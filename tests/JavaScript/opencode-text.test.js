import assert from "node:assert/strict";
import { chmod, mkdtemp, mkdir, readFile, realpath, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";
import test from "node:test";

const modulePath = join(dirname(fileURLToPath(import.meta.url)), "../../bin/opencode-text.mjs");
const {
  LIMIT_MESSAGE, OUTPUT_LIMIT_MESSAGE, STARTUP_RETRY_DELAYS_MS, assertRestrictedConfig, defaultDescribeError, lookupModelLimit, modelLimitFrom, runOpenCodeText, textModelEnvironment, withOutputCap,
} = await import(pathToFileURL(modulePath));

const fakeCliSource = `#!/usr/bin/env node
import { spawn } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
if (process.argv[2] === 'api') {
  const apiBehavior = JSON.parse(readFileSync('behavior.json', 'utf8'));
  writeFileSync('api-observed.json', JSON.stringify({ args: process.argv.slice(2), cwd: process.cwd(), home: process.env.HOME, configPath: process.env.OPENCODE_CONFIG, hasContent: typeof process.env.OPENCODE_CONFIG_CONTENT === 'string' }));
  let started = 0;
  try { started = Number(readFileSync('api-count', 'utf8')); } catch {}
  writeFileSync('api-count', String(started + 1));
  if (apiBehavior.apiHang) {
    if (apiBehavior.apiGrandchild) {
      const grandchild = spawn(process.execPath, ['-e', 'process.on("SIGTERM", () => {}); setInterval(() => {}, 1000)'], { stdio: 'ignore' });
      writeFileSync('api-grandchild.pid', String(grandchild.pid));
    }
    setInterval(() => {}, 1000);
    await new Promise(() => {});
  } else if (started < (apiBehavior.apiFailFirst ?? 0)) {
    process.exit(1);
  } else {
    process.stdout.write(apiBehavior.apiOutput ?? '');
    process.exit(apiBehavior.apiExitCode ?? 0);
  }
}
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
  home: process.env.HOME,
  configPath: process.env.OPENCODE_CONFIG,
  extra: process.env.BENCHFORGE_FIXTURE_EXTRA,
  environmentKeys: Object.keys(process.env),
}));
const emit = (value) => process.stdout.write(JSON.stringify(value) + '\\n');
await new Promise((resolve) => setTimeout(resolve, behavior.delayMs ?? 0));
let runs = 0;
try { runs = Number(readFileSync('run-count', 'utf8')); } catch {}
writeFileSync('run-count', String(runs + 1));
if (runs < (behavior.startupFailFirst ?? 0)) {
  emit({ type: 'error', sessionID: '', error: { type: 'unknown', message: 'Standalone server exited before reporting readiness' } });
  process.exit(1);
}
if (behavior.mode === 'success') {
  emit({ type: 'step_start', sessionID: 'not a session id', part: { type: 'step-start' } });
  emit({ type: 'text', sessionID: 'ses_fixture_A1', part: { id: 'p1', text: 'draft' } });
  emit({ type: 'text', sessionID: 'ses_fixture_B2', part: { id: 'p1', text: 'ready text' } });
  emit({ type: 'step_finish', sessionID: 'ses_fixture_A1', part: { cost: 0.002, tokens: { input: 7, output: 2 } } });
} else if (behavior.mode === 'error') {
  emit({ type: 'error', sessionID: 'ses_fixture_error', error: { name: 'APIError', data: { statusCode: 429, isRetryable: true, message: 'Rate limited' } } });
  emit({ type: 'error', sessionID: 'ses_fixture_error', error: { name: 'UnknownError', data: { message: 'second error' } } });
  process.exitCode = 1;
} else if (behavior.mode === 'exit-code') {
  emit({ type: 'text', part: { id: 'p1', text: 'partial answer' } });
  process.exitCode = 3;
} else if (behavior.mode === 'tool') {
  emit({ type: 'tool_use', sessionID: 'ses_fixture_tool', part: { type: 'tool', tool: 'bash', state: { status: 'running' } } });
  setInterval(() => {}, 1000);
} else if (behavior.mode === 'events') {
  for (const event of behavior.events) emit(event);
  if (behavior.exitCode) process.exitCode = behavior.exitCode;
} else if (behavior.mode === 'hang') {
  const grandchild = spawn(process.execPath, ['-e', 'process.on("SIGTERM", () => {}); setInterval(() => {}, 1000)'], { stdio: 'ignore' });
  writeFileSync('grandchild.pid', String(grandchild.pid));
  setInterval(() => {}, 1000);
}
`;

function restrictiveConfig(agentName = "benchforge-workflow") {
  return {
    $schema: "https://opencode.ai/config.json",
    autoupdate: false,
    share: "disabled",
    plugin: [],
    instructions: [],
    mcp: {},
    agent: {
      [agentName]: {
        description: "Workflow fixture",
        mode: "primary",
        tools: { "*": false },
        permission: { "*": "deny" },
      },
    },
    permission: { "*": "deny" },
  };
}

async function fixture(behavior) {
  const root = await mkdtemp(join(tmpdir(), "benchforge-opencode-text-"));
  const cliPath = join(root, "fake-opencode.mjs");
  const directory = join(root, "workspace");
  const privateHome = join(root, ".benchforge-home");
  const configPath = join(root, "opencode.json");
  const configContent = JSON.stringify(restrictiveConfig());
  await writeFile(cliPath, fakeCliSource, { mode: 0o700 });
  await chmod(cliPath, 0o700);
  await mkdir(directory);
  await mkdir(privateHome);
  await writeFile(join(directory, "behavior.json"), JSON.stringify(behavior));
  await writeFile(configPath, configContent, { mode: 0o600 });

  return {
    root,
    directory,
    privateHome,
    options: {
      opencodeBinary: cliPath,
      agent: "benchforge-workflow",
      model: "openrouter/fixture/model-1",
      directory,
      privateHome,
      configPath,
      configContent,
      prompt: "Write one sentence.\n",
      timeoutSeconds: 10,
    },
  };
}

async function observed(directory) {
  return JSON.parse(await readFile(join(directory, "observed.json"), "utf8"));
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

test("passes the title after the fixed arguments and extra environment to the CLI", async () => {
  const { root, directory, privateHome, options } = await fixture({ mode: "success" });
  const title = "benchforge:workflow:game-idea-factory-20261022-183012:or-fixture:ideas:001:1";
  const texts = [];
  try {
    const result = await runOpenCodeText({
      ...options,
      title,
      extraEnv: { BENCHFORGE_FIXTURE_EXTRA: 32000 },
      onText: (text) => texts.push(text),
    });
    const seen = await observed(directory);

    assert.equal(result.status, "completed");
    assert.equal(result.text, "ready text");
    assert.equal(result.errorMessage, null);
    assert.deepEqual(seen.args, [
      "run", "--standalone", "--format", "json", "--agent", "benchforge-workflow",
      "--model", "openrouter/fixture/model-1", "--title", title,
    ]);
    assert.equal(seen.prompt, "Write one sentence.\n");
    assert.equal(seen.cwd, await realpath(directory));
    assert.equal(seen.home, privateHome);
    assert.equal(seen.configPath, options.configPath);
    assert.equal(seen.extra, "32000");
    assert.deepEqual(texts, ["draft", "ready text"]);
    assert.equal(result.costUsd, 0.002);
    assert.equal(result.inputTokens, 7);
    assert.equal(result.outputTokens, 2);
    assert.equal(result.reasoningTokens, 0);
    assert.equal(result.cacheReadTokens, 0);
    assert.equal(result.cacheWriteTokens, 0);
    assert.equal(result.stopReason, null);
    assert.equal(result.abortReason, null);
    assert.equal(result.exitCode, 0);
    assert.equal(result.signal, null);
    assert.equal(result.errorEvent, null);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("adds no title or extra environment unless the caller asks for them", async () => {
  const { root, directory, options } = await fixture({ mode: "success" });
  try {
    const result = await runOpenCodeText(options);
    const seen = await observed(directory);

    assert.equal(result.status, "completed");
    assert.deepEqual(seen.args, [
      "run", "--standalone", "--format", "json", "--agent", "benchforge-workflow",
      "--model", "openrouter/fixture/model-1",
    ]);
    assert.ok(!seen.environmentKeys.includes("BENCHFORGE_FIXTURE_EXTRA"));
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("captures the first valid session id and measures elapsed time with a monotonic clock", async () => {
  const { root, options } = await fixture({ mode: "success", delayMs: 300 });
  try {
    const result = await runOpenCodeText(options);

    assert.equal(result.status, "completed");
    assert.equal(result.sessionId, "ses_fixture_A1");
    assert.equal(typeof result.elapsedMs, "number");
    assert.ok(result.elapsedMs >= 300, `elapsed ${result.elapsedMs} ms`);
    assert.ok(result.elapsedMs < 10000, `elapsed ${result.elapsedMs} ms`);
    assert.ok(result.durationSeconds >= 1);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("returns the first error event and describes it with the caller's function", async () => {
  const { root, options } = await fixture({ mode: "error" });
  try {
    const result = await runOpenCodeText({
      ...options,
      describeError: (event) => `described ${event.error.data.statusCode}`,
    });

    assert.equal(result.status, "failed");
    assert.equal(result.abortReason, "cli-error");
    assert.equal(result.errorMessage, "described 429");
    assert.equal(result.sessionId, "ses_fixture_error");
    assert.deepEqual(result.errorEvent, {
      type: "error",
      sessionID: "ses_fixture_error",
      error: { name: "APIError", data: { statusCode: 429, isRetryable: true, message: "Rate limited" } },
    });

    const described = await runOpenCodeText(options);
    assert.equal(described.errorMessage, "Sağlayıcı geçici bir hata verdi (istek sınırı veya sunucu hatası).");
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("reports a failing CLI exit code without a session id", async () => {
  const { root, options } = await fixture({ mode: "exit-code" });
  try {
    const result = await runOpenCodeText(options);

    assert.equal(result.status, "failed");
    assert.equal(result.text, "partial answer");
    assert.equal(result.exitCode, 3);
    assert.equal(result.signal, null);
    assert.equal(result.abortReason, null);
    assert.equal(result.sessionId, null);
    assert.equal(result.errorMessage, "Model isteği başarısız oldu (CLI kodu 3).");
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("aborts a call whose model tries to use a tool", async () => {
  const { root, options } = await fixture({ mode: "tool" });
  try {
    const result = await runOpenCodeText({ ...options, stopGraceMs: 300 });

    assert.equal(result.status, "failed");
    assert.equal(result.abortReason, "forbidden-activity");
    assert.equal(result.errorMessage, "Model araç veya izin kullanımı denedi.");
    assert.equal(result.sessionId, "ses_fixture_tool");
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("times out a call and kills its whole process group", async () => {
  const { root, directory, options } = await fixture({ mode: "hang" });
  try {
    const result = await runOpenCodeText({ ...options, timeoutSeconds: 1, stopGraceMs: 300 });
    const grandchildPid = Number(await readFile(join(directory, "grandchild.pid"), "utf8"));

    assert.equal(result.status, "failed");
    assert.equal(result.abortReason, "timeout");
    assert.equal(result.errorMessage, "Model yanıtı alınamadı.");
    assert.ok(result.elapsedMs >= 1000, `elapsed ${result.elapsedMs} ms`);
    await waitFor(() => !isAlive(grandchildPid), 3000);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("stops through the handle, kills the process group and releases the handle", async () => {
  const { root, directory, options } = await fixture({ mode: "hang" });
  const handles = [];
  try {
    const running = runOpenCodeText({ ...options, stopGraceMs: 300, onHandle: (handle) => handles.push(handle) });
    const grandchildPid = Number(await waitFor(() => readFile(join(directory, "grandchild.pid"), "utf8")));
    handles[0].abort("stopped");
    const result = await running;

    assert.equal(result.status, "stopped");
    assert.equal(result.abortReason, "stopped");
    assert.equal(result.errorMessage, "Kullanıcı tarafından durduruldu.");
    assert.equal(handles.length, 2);
    assert.equal(typeof handles[0].abort, "function");
    assert.equal(handles[1], null);
    await waitFor(() => !isAlive(grandchildPid), 3000);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("stops at once when a stop was requested before the call started", async () => {
  const { root, options } = await fixture({ mode: "hang", delayMs: 5000 });
  try {
    const result = await runOpenCodeText({ ...options, stopGraceMs: 300, isStopRequested: () => true });

    assert.equal(result.status, "stopped");
    assert.equal(result.abortReason, "stopped");
    assert.ok(result.elapsedMs < 5000, `elapsed ${result.elapsedMs} ms`);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

const step = (type, part = {}) => ({ type, sessionID: "ses_fixture_cut", part });
const cutOffStep = (text, reason) => [step("step_start"), step("text", { id: `p-${text}`, text }), step("step_finish", { cost: 0.001, tokens: { input: 10, output: 5 }, reason })];

test("reports a response that OpenCode 2.x continued after a cut-off as cut off", async () => {
  const events = [...cutOffStep("The report starts and is cut", "length"), ...cutOffStep("off here and goes on.", "stop")];
  const { root, options } = await fixture({ mode: "events", events });
  try {
    const result = await runOpenCodeText(options);

    assert.equal(result.status, "completed");
    assert.equal(result.stopReason, "length");
    assert.equal(result.text, "The report starts and is cut\noff here and goes on.");
    assert.equal(result.outputTokens, 10);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("keeps an ordinary stop as it is", async () => {
  const { root, options } = await fixture({ mode: "events", events: cutOffStep("A complete answer.", "stop") });
  try {
    assert.equal((await runOpenCodeText(options)).stopReason, "stop");
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("reports OpenCode 2.x's output-limit error as a cut-off answer, not as a provider error or an abort", async () => {
  // The order OpenCode 2.2.5 wrote: the error comes before the events of the third cut-off step.
  const events = [
    ...cutOffStep("cut one", "length"),
    ...cutOffStep("cut two", "length"),
    { type: "error", sessionID: "ses_fixture_cut", error: { type: "output-limit", message: "Response still truncated after two output token limit continuations" } },
    ...cutOffStep("cut three", "length"),
  ];
  const { root, options } = await fixture({ mode: "events", events, exitCode: 1 });
  try {
    const result = await runOpenCodeText(options);

    assert.equal(result.status, "failed");
    assert.equal(result.stopReason, "length");
    assert.equal(result.abortReason, null);
    assert.equal(result.errorEvent, null);
    assert.equal(result.errorMessage, OUTPUT_LIMIT_MESSAGE);
    assert.equal(result.exitCode, 1);
    assert.equal(result.outputTokens, 15);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("still aborts on another error event", async () => {
  const events = [{ type: "error", sessionID: "ses_fixture_cut", error: { type: "provider.auth", message: "User not found.", status: 401 } }];
  const { root, options } = await fixture({ mode: "events", events, exitCode: 1 });
  try {
    const result = await runOpenCodeText(options);

    assert.equal(result.status, "failed");
    assert.equal(result.abortReason, "cli-error");
    assert.notEqual(result.stopReason, "length");
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

const modelList = (...models) => JSON.stringify({ location: { directory: "/workspace" }, data: models });
const listedModel = (providerID, modelID, limit) => ({ id: modelID, modelID, providerID, type: "chat", limit });

test("reads a model's real token limits from the OpenCode model list", () => {
  const list = modelList(
    listedModel("openrouter", "google/gemma-4-26b-a4b-it", { context: 262144, output: 32768 }),
    listedModel("opencode-go", "hy4", { context: 1048576, input: 983010, output: 65536 }),
    listedModel("openrouter", "no/output", { context: 1000 }),
  );

  assert.deepEqual(modelLimitFrom(list, "openrouter/google/gemma-4-26b-a4b-it"), { context: 262144, output: 32768 });
  assert.deepEqual(modelLimitFrom(list, "opencode-go/hy4"), { context: 1048576, output: 65536, input: 983010 });
  assert.equal(modelLimitFrom(list, "openrouter/no/output"), null);
  assert.equal(modelLimitFrom(modelList(listedModel("openrouter", "no/context", { output: 1000 })), "openrouter/no/context"), null);
  assert.equal(modelLimitFrom(modelList(listedModel("openrouter", "zero/limit", { context: 0, output: 0 })), "openrouter/zero/limit"), null);
  assert.equal(modelLimitFrom(modelList(listedModel("openrouter", "no/limit", undefined)), "openrouter/no/limit"), null);
  assert.equal(modelLimitFrom(list, "openrouter/unlisted"), null);
  assert.equal(modelLimitFrom(list, "opencode-go/google/gemma-4-26b-a4b-it"), null);
  assert.equal(modelLimitFrom(list, "no-slash"), null);
  assert.equal(modelLimitFrom("not json", "openrouter/x"), null);
  assert.equal(modelLimitFrom(JSON.stringify({ data: "no" }), "openrouter/x"), null);
});

test("limits the answer to a cap below the model's own limit, keeping routing and the model's other limits", () => {
  const config = JSON.stringify({
    ...restrictiveConfig(),
    provider: { openrouter: { models: { "google/gemma-4-26b-a4b-it": { options: { provider: { order: ["x/fp8"], allow_fallbacks: false } } } } } },
  });
  const limit = { context: 262144, output: 32768, input: 200000 };
  const capped = JSON.parse(withOutputCap(config, "openrouter/google/gemma-4-26b-a4b-it", limit, 8000));

  assert.deepEqual(capped.provider.openrouter.models["google/gemma-4-26b-a4b-it"], {
    options: { provider: { order: ["x/fp8"], allow_fallbacks: false } },
    limit: { context: 262144, output: 8000, input: 200000 },
  });
  assert.deepEqual(capped.agent, JSON.parse(config).agent);

  const added = JSON.parse(withOutputCap(JSON.stringify(restrictiveConfig()), "opencode-go/hy4", { context: 1048576, output: 65536 }, 4000));
  assert.deepEqual(added.provider["opencode-go"].models.hy4, { limit: { context: 1048576, output: 4000 } });
});

test("leaves the configuration alone when the cap is not below the model's limit or the limit is unknown", () => {
  const config = JSON.stringify(restrictiveConfig());
  const limit = { context: 262144, output: 32768 };

  assert.equal(withOutputCap(config, "openrouter/x/y", limit, 32768), config);
  assert.equal(withOutputCap(config, "openrouter/x/y", limit, 100000), config);
  assert.equal(withOutputCap(config, "openrouter/x/y", null, 4000), config);
  assert.equal(withOutputCap(config, "openrouter/x/y", limit, 0), config);
  assert.equal(withOutputCap(config, "no-slash", limit, 4000), config);
});

test("looks a model's limit up with the call environment, and gives null when OpenCode cannot answer", async () => {
  const good = await fixture({ mode: "success", apiOutput: modelList(listedModel("openrouter", "fixture/model-1", { context: 128000, output: 16000 })) });
  const failing = await fixture({ mode: "success", apiOutput: "{}", apiExitCode: 1 });
  const garbage = await fixture({ mode: "success", apiOutput: "<html>" });
  const hanging = await fixture({ mode: "success", apiHang: true });
  try {
    assert.deepEqual(await lookupModelLimit(good.options), { context: 128000, output: 16000 });
    const seen = JSON.parse(await readFile(join(good.directory, "api-observed.json"), "utf8"));
    assert.deepEqual(seen.args, ["api", "--standalone", "GET", "/api/model"]);
    assert.equal(seen.cwd, await realpath(good.directory));
    assert.equal(seen.home, good.privateHome);
    assert.equal(seen.configPath, good.options.configPath);
    assert.equal(seen.hasContent, true);

    assert.equal(await lookupModelLimit(failing.options), null);
    assert.equal(await lookupModelLimit(garbage.options), null);
    assert.equal(await lookupModelLimit({ ...good.options, model: "unlisted/model" }), null);
    assert.equal(await lookupModelLimit({ ...good.options, model: "no-slash" }), null);
    assert.equal(await lookupModelLimit({ ...good.options, privateHome: "relative/home" }), null);
    assert.equal(await lookupModelLimit({ ...good.options, opencodeBinary: join(good.root, "missing") }), null);

    const started = Date.now();
    assert.equal(await lookupModelLimit({ ...hanging.options, timeoutSeconds: 0.3, stopGraceMs: 200 }), null);
    assert.ok(Date.now() - started < 4000);
  } finally {
    await Promise.all([good, failing, garbage, hanging].map(({ root }) => rm(root, { recursive: true, force: true })));
  }
});

async function apiCount(directory) {
  return Number(await readFile(join(directory, "api-count"), "utf8"));
}

test("tries a lookup again only when the OpenCode process itself failed, and gives up after the last wait", async () => {
  const flaky = await fixture({ mode: "success", apiFailFirst: 1, apiOutput: modelList(listedModel("openrouter", "fixture/model-1", { context: 128000, output: 16000 })) });
  const broken = await fixture({ mode: "success", apiFailFirst: 9, apiOutput: "{}" });
  const hanging = await fixture({ mode: "success", apiHang: true });
  const unlisted = await fixture({ mode: "success", apiOutput: modelList() });
  try {
    assert.deepEqual(await lookupModelLimit({ ...flaky.options, retryDelaysMs: [10, 10] }), { context: 128000, output: 16000 });
    assert.equal(await apiCount(flaky.directory), 2);

    assert.equal(await lookupModelLimit({ ...broken.options, retryDelaysMs: [10, 10] }), null);
    assert.equal(await apiCount(broken.directory), 3);

    // The timeout must outlast the start-up of the fake CLI on a loaded machine.
    assert.equal(await lookupModelLimit({ ...hanging.options, timeoutSeconds: 2, stopGraceMs: 100, retryDelaysMs: [10, 10] }), null);
    assert.equal(await apiCount(hanging.directory), 1);

    assert.equal(await lookupModelLimit({ ...unlisted.options, retryDelaysMs: [10, 10] }), null);
    assert.equal(await apiCount(unlisted.directory), 1);
  } finally {
    await Promise.all([flaky, broken, hanging, unlisted].map(({ root }) => rm(root, { recursive: true, force: true })));
  }
});

test("stops a lookup at once when its signal aborts, and never starts one that is already aborted", async () => {
  const { root, directory, options } = await fixture({ mode: "success", apiHang: true });
  try {
    const controller = new AbortController();
    const lookup = lookupModelLimit({ ...options, timeoutSeconds: 30, stopGraceMs: 200, signal: controller.signal });
    await waitFor(() => readFile(join(directory, "api-count"), "utf8"));
    const aborted = Date.now();
    controller.abort();

    assert.equal(await lookup, null);
    assert.ok(Date.now() - aborted < 3000, `abort took ${Date.now() - aborted} ms`);
    assert.equal(await apiCount(directory), 1);

    assert.equal(await lookupModelLimit({ ...options, signal: AbortSignal.abort() }), null);
    assert.equal(await apiCount(directory), 1);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("kills what a stopped lookup left behind, even a child that ignores SIGTERM", async () => {
  const { root, directory, options } = await fixture({ mode: "success", apiHang: true, apiGrandchild: true });
  try {
    const controller = new AbortController();
    const lookup = lookupModelLimit({ ...options, timeoutSeconds: 30, stopGraceMs: 200, signal: controller.signal });
    // Stop it only once the fake CLI has started its grandchild, however slow the machine is.
    const grandchildPid = Number(await waitFor(() => readFile(join(directory, "api-grandchild.pid"), "utf8")));
    controller.abort();

    assert.equal(await lookup, null);
    await waitFor(() => !isAlive(grandchildPid), 3000);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("refuses extra environment that would replace the isolation settings", async () => {
  const { root, directory, privateHome, options } = await fixture({ mode: "success" });
  try {
    for (const key of ["HOME", "XDG_CONFIG_HOME", "XDG_DATA_HOME", "OPENCODE_CONFIG_DIR", "OPENCODE_CONFIG", "OPENCODE_CONFIG_CONTENT", "OPENCODE_DISABLE_PROJECT_CONFIG", "OPENCODE_DISABLE_MODELS_FETCH"]) {
      await assert.rejects(runOpenCodeText({ ...options, extraEnv: { [key]: "override" } }), /may not replace/);
    }
    await assert.rejects(readFile(join(directory, "observed.json"), "utf8"), { code: "ENOENT" });

    const environment = textModelEnvironment({
      privateHome,
      configPath: options.configPath,
      configContent: options.configContent,
      extra: { BENCHFORGE_FIXTURE_EXTRA: 1000 },
    });
    assert.equal(environment.HOME, privateHome);
    assert.equal(environment.OPENCODE_CONFIG_DIR, "");
    assert.equal(environment.OPENCODE_DISABLE_PROJECT_CONFIG, "1");
    assert.equal(Object.keys(environment).at(-1), "BENCHFORGE_FIXTURE_EXTRA");
    assert.equal(environment.BENCHFORGE_FIXTURE_EXTRA, "1000");
    // OpenCode 2.x has none of these switches; the restricted config and the private HOME replace them.
    for (const name of [
      "OPENCODE_PURE", "OPENCODE_PERMISSION", "OPENCODE_TUI_CONFIG", "OPENCODE_DISABLE_DEFAULT_PLUGINS", "OPENCODE_DISABLE_EXTERNAL_SKILLS",
      "OPENCODE_DISABLE_LSP_DOWNLOAD", "OPENCODE_DISABLE_CLAUDE_CODE_PROMPT", "OPENCODE_DISABLE_SHARE", "OPENCODE_EXPERIMENTAL_OUTPUT_TOKEN_MAX",
    ]) {
      assert.equal(name in environment, false, name);
    }
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("checks the restricted configuration for the named agent", () => {
  assert.doesNotThrow(() => assertRestrictedConfig(restrictiveConfig("benchforge-workflow"), "benchforge-workflow"));
  assert.doesNotThrow(() => assertRestrictedConfig(restrictiveConfig("benchforge-preparation")));
  assert.throws(() => assertRestrictedConfig(restrictiveConfig("benchforge-preparation"), "benchforge-workflow"));

  const permissive = restrictiveConfig();
  permissive.agent["benchforge-workflow"].tools = { "*": true };
  assert.throws(() => assertRestrictedConfig(permissive, "benchforge-workflow"));
});

// Real step_finish events of OpenCode 1.19 (runs/plush-octopus-v2/_attempts/go-claude-haiku-5-5-a7da46/attempt-2,
// runs/kurtulus-savasi/_logs/go-qwen38-flash-35538a and go-mimo-v26-pro-d284f8): tokens.total is
// input + output + reasoning + cache.read + cache.write.
const REAL_STOP_STEP = {"type":"step_finish","timestamp":1792109966872,"sessionID":"ses_ebdec6920ffetX1PPnt1Bvk5MV","part":{"id":"prt_14213f5df0013dyXxK2G6zqnWx","reason":"stop","messageID":"msg_14213e512001jWd8HwPkZDFpl4","sessionID":"ses_ebdec6920ffetX1PPnt1Bvk5MV","type":"step-finish","tokens":{"total":85155,"input":2,"output":603,"reasoning":151,"cache":{"write":1353,"read":83046}},"cost":0.001376785}};
const REAL_LENGTH_STEP = {"type":"step_finish","timestamp":1791936831027,"sessionID":"ses_ec847168affevSC9U6rESHT87S","part":{"id":"prt_137c21e10001T16l2pmh3wBOp1","reason":"length","messageID":"msg_137b99f85001cUUliV8DoNXVp3","sessionID":"ses_ec847168affevSC9U6rESHT87S","type":"step-finish","tokens":{"total":75578,"input":6,"output":32000,"reasoning":0,"cache":{"write":5005,"read":38567}},"cost":0.016658972}};
const REAL_REASONING_LENGTH_STEP = {"type":"step_finish","timestamp":1791935069202,"sessionID":"ses_ec85d0dd6ffeX5BjbAeG89J6QO","part":{"id":"prt_137a73be1001s9xJ8laeAp4ZIK","reason":"length","messageID":"msg_137a3c72d001m7EPNrclxeEYkX","sessionID":"ses_ec85d0dd6ffeX5BjbAeG89J6QO","type":"step-finish","tokens":{"total":84353,"input":1281,"output":0,"reasoning":32002,"cache":{"write":0,"read":51072}},"cost":0.028584111}};

function textEvent(text) {
  return { type: "text", sessionID: "ses_fixture_text", part: { id: "p1", text } };
}

test("reports the reasoning and cache tokens and the stop reason of a real step", async () => {
  const { root, options } = await fixture({ mode: "events", events: [textEvent("A short answer."), REAL_STOP_STEP] });
  try {
    const result = await runOpenCodeText(options);

    assert.equal(result.status, "completed");
    assert.equal(result.text, "A short answer.");
    assert.equal(result.inputTokens, 2);
    assert.equal(result.outputTokens, 603);
    assert.equal(result.reasoningTokens, 151);
    assert.equal(result.cacheReadTokens, 83046);
    assert.equal(result.cacheWriteTokens, 1353);
    assert.equal(result.inputTokens + result.outputTokens + result.reasoningTokens + result.cacheReadTokens + result.cacheWriteTokens, 85155);
    assert.equal(result.costUsd, 0.001376785);
    assert.equal(result.stopReason, "stop");
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("sums token parts over steps and keeps the reason of the last step", async () => {
  const { root, options } = await fixture({
    mode: "events",
    events: [
      textEvent("Cut off answer"),
      { type: "step_finish", part: { cost: 0.5, tokens: { input: 5, output: 1 }, reason: "unknown" } },
      REAL_STOP_STEP,
      REAL_LENGTH_STEP,
    ],
  });
  try {
    const result = await runOpenCodeText(options);

    assert.equal(result.status, "completed");
    assert.equal(result.inputTokens, 5 + 2 + 6);
    assert.equal(result.outputTokens, 1 + 603 + 32000);
    assert.equal(result.reasoningTokens, 0 + 151 + 0);
    assert.equal(result.cacheReadTokens, 0 + 83046 + 38567);
    assert.equal(result.cacheWriteTokens, 0 + 1353 + 5005);
    assert.equal(result.stopReason, "length");
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("keeps token parts unknown until a step reports a valid value", async () => {
  const { root, options } = await fixture({
    mode: "events",
    events: [
      textEvent("answer"),
      { type: "step_finish", part: { reason: "stop" } },
      { type: "step_finish", part: { tokens: { input: -1, output: "2", reasoning: 1.5, cache: { read: null, write: -3 } } } },
    ],
  });
  try {
    const result = await runOpenCodeText(options);

    assert.equal(result.status, "completed");
    for (const key of ["inputTokens", "outputTokens", "reasoningTokens", "cacheReadTokens", "cacheWriteTokens", "costUsd"]) {
      assert.equal(result[key], null, key);
    }
    assert.equal(result.stopReason, null);

    await writeFile(join(options.directory, "behavior.json"), JSON.stringify({
      mode: "events",
      events: [textEvent("answer"), { type: "step_finish", part: { tokens: { output: 4, reasoning: 3, cache: { read: 9 } } } }],
    }));
    const partial = await runOpenCodeText(options);
    assert.equal(partial.inputTokens, null);
    assert.equal(partial.outputTokens, 4);
    assert.equal(partial.reasoningTokens, 3);
    assert.equal(partial.cacheReadTokens, 9);
    assert.equal(partial.cacheWriteTokens, null);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("returns the length stop reason for a cut-off answer and for a step spent on reasoning", async () => {
  const { root, options } = await fixture({ mode: "events", events: [textEvent('{"ideas": [{"title": "Cut'), REAL_LENGTH_STEP] });
  try {
    const cut = await runOpenCodeText(options);
    assert.equal(cut.status, "completed");
    assert.equal(cut.stopReason, "length");
    assert.equal(cut.outputTokens, 32000);

    await writeFile(join(options.directory, "behavior.json"), JSON.stringify({ mode: "events", events: [REAL_REASONING_LENGTH_STEP] }));
    const empty = await runOpenCodeText(options);
    assert.equal(empty.status, "failed");
    assert.equal(empty.text, "");
    assert.equal(empty.abortReason, null);
    assert.equal(empty.errorMessage, "Model yanıtı alınamadı.");
    assert.equal(empty.stopReason, "length");
    assert.equal(empty.outputTokens, 0);
    assert.equal(empty.reasoningTokens, 32002);
    assert.equal(empty.cacheReadTokens, 51072);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("describes a privacy or data policy refusal of the model in Turkish", () => {
  const refusal = {
    type: "error",
    error: {
      name: "APIError",
      data: {
        statusCode: 400,
        isRetryable: false,
        message: "Upstream request failed: This Go model trains on request data. Allow paid endpoints that train on request data in your workspace's Privacy settings to use it.",
      },
    },
  };

  assert.equal(
    defaultDescribeError(refusal),
    "Sağlayıcı bu modeli hesabınızın gizlilik ayarı nedeniyle reddetti (model istek verisiyle eğitim yapıyor). OpenCode Console veya OpenRouter gizlilik ayarını kontrol edin.",
  );
  assert.equal(
    defaultDescribeError({ type: "error", error: { name: "APIError", data: { statusCode: 401, message: "Invalid key" } } }),
    "Sağlayıcı hesabı isteği reddetti (anahtar, abonelik veya kullanım sınırı). Anahtarı, aboneliği ve sınırı kontrol edin.",
  );
});

const runCount = async (directory) => Number(await readFile(join(directory, "run-count"), "utf8"));
const STARTUP_MESSAGE = "OpenCode başlatılamadı (geçici bir başlatma hatası).";

test("runs a call again when its OpenCode server did not start, and returns the later answer", async () => {
  const { root, directory, options } = await fixture({ mode: "success", startupFailFirst: 2 });
  try {
    const result = await runOpenCodeText({ ...options, startupRetryDelaysMs: [0, 0, 0] });

    assert.equal(result.status, "completed");
    assert.equal(result.text, "ready text");
    assert.equal(result.errorEvent, null);
    assert.equal(result.errorMessage, null);
    assert.equal(await runCount(directory), 3);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("returns the startup failure like any other failure after the last new try", async () => {
  const { root, directory, options } = await fixture({ mode: "success", startupFailFirst: 9 });
  try {
    const result = await runOpenCodeText({ ...options, startupRetryDelaysMs: [0, 0] });

    assert.equal(result.status, "failed");
    assert.equal(result.errorMessage, STARTUP_MESSAGE);
    assert.equal(result.errorEvent.error.message, "Standalone server exited before reporting readiness");
    assert.equal(result.sessionId, null);
    assert.equal(await runCount(directory), 3);
    assert.equal(defaultDescribeError(result.errorEvent), STARTUP_MESSAGE);
    assert.deepEqual([...STARTUP_RETRY_DELAYS_MS], [300, 900, 2000]);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("does not run a call again for any other failure", async () => {
  for (const behavior of [{ mode: "error" }, { mode: "exit-code" }]) {
    const { root, directory, options } = await fixture(behavior);
    try {
      const result = await runOpenCodeText({ ...options, startupRetryDelaysMs: [0, 0, 0] });

      assert.equal(result.status, "failed");
      assert.equal(await runCount(directory), 1);
    } finally {
      await rm(root, { recursive: true, force: true });
    }
  }
});

test("does not run a call again once the model has been asked: the error event has a session", async () => {
  const startupWithSession = { type: "error", sessionID: "ses_fixture_started", error: { type: "unknown", message: "Standalone server exited before reporting readiness" } };
  const { root, directory, options } = await fixture({ mode: "events", events: [startupWithSession], exitCode: 1 });
  try {
    const result = await runOpenCodeText({ ...options, startupRetryDelaysMs: [0, 0, 0] });

    assert.equal(result.status, "failed");
    assert.equal(result.sessionId, "ses_fixture_started");
    assert.equal(await runCount(directory), 1);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("a stop that arrives while waiting for a new try ends the call as stopped, without starting another process", async () => {
  const { root, directory, options } = await fixture({ mode: "success", startupFailFirst: 9 });
  try {
    let stopRequested = false;
    const result = await runOpenCodeText({
      ...options,
      startupRetryDelaysMs: [300, 300, 300],
      isStopRequested: () => stopRequested,
      // The first try has failed by now; the stop request comes during the wait that follows.
      onHandle: (handle) => {
        if (handle === null) setTimeout(() => { stopRequested = true; }, 30);
      },
    });

    assert.equal(result.status, "stopped");
    assert.equal(result.errorMessage, "Kullanıcı tarafından durduruldu.");
    assert.equal(await runCount(directory), 1);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("an aborted signal wakes the wait for a new try at once", async () => {
  const { root, directory, options } = await fixture({ mode: "success", startupFailFirst: 9 });
  try {
    const controller = new AbortController();
    const started = Date.now();
    const result = await runOpenCodeText({
      ...options,
      startupRetryDelaysMs: [20_000],
      signal: controller.signal,
      onHandle: (handle) => {
        if (handle === null) setTimeout(() => controller.abort(), 30);
      },
    });

    assert.equal(result.status, "stopped");
    assert.ok(Date.now() - started < 10_000, `the wait took ${Date.now() - started} ms`);
    assert.equal(await runCount(directory), 1);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("describes a spent model spend limit apart from the account errors", () => {
  const limit = { type: "error", sessionID: "ses_fixture", status: 429, error: { type: "provider.model-budget-limit", message: "You've reached the spend limit for this model. Switch models to keep going.", status: 429 } };

  assert.equal(defaultDescribeError(limit), LIMIT_MESSAGE);
  assert.equal(LIMIT_MESSAGE, "Bu model için harcama sınırı doldu.");
});
