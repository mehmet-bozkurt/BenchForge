import assert from "node:assert/strict";
import { spawn } from "node:child_process";
import { once } from "node:events";
import { chmod, mkdtemp, mkdir, readFile, realpath, rm, stat, symlink, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import test from "node:test";

const workerPath = join(dirname(fileURLToPath(import.meta.url)), "../../bin/preparation-worker.mjs");
const fakeCliSource = `#!/usr/bin/env node
import { spawn } from 'node:child_process';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
const prompt = await new Promise((resolve) => {
  let input = '';
  process.stdin.setEncoding('utf8');
  process.stdin.on('data', (chunk) => { input += chunk; });
  process.stdin.on('end', () => resolve(input));
});
const behavior = JSON.parse(readFileSync('behavior.json', 'utf8'));
writeFileSync('observed.json', JSON.stringify({
  args: process.argv.slice(2),
  prompt,
  cwd: process.cwd(),
  priorResultPresent: existsSync(join(dirname(process.cwd()), 'result-1.txt')),
  home: process.env.HOME,
  configHome: process.env.XDG_CONFIG_HOME,
  dataHome: process.env.XDG_DATA_HOME,
  authPresent: existsSync(join(process.env.XDG_DATA_HOME, 'opencode', 'auth.json')),
  configDirectoryOverride: process.env.OPENCODE_CONFIG_DIR,
  permissionOverride: process.env.OPENCODE_PERMISSION,
  configPath: process.env.OPENCODE_CONFIG,
  config: process.env.OPENCODE_CONFIG_CONTENT,
  flags: Object.fromEntries(Object.entries(process.env).filter(([key]) => key.startsWith('OPENCODE_DISABLE_') || key === 'OPENCODE_PURE')),
  environmentKeys: Object.keys(process.env),
}));
const emit = (value) => process.stdout.write(JSON.stringify(value) + '\\n');
if (behavior.mode === 'success') {
  emit({ type: 'text', messageID: 'm1', part: { id: 'p1', text: '{"summary":"draft"}' } });
  emit({ type: 'text', messageID: 'm1', part: { id: 'p1', text: '{"summary":"ready"}' } });
  emit({ type: 'step_finish', part: { cost: 0.012, tokens: { input: 10, output: 3 } } });
  emit({ type: 'step_finish', part: { cost: 0.005, tokens: { input: 4, output: 2 } } });
} else if (behavior.mode === 'failure') {
  process.stderr.write('PRIVATE_TOKEN_DO_NOT_EXPOSE\\n');
  emit({ type: 'error', error: { message: 'PRIVATE_TOKEN_DO_NOT_EXPOSE', code: 429 } });
  process.exitCode = 7;
} else if (behavior.mode === 'tool') {
  emit({ type: 'tool_use', part: { type: 'tool', tool: 'bash', state: { status: 'running' } } });
  setInterval(() => {}, 1000);
} else if (behavior.mode === 'large-text') {
  emit({ type: 'text', messageID: 'm1', part: { id: 'p1', text: 'x'.repeat(1024 * 1024) } });
} else if (behavior.mode === 'large-events') {
  emit({ type: 'unrecognized', data: 'x'.repeat(2 * 1024 * 1024) });
} else if (behavior.mode === 'hang') {
  const grandchild = spawn(process.execPath, ['-e', 'process.on("SIGTERM", () => {}); setInterval(() => {}, 1000)'], { stdio: 'ignore' });
  writeFileSync('grandchild.pid', String(grandchild.pid));
  setInterval(() => {}, 1000);
}
`;

function restrictiveConfig() {
  return {
    $schema: "https://opencode.ai/config.json",
    autoupdate: false,
    share: "disabled",
    plugin: [],
    instructions: [],
    mcp: {},
    agent: {
      "benchforge-preparation": {
        description: "Preparation fixture",
        mode: "primary",
        tools: { "*": false },
        permission: { "*": "deny" },
      },
    },
    permission: { "*": "deny" },
  };
}

async function fixture(modes = ["success"], overrides = {}) {
  const root = await mkdtemp(join(tmpdir(), "benchforge-preparation-worker-"));
  const cliPath = join(root, "fake-opencode.mjs");
  const jobPath = join(root, "job.json");
  const promptPath = join(root, "prompt.txt");
  const statePath = join(root, "state.json");
  await writeFile(cliPath, fakeCliSource, { mode: 0o700 });
  await chmod(cliPath, 0o700);
  await writeFile(promptPath, "Create a preparation JSON.\n", { mode: 0o600 });

  const models = [];
  for (let index = 0; index < modes.length; index += 1) {
    const directory = join(root, `model-${index + 1}`);
    const configPath = join(root, `config-${index + 1}.json`);
    await mkdir(directory);
    await writeFile(join(directory, "behavior.json"), JSON.stringify({ mode: modes[index] }));
    await writeFile(configPath, JSON.stringify(restrictiveConfig()), { mode: 0o600 });
    models.push({
      packageId: index + 11,
      modelId: `catalog-${index + 1}`,
      modelName: `Fixture ${index + 1}`,
      model: `openrouter/fixture/model-${index + 1}`,
      directory,
      configPath,
      resultPath: join(root, `result-${index + 1}.txt`),
    });
  }

  const job = {
    version: 1,
    comparisonId: 47,
    statePath,
    promptPath,
    opencodeBinary: cliPath,
    timeoutSeconds: 10,
    models,
    startedAt: "2026-10-03T00:00:00.000Z",
    ...overrides,
  };
  await writeFile(jobPath, JSON.stringify(job), { mode: 0o600 });
  return { root, jobPath, statePath, job };
}

function launch(jobPath, extraEnv = {}) {
  return spawn(process.execPath, [workerPath, jobPath], {
    env: { ...process.env, ...extraEnv, BENCHFORGE_RUNNER: "1" },
    stdio: ["ignore", "ignore", "pipe"],
  });
}

async function waitForExit(child) {
  const timer = setTimeout(() => child.kill("SIGKILL"), 12000);
  try {
    const [code, signal] = await once(child, "exit");
    return { code, signal };
  } finally {
    clearTimeout(timer);
  }
}

async function waitFor(check, maxMs = 4000) {
  const until = Date.now() + maxMs;
  while (Date.now() < until) {
    try {
      const value = await check();
      if (value) return value;
    } catch (error) {
      if (error.code !== "ENOENT") throw error;
    }
    await new Promise((resolve) => setTimeout(resolve, 20));
  }
  throw new Error("Timed out waiting for fixture state");
}

function isAlive(pid) {
  try {
    process.kill(pid, 0);
    return true;
  } catch (error) {
    if (error.code === "ESRCH") return false;
    throw error;
  }
}

test("records sequential text results, usage and restrictive OpenCode invocation", async () => {
  const { root, jobPath, statePath, job } = await fixture(["success", "success"]);
  try {
    const child = launch(jobPath);
    const exit = await waitForExit(child);
    const state = JSON.parse(await readFile(statePath, "utf8"));
    const observed = JSON.parse(await readFile(join(job.models[0].directory, "observed.json"), "utf8"));

    assert.deepEqual(exit, { code: 0, signal: null });
    assert.equal(state.status, "completed");
    assert.equal(state.comparisonId, 47);
    assert.equal(state.runnerPid, child.pid);
    assert.deepEqual(state.models.map((model) => model.status), ["completed", "completed"]);
    assert.equal(state.models[0].costUsd, 0.017);
    assert.equal(state.models[0].inputTokens, 14);
    assert.equal(state.models[0].outputTokens, 5);
    assert.equal(await readFile(job.models[0].resultPath, "utf8"), '{"summary":"ready"}\n');
    assert.equal(await readFile(job.models[1].resultPath, "utf8"), '{"summary":"ready"}\n');
    assert.deepEqual(observed.args, [
      "run", "--pure", "--format", "json", "--agent", "benchforge-preparation",
      "--model", job.models[0].model, "--dir", job.models[0].directory,
    ]);
    assert.equal(observed.prompt, "Create a preparation JSON.\n");
    assert.equal(observed.cwd, await realpath(job.models[0].directory));
    const secondObserved = JSON.parse(await readFile(join(job.models[1].directory, "observed.json"), "utf8"));
    assert.equal(secondObserved.priorResultPresent, true);
    assert.equal(observed.configPath, job.models[0].configPath);
    assert.equal(observed.config, await readFile(job.models[0].configPath, "utf8"));
    for (const flag of [
      "OPENCODE_DISABLE_PROJECT_CONFIG", "OPENCODE_DISABLE_EXTERNAL_SKILLS",
      "OPENCODE_DISABLE_CLAUDE_CODE_SKILLS", "OPENCODE_DISABLE_DEFAULT_PLUGINS",
      "OPENCODE_DISABLE_CLAUDE_CODE_PROMPT", "OPENCODE_DISABLE_CLAUDE_CODE",
      "OPENCODE_DISABLE_MODELS_FETCH", "OPENCODE_DISABLE_SHARE",
    ]) {
      assert.equal(observed.flags[flag], "1");
    }
    assert.equal(observed.flags.OPENCODE_PURE, "1");
    assert.equal((await readFile(job.models[0].resultPath, "utf8")).includes("draft"), false);
    assert.equal((await readFile(jobPath, "utf8")).includes('"comparisonId":47'), true);
    assert.equal((await stat(statePath)).mode & 0o777, 0o600);
    assert.equal((await stat(job.models[0].resultPath)).mode & 0o777, 0o600);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("isolates global instructions while preserving the configured authentication data location", async () => {
  const { root, jobPath, statePath, job } = await fixture(["success"]);
  const fakeHostHome = await mkdtemp(join(tmpdir(), "benchforge-fake-host-home-"));
  try {
    const authDirectory = join(fakeHostHome, ".local", "share", "opencode");
    await mkdir(authDirectory, { recursive: true });
    await writeFile(join(authDirectory, "auth.json"), '{"fixture":true}\n', { mode: 0o600 });
    const child = launch(jobPath, {
      HOME: fakeHostHome,
      XDG_DATA_HOME: "",
      XDG_CONFIG_HOME: join(fakeHostHome, "unsafe-config"),
      OPENCODE_CONFIG_DIR: join(fakeHostHome, "unsafe-opencode"),
      OPENCODE_PERMISSION: '{"*":"allow"}',
      APP_KEY: 'fixture-app-secret',
      OPENROUTER_API_KEY: 'fixture-provider-secret',
      AWS_SECRET_ACCESS_KEY: 'fixture-aws-secret',
      NODE_OPTIONS: '--no-warnings',
      BENCHFORGE_UNKNOWN: 'fixture-unknown',
    });
    const exit = await waitForExit(child);
    const state = JSON.parse(await readFile(statePath, "utf8"));
    const observed = JSON.parse(await readFile(join(job.models[0].directory, "observed.json"), "utf8"));

    assert.deepEqual(exit, { code: 0, signal: null });
    assert.equal(state.status, "completed");
    assert.equal(observed.home, join(job.models[0].directory, ".benchforge-home"));
    assert.equal(observed.configHome, join(observed.home, ".config"));
    assert.equal(observed.dataHome, join(fakeHostHome, ".local", "share"));
    assert.equal(observed.authPresent, true);
    assert.equal(observed.configDirectoryOverride, "");
    assert.equal(observed.permissionOverride, "");
    for (const key of ['APP_KEY', 'OPENROUTER_API_KEY', 'AWS_SECRET_ACCESS_KEY', 'NODE_OPTIONS', 'BENCHFORGE_UNKNOWN', 'BENCHFORGE_RUNNER']) {
      assert.ok(!observed.environmentKeys.includes(key));
    }
  } finally {
    await rm(root, { recursive: true, force: true });
    await rm(fakeHostHome, { recursive: true, force: true });
  }
});

test("continues after a model error without disclosing stderr or treating missing usage as zero", async () => {
  const { root, jobPath, statePath, job } = await fixture(["failure", "success"]);
  try {
    const child = launch(jobPath);
    const exit = await waitForExit(child);
    const state = JSON.parse(await readFile(statePath, "utf8"));

    assert.deepEqual(exit, { code: 0, signal: null });
    assert.equal(state.status, "partial");
    assert.deepEqual(state.models.map((model) => model.status), ["failed", "completed"]);
    assert.equal(state.models[0].errorMessage, "İstek sınırına ulaşıldı.");
    assert.equal(state.models[0].costUsd, null);
    assert.equal(state.models[0].inputTokens, null);
    assert.equal(state.models[0].outputTokens, null);
    assert.equal(JSON.stringify(state).includes("PRIVATE_TOKEN_DO_NOT_EXPOSE"), false);
    await assert.rejects(readFile(job.models[0].resultPath, "utf8"), { code: "ENOENT" });
    assert.equal(await readFile(job.models[1].resultPath, "utf8"), '{"summary":"ready"}\n');
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("fails an empty model response and continues with the next model", async () => {
  const { root, jobPath, statePath } = await fixture(["empty", "success"]);
  try {
    const child = launch(jobPath);
    await waitForExit(child);
    const state = JSON.parse(await readFile(statePath, "utf8"));

    assert.equal(state.status, "partial");
    assert.deepEqual(state.models.map((model) => model.status), ["failed", "completed"]);
    assert.equal(state.models[0].errorMessage, "Model yanıtı alınamadı.");
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("terminates a CLI that attempts any tool use", async () => {
  const { root, jobPath, statePath, job } = await fixture(["tool"]);
  try {
    const child = launch(jobPath);
    const exit = await waitForExit(child);
    const state = JSON.parse(await readFile(statePath, "utf8"));

    assert.deepEqual(exit, { code: 0, signal: null });
    assert.equal(state.status, "failed");
    assert.equal(state.models[0].errorMessage, "Model araç veya izin kullanımı denedi.");
    await assert.rejects(readFile(job.models[0].resultPath, "utf8"), { code: "ENOENT" });
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

for (const [mode, expectedError] of [
  ["large-text", "Model yanıtı izin verilen boyutu aştı."],
  ["large-events", "Model olay çıktısı izin verilen boyutu aştı."],
]) {
  test(`rejects ${mode} output before writing a result`, async () => {
    const { root, jobPath, statePath, job } = await fixture([mode]);
    try {
      const child = launch(jobPath);
      const exit = await waitForExit(child);
      const state = JSON.parse(await readFile(statePath, "utf8"));

      assert.deepEqual(exit, { code: 0, signal: null });
      assert.equal(state.status, "failed");
      assert.equal(state.models[0].errorMessage, expectedError);
      await assert.rejects(readFile(job.models[0].resultPath, "utf8"), { code: "ENOENT" });
    } finally {
      await rm(root, { recursive: true, force: true });
    }
  });
}

test("times out a model and terminates its child process group", async () => {
  const { root, jobPath, statePath, job } = await fixture(["hang"], { timeoutSeconds: 1 });
  try {
    const child = launch(jobPath);
    await waitFor(job.models[0].directory
      ? () => readFile(join(job.models[0].directory, "grandchild.pid"), "utf8")
      : () => false);
    const grandchildPid = Number(await readFile(join(job.models[0].directory, "grandchild.pid"), "utf8"));
    const exit = await waitForExit(child);
    const state = JSON.parse(await readFile(statePath, "utf8"));

    assert.deepEqual(exit, { code: 0, signal: null });
    assert.equal(state.status, "failed");
    assert.equal(state.models[0].status, "failed");
    await waitFor(() => !isAlive(grandchildPid), 3000);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("stops the active and queued models while preserving completed results and unrelated processes", async () => {
  const { root, jobPath, statePath, job } = await fixture(["success", "hang", "success"]);
  const unrelated = spawn(process.execPath, ["-e", "setInterval(() => {}, 1000)"], { stdio: "ignore" });
  try {
    const child = launch(jobPath);
    await waitFor(async () => {
      const state = JSON.parse(await readFile(statePath, "utf8"));
      return state.models[0].status === "completed" && state.models[1].status === "running";
    });
    child.kill("SIGTERM");
    const exit = await waitForExit(child);
    const state = JSON.parse(await readFile(statePath, "utf8"));

    assert.deepEqual(exit, { code: 0, signal: null });
    assert.equal(state.status, "stopped");
    assert.deepEqual(state.models.map((model) => model.status), ["completed", "stopped", "stopped"]);
    assert.equal(await readFile(job.models[0].resultPath, "utf8"), '{"summary":"ready"}\n');
    await assert.rejects(readFile(job.models[2].resultPath, "utf8"), { code: "ENOENT" });
    assert.equal(isAlive(unrelated.pid), true);
  } finally {
    unrelated.kill("SIGTERM");
    await once(unrelated, "exit");
    await rm(root, { recursive: true, force: true });
  }
});

for (const kind of ["outside", "symlink"]) {
  test(`rejects a ${kind} result path before starting any model`, async () => {
    const { root, jobPath, statePath, job } = await fixture(["success"]);
    const outside = await mkdtemp(join(tmpdir(), "benchforge-preparation-outside-"));
    const outsideResult = join(outside, "protected.txt");
    try {
      await writeFile(outsideResult, "keep me\n");
      if (kind === "outside") {
        job.models[0].resultPath = outsideResult;
      } else {
        const linkedResult = join(root, "linked-result.txt");
        await symlink(outsideResult, linkedResult);
        job.models[0].resultPath = linkedResult;
      }
      await writeFile(jobPath, JSON.stringify(job), { mode: 0o600 });

      const child = launch(jobPath);
      const exit = await waitForExit(child);

      assert.equal(exit.code, 1);
      assert.equal(await readFile(outsideResult, "utf8"), "keep me\n");
      await assert.rejects(readFile(statePath, "utf8"), { code: "ENOENT" });
      await assert.rejects(readFile(join(job.models[0].directory, "observed.json"), "utf8"), { code: "ENOENT" });
    } finally {
      await rm(root, { recursive: true, force: true });
      await rm(outside, { recursive: true, force: true });
    }
  });
}

test("rejects a configuration that enables tools before starting the CLI", async () => {
  const { root, jobPath, statePath, job } = await fixture(["success"]);
  try {
    const config = restrictiveConfig();
    config.agent["benchforge-preparation"].tools = { "*": true };
    await writeFile(job.models[0].configPath, JSON.stringify(config));

    const child = launch(jobPath);
    const exit = await waitForExit(child);

    assert.equal(exit.code, 1);
    await assert.rejects(readFile(statePath, "utf8"), { code: "ENOENT" });
    await assert.rejects(readFile(join(job.models[0].directory, "observed.json"), "utf8"), { code: "ENOENT" });
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});
