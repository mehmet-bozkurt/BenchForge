import assert from "node:assert/strict";
import { chmod, mkdtemp, mkdir, readFile, realpath, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";
import test from "node:test";

const modulePath = join(dirname(fileURLToPath(import.meta.url)), "../../bin/opencode-text.mjs");
const { assertRestrictedConfig, defaultDescribeError, runOpenCodeText, textModelEnvironment } = await import(pathToFileURL(modulePath));

const fakeCliSource = `#!/usr/bin/env node
import { spawn } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
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
  outputTokenMax: process.env.OPENCODE_EXPERIMENTAL_OUTPUT_TOKEN_MAX,
  environmentKeys: Object.keys(process.env),
}));
const emit = (value) => process.stdout.write(JSON.stringify(value) + '\\n');
await new Promise((resolve) => setTimeout(resolve, behavior.delayMs ?? 0));
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
      extraEnv: { OPENCODE_EXPERIMENTAL_OUTPUT_TOKEN_MAX: 32000 },
      onText: (text) => texts.push(text),
    });
    const seen = await observed(directory);

    assert.equal(result.status, "completed");
    assert.equal(result.text, "ready text");
    assert.equal(result.errorMessage, null);
    assert.deepEqual(seen.args, [
      "run", "--pure", "--format", "json", "--agent", "benchforge-workflow",
      "--model", "openrouter/fixture/model-1", "--dir", directory, "--title", title,
    ]);
    assert.equal(seen.prompt, "Write one sentence.\n");
    assert.equal(seen.cwd, await realpath(directory));
    assert.equal(seen.home, privateHome);
    assert.equal(seen.configPath, options.configPath);
    assert.equal(seen.outputTokenMax, "32000");
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
      "run", "--pure", "--format", "json", "--agent", "benchforge-workflow",
      "--model", "openrouter/fixture/model-1", "--dir", directory,
    ]);
    assert.ok(!seen.environmentKeys.includes("OPENCODE_EXPERIMENTAL_OUTPUT_TOKEN_MAX"));
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

test("refuses extra environment that would replace the isolation settings", async () => {
  const { root, directory, privateHome, options } = await fixture({ mode: "success" });
  try {
    for (const key of ["HOME", "OPENCODE_PERMISSION", "OPENCODE_CONFIG_CONTENT", "OPENCODE_DISABLE_SHARE"]) {
      await assert.rejects(runOpenCodeText({ ...options, extraEnv: { [key]: "override" } }), /may not replace/);
    }
    await assert.rejects(readFile(join(directory, "observed.json"), "utf8"), { code: "ENOENT" });

    const environment = textModelEnvironment({
      privateHome,
      configPath: options.configPath,
      configContent: options.configContent,
      extra: { OPENCODE_EXPERIMENTAL_OUTPUT_TOKEN_MAX: 1000 },
    });
    assert.equal(environment.HOME, privateHome);
    assert.equal(environment.OPENCODE_PERMISSION, "");
    assert.equal(Object.keys(environment).at(-1), "OPENCODE_EXPERIMENTAL_OUTPUT_TOKEN_MAX");
    assert.equal(environment.OPENCODE_EXPERIMENTAL_OUTPUT_TOKEN_MAX, "1000");
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
    "Sağlayıcı hesabı isteği reddetti (401/403). Anahtarı veya aboneliği kontrol edin.",
  );
});
