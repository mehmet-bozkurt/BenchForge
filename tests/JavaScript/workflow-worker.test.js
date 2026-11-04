import assert from "node:assert/strict";
import { execFile, spawn } from "node:child_process";
import { once } from "node:events";
import { chmod, copyFile, mkdtemp, mkdir, readFile, readdir, realpath, rm, stat, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { promisify } from "node:util";
import test, { afterEach } from "node:test";
import { Semaphore, runPool, sleep } from "../../bin/workflow/limiter.mjs";
import { EventLog, RunStateWriter, isoTimestamp, mergeUsage } from "../../bin/workflow/run-state.mjs";

const repositoryRoot = join(dirname(fileURLToPath(import.meta.url)), "../..");
const workerPath = join(repositoryRoot, "bin/workflow-worker.mjs");
const gameTemplatePath = join(repositoryRoot, "engine/workflow-templates/game-idea-factory/v001.json");
const ISO_WITH_OFFSET = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/;
const RUN_ID = "fixture-flow-20261022-183012";
const ACCOUNT_MESSAGE = "Sağlayıcı hesabı isteği reddetti (401/403). Anahtarı veya aboneliği kontrol edin.";

// The fake CLI picks the first rule whose `match` substrings all occur in the prompt (and whose `model`
// occurs in --model); `responses` are used in call order per rule. Every call is recorded next to it.
// Its command line, and that of the grandchild a hanging call starts, names the fake directory.
const fakeCliSource = `#!/usr/bin/env node
import { spawn } from 'node:child_process';
import { closeSync, mkdirSync, openSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
const here = dirname(fileURLToPath(import.meta.url));
const prompt = await new Promise((resolve) => {
  let input = '';
  process.stdin.setEncoding('utf8');
  process.stdin.on('data', (chunk) => { input += chunk; });
  process.stdin.on('end', () => resolve(input));
});
const args = process.argv.slice(2);
const model = args[args.indexOf('--model') + 1];
const marker = join(here, 'running', String(process.pid));
writeFileSync(marker, '');
const concurrent = readdirSync(join(here, 'running')).length;
const { rules } = JSON.parse(readFileSync(join(here, 'behavior.json'), 'utf8'));
const ruleIndex = rules.findIndex((rule) => [].concat(rule.match ?? []).every((text) => prompt.includes(text))
  && (!rule.model || model.includes(rule.model)));
let response = { text: 'UNMATCHED PROMPT' };
if (ruleIndex !== -1) {
  let call = 0;
  for (;;) {
    try { closeSync(openSync(join(here, 'counters', ruleIndex + '-' + call), 'wx')); break; } catch { call += 1; }
  }
  const responses = rules[ruleIndex].responses ?? [rules[ruleIndex].response];
  response = responses[Math.min(call, responses.length - 1)];
}
writeFileSync(join(here, 'calls', process.pid + '.json'), JSON.stringify({
  pid: process.pid,
  startedAt: Date.now(),
  args,
  model,
  prompt,
  cwd: process.cwd(),
  rule: ruleIndex,
  concurrent,
  env: Object.fromEntries(Object.entries(process.env).filter(([key]) => key.startsWith('OPENCODE_') || key.startsWith('XDG_') || key === 'HOME')),
  environmentKeys: Object.keys(process.env),
}));
const sessionID = 'ses_fake' + process.pid;
const emit = (event) => process.stdout.write(JSON.stringify({ sessionID, ...event }) + '\\n');
const done = () => rmSync(marker, { force: true });
if (response.hang) {
  const grandchild = spawn(process.execPath, ['-e', 'process.on("SIGTERM", () => {}); setInterval(() => {}, 1000)', here], { stdio: 'ignore' });
  writeFileSync(join(here, 'grandchildren', String(grandchild.pid)), '');
  if (response.text) emit({ type: 'text', messageID: 'm1', part: { id: 'p1', text: response.text } });
  setInterval(() => {}, 1000);
} else {
  setTimeout(() => {
    if (response.error) {
      emit({ type: 'error', error: response.error });
      done();
      process.exitCode = 1;
      return;
    }
    for (const text of [].concat(response.text ?? [])) {
      emit({ type: 'text', messageID: 'm1', part: { id: 'p1', text } });
    }
    if (response.usage !== null) {
      emit({ type: 'step_finish', part: response.finish ?? { cost: 0.001, tokens: { input: 10, output: 5 }, reason: 'stop' } });
    }
    done();
  }, response.delayMs ?? 0);
}
`;

const MODELS = [
  { slug: "or-alpha-111111", model: "openrouter/fixture/alpha:free", name: "Alpha (free)", provider: "openrouter" },
  { slug: "or-beta-222222", model: "openrouter/fixture/beta", name: "Beta", provider: "openrouter" },
  { slug: "go-gamma-333333", model: "opencode-go/gamma", name: "Gamma", provider: "opencode-go" },
];

function restrictiveConfig() {
  return {
    $schema: "https://opencode.ai/config.json",
    autoupdate: false,
    share: "disabled",
    plugin: [],
    instructions: [],
    mcp: {},
    tools: { "*": false },
    permission: { "*": "deny" },
    agent: {
      "benchforge-workflow": {
        description: "BenchForge iş akışı adımı",
        mode: "primary",
        prompt: "Follow the user's instructions exactly.",
        tools: { "*": false },
        permission: { "*": "deny" },
      },
    },
  };
}

function flowTemplate(steps, extra = {}) {
  return { schema: "benchforge-workflow/1", id: "fixture-flow", name: "Deneme akışı", version: 1, steps, ...extra };
}

function textStep(id, prompt, extra = {}) {
  return { id, type: "llm_text", title: `Adım ${id}`, prompt, ...extra };
}

// Every worker launch() started and every fixture's fake CLI directory since the last test ended. A test that
// fails before its worker exits has already removed its fixture in `finally`, so the hook below ends what is left:
// a running worker gets SIGTERM (it then ends its OpenCode process groups) and SIGKILL after 5 s, then every
// process whose command line names a fake directory (a fake CLI group leader or its grandchild) gets SIGKILL.
// A failing test so leaves no process behind and never holds the test file open.
const launchedWorkers = new Set();
const fakeDirectories = new Set();
const execFileAsync = promisify(execFile);

afterEach(async () => {
  const workers = [...launchedWorkers];
  const directories = [...fakeDirectories];
  launchedWorkers.clear();
  fakeDirectories.clear();
  await Promise.all(workers.map((child) => endWorker(child)));
  await killFakeCliProcesses(directories);
});

async function endWorker(child, graceMs = 5000) {
  if (child.exitCode !== null || child.signalCode !== null) return;
  const exited = once(child, "exit");
  child.kill("SIGTERM");
  const timer = setTimeout(() => child.kill("SIGKILL"), graceMs);
  try {
    await exited;
  } finally {
    clearTimeout(timer);
  }
}

async function killFakeCliProcesses(directories) {
  if (directories.length === 0) return;
  const { stdout } = await execFileAsync("ps", ["-A", "-ww", "-o", "pid=,pgid=,command="]);
  for (const line of stdout.split("\n")) {
    const [, pid, groupId, command] = /^\s*(\d+)\s+(\d+)\s(.*)$/.exec(line) ?? [];
    if (command === undefined || !directories.some((directory) => command.includes(directory))) continue;
    try {
      process.kill(pid === groupId ? -Number(pid) : Number(pid), "SIGKILL");
    } catch (error) {
      if (error.code !== "ESRCH" && error.code !== "EPERM") throw error;
    }
  }
}

async function fixture({ template, inputs = {}, models = 1, rules = [], settings = {}, testing = {} }) {
  const base = await realpath(await mkdtemp(join(tmpdir(), "benchforge-workflow-worker-")));
  const fakeDirectory = join(base, "fake");
  const cliPath = join(fakeDirectory, "opencode.mjs");
  for (const name of ["calls", "running", "counters", "grandchildren"]) {
    await mkdir(join(fakeDirectory, name), { recursive: true });
  }
  fakeDirectories.add(fakeDirectory);
  await writeFile(cliPath, fakeCliSource, { mode: 0o700 });
  await chmod(cliPath, 0o700);
  await writeFile(join(fakeDirectory, "behavior.json"), JSON.stringify({ rules }));

  const runDirectory = join(base, "workflow-runs", RUN_ID);
  await mkdir(runDirectory, { recursive: true });
  if (typeof template === "string") {
    await copyFile(template, join(runDirectory, "template.json"));
  } else {
    await writeFile(join(runDirectory, "template.json"), JSON.stringify(template));
  }
  await writeFile(join(runDirectory, "inputs.json"), JSON.stringify(inputs));

  const selected = MODELS.slice(0, models);
  for (const model of selected) {
    await mkdir(join(runDirectory, "models", model.slug), { recursive: true });
    await writeFile(join(runDirectory, "models", model.slug, "opencode.json"), JSON.stringify(restrictiveConfig()), { mode: 0o600 });
  }

  const job = {
    schema: "benchforge-workflow-job/1",
    runId: RUN_ID,
    runDirectory,
    templateFile: "template.json",
    inputs,
    models: selected.map((model) => ({ ...model, configFile: `models/${model.slug}/opencode.json` })),
    settings: {
      maxParallelModels: selected.length,
      maxConcurrentCalls: 4,
      stepModes: {},
      timeouts: { llm: null, code: null },
      testing: { backoffScale: 0.001, ...testing },
      ...settings,
    },
    opencode: { binary: cliPath },
    createdAt: "2026-10-22T18:30:12+03:00",
  };
  const jobPath = join(runDirectory, "job.json");
  await writeFile(jobPath, JSON.stringify(job), { mode: 0o600 });

  return { base, fakeDirectory, runDirectory, jobPath, job, models: selected, statePath: join(runDirectory, "state.json") };
}

function launch(jobPath, extraEnv = {}) {
  const child = spawn(process.execPath, [workerPath, jobPath], {
    env: {
      ...process.env,
      APP_KEY: "fixture-app-secret",
      OPENROUTER_API_KEY: "fixture-provider-secret",
      ...extraEnv,
      BENCHFORGE_RUNNER: "1",
    },
    stdio: ["ignore", "ignore", "pipe"],
  });
  launchedWorkers.add(child);
  return child;
}

async function waitForExit(child, maxMs = 20000) {
  const timer = setTimeout(() => child.kill("SIGKILL"), maxMs);
  try {
    const [code, signal] = await once(child, "exit");
    return { code, signal };
  } finally {
    clearTimeout(timer);
  }
}

async function waitFor(check, maxMs = 5000) {
  const until = Date.now() + maxMs;
  while (Date.now() < until) {
    try {
      const value = await check();
      if (value) return value;
    } catch (error) {
      if (error.code !== "ENOENT" && !(error instanceof SyntaxError)) throw error;
    }
    await new Promise((resolve) => setTimeout(resolve, 20));
  }
  throw new Error("Timed out waiting for the fixture");
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

async function readJson(path) {
  return JSON.parse(await readFile(path, "utf8"));
}

async function calls(fakeDirectory) {
  const names = await readdir(join(fakeDirectory, "calls"));
  const records = await Promise.all(names.map((name) => readJson(join(fakeDirectory, "calls", name))));
  return records.sort((left, right) => left.startedAt - right.startedAt || left.pid - right.pid);
}

async function spawnedPids(fakeDirectory) {
  const grandchildren = (await readdir(join(fakeDirectory, "grandchildren"))).map(Number);
  return [...(await calls(fakeDirectory)).map((call) => call.pid), ...grandchildren];
}

async function events(runDirectory) {
  return (await readFile(join(runDirectory, "events.jsonl"), "utf8")).trim().split("\n").map((line) => JSON.parse(line));
}

function titleOf(call) {
  return call.args[call.args.indexOf("--title") + 1];
}

function idea(title) {
  return {
    title,
    references: [],
    pitch: `${title} pitch`,
    coreLoop: "Tap, dodge, score.",
    controls: "One finger drag.",
    first10Seconds: "The player drags at once.",
    sessionLength: "60 s rounds",
    whyReturn: "Beat the best score.",
    hook: "Big satisfying crashes.",
    rewardedAdMoments: ["Continue after a crash"],
    hitPotential: { rating: "medium", reasons: ["Instant fun"], risks: ["Crowded genre"] },
    platformFit: "Fits Playables.",
    demoSpec: `Scope of ${title}.`,
  };
}

function fenced(language, body) {
  return `Here it is.\n\`\`\`${language}\n${body}\n\`\`\``;
}

function demoHtml(listener) {
  return `<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Fixture Demo</title></head>
<body>
<canvas id="game"></canvas>
<script>
const canvas = document.getElementById('game');
canvas.addEventListener('${listener}', (event) => event.preventDefault());
window.addEventListener('resize', () => {});
function frame() { requestAnimationFrame(frame); }
frame();
</script>
</body>
</html>`;
}

test("runs the Game Idea Factory template end to end for two models", async () => {
  const goodHtml = demoHtml("pointerdown");
  const titles = ["Existing Idea", "Original Idea", "Hybrid Idea", "Reimagined Idea"];
  const scores = { scores: titles.map((title) => ({ title, score: 7, verdict: "İyi.", reasoning: "Sade ve eğlenceli." })) };
  const brokenOriginal = `\`\`\`json\n{"ideas": [${JSON.stringify(idea("Original Idea"))},]}\n\`\`\``;
  const { base, fakeDirectory, runDirectory, jobPath, models, statePath } = await fixture({
    template: gameTemplatePath,
    models: 2,
    inputs: { ideasPerCategory: 1, docLanguage: "Türkçe", extraBrief: "" },
    settings: { maxConcurrentCalls: 4, stepModes: { build: "code_text" } },
    rules: [
      { match: "YOUR PREVIOUS ANSWER HAD THESE PROBLEMS", response: { text: fenced("html", goodHtml) } },
      { match: "Rate every game idea below", response: { text: fenced("json", JSON.stringify(scores)) } },
      { match: ["Build a playable", "TITLE: Hybrid Idea"], model: "alpha", response: { text: fenced("html", demoHtml("keydown")) } },
      { match: "Build a playable", response: { text: fenced("html", goodHtml) } },
      { match: "CATEGORY:\nORIGINAL GAME", model: "beta", response: { text: brokenOriginal } },
      { match: "CATEGORY:\nEXISTING GAME", response: { text: fenced("json", JSON.stringify({ ideas: [idea(titles[0])] })) } },
      { match: "CATEGORY:\nORIGINAL GAME", response: { text: fenced("json", JSON.stringify({ ideas: [idea(titles[1])] })) } },
      { match: "CATEGORY:\nHYBRID", response: { text: fenced("json", JSON.stringify({ ideas: [idea(titles[2])] })) } },
      { match: "CATEGORY:\nREIMAGINED", response: { text: fenced("json", JSON.stringify({ ideas: [idea(titles[3])] })) } },
    ],
  });
  const [alpha, beta] = models;
  try {
    const child = launch(jobPath);
    const exit = await waitForExit(child);
    const state = await readJson(statePath);
    const observed = await calls(fakeDirectory);

    assert.deepEqual(exit, { code: 0, signal: null });
    assert.equal(state.schema, "benchforge-workflow-state/1");
    assert.equal(state.runId, RUN_ID);
    assert.equal(state.pid, child.pid);
    assert.equal(state.status, "completed");
    assert.equal(state.message, null);
    for (const value of [state.startedAt, state.updatedAt, state.finishedAt]) assert.match(value, ISO_WITH_OFFSET);
    assert.equal(observed.some((call) => call.rule === -1), false);

    for (const [model, callCount] of [[alpha, 10], [beta, 9]]) {
      const entry = state.models[model.slug];
      assert.equal(entry.status, "completed");
      assert.equal(entry.model, model.model);
      assert.equal(entry.name, model.name);
      assert.deepEqual(entry.active, []);
      assert.deepEqual(entry.items, { total: 8, completed: 8, failed: 0 });
      assert.deepEqual(entry.usage, { inputTokens: callCount * 10, outputTokens: callCount * 5, costUsd: Number((callCount * 0.001).toFixed(10)), calls: callCount });
      assert.deepEqual(Object.keys(entry.steps), ["ideas", "score", "demos"]);
      assert.deepEqual(entry.steps.ideas.counts, { total: 4, completed: 4, failed: 0, running: 0, skipped: 0, stopped: 0 });
      assert.deepEqual(entry.steps.ideas.items.map((item) => item.label), ["Var olan oyun", "Özgün oyun", "Melez oyun", "Yeniden yorum"]);
      assert.equal(entry.steps.score.status, "completed");
      assert.equal(entry.steps.score.attempts, 1);
      assert.equal(entry.steps.demos.status, "completed");
      assert.deepEqual(entry.steps.demos.items.map((item) => item.label), titles);
      for (const item of entry.steps.demos.items) {
        assert.deepEqual(item.steps, { build: "completed", check: "skipped" });
      }

      const { active: _active, ...expectedSummary } = entry;
      assert.deepEqual(await readJson(join(runDirectory, "models", model.slug, "summary.json")), expectedSummary);

      const modelCalls = observed.filter((call) => call.model === model.model);
      assert.equal(modelCalls.length, callCount);
      for (const call of modelCalls) {
        const workspace = join(runDirectory, "models", model.slug, "workspace");
        assert.deepEqual(call.args.slice(0, 10), ["run", "--pure", "--format", "json", "--agent", "benchforge-workflow", "--model", model.model, "--dir", workspace]);
        assert.equal(call.cwd, workspace);
        assert.equal(call.env.HOME, join(runDirectory, "models", model.slug, ".benchforge-home"));
        assert.equal(call.env.OPENCODE_CONFIG, join(runDirectory, "models", model.slug, "opencode.json"));
        for (const key of ["APP_KEY", "OPENROUTER_API_KEY", "BENCHFORGE_RUNNER"]) {
          assert.equal(call.environmentKeys.includes(key), false);
        }
      }
    }

    const titlesOfAlpha = observed.filter((call) => call.model === alpha.model).map(titleOf);
    assert.ok(titlesOfAlpha.includes(`benchforge:workflow:${RUN_ID}:${alpha.slug}:generate:001:1`));
    assert.ok(titlesOfAlpha.includes(`benchforge:workflow:${RUN_ID}:${alpha.slug}:score:-:1`));
    assert.ok(titlesOfAlpha.includes(`benchforge:workflow:${RUN_ID}:${alpha.slug}:build:003:2`));

    const generateCall = observed.find((call) => titleOf(call) === `benchforge:workflow:${RUN_ID}:${alpha.slug}:generate:002:1`);
    assert.equal(generateCall.env.OPENCODE_EXPERIMENTAL_OUTPUT_TOKEN_MAX, "32000");
    assert.match(generateCall.prompt, /DO NOT REPEAT \(ideas from earlier categories; empty for the first category\):\n- Existing Idea\n/);
    assert.match(generateCall.prompt, /Write exactly 1 game ideas for this category\./);
    assert.ok(generateCall.prompt.includes("\n\nOUTPUT FORMAT (mandatory):\n"));
    const buildCall = observed.find((call) => titleOf(call) === `benchforge:workflow:${RUN_ID}:${beta.slug}:build:001:1`);
    assert.equal(buildCall.env.OPENCODE_EXPERIMENTAL_OUTPUT_TOKEN_MAX, "100000");
    assert.ok(buildCall.prompt.includes("TITLE: Existing Idea\n"));
    const scoreCall = observed.find((call) => titleOf(call) === `benchforge:workflow:${RUN_ID}:${beta.slug}:score:-:1`);
    for (const title of titles) assert.ok(scoreCall.prompt.includes(`"title": "${title}"`));

    const repairedJson = await readJson(join(runDirectory, "models", beta.slug, "steps/ideas/items/002/generate/result.json"));
    assert.equal(repairedJson.status, "completed");
    assert.equal(repairedJson.attempts.length, 1);
    assert.equal(repairedJson.output.ideas[0].title, "Original Idea");
    assert.deepEqual(repairedJson.attempts[0].files, {
      prompt: "attempt-1/prompt.txt", response: "attempt-1/response.txt", output: "attempt-1/output.json", demo: null, checks: null,
    });

    const buildDirectory = join(runDirectory, "models", alpha.slug, "steps/demos/items/003/build");
    const build = await readJson(join(buildDirectory, "result.json"));
    assert.equal(build.schema, "benchforge-workflow-result/1");
    assert.equal(build.stepId, "build");
    assert.equal(build.type, "code_text");
    assert.equal(build.item, "003");
    assert.equal(build.status, "completed");
    assert.deepEqual(build.attempts.map((attempt) => [attempt.n, attempt.kind, attempt.status]), [[1, "initial", "failed"], [2, "repair", "completed"]]);
    assert.match(build.attempts[0].error, /Dokunma\/fare dinleyicisi yok/);
    assert.equal(build.attempts[1].sessionId.startsWith("ses_fake"), true);
    assert.deepEqual(build.output, { file: "demo.html", bytes: Buffer.byteLength(goodHtml), checks: { critical: [], warnings: [] } });
    assert.deepEqual(build.usage, { inputTokens: 20, outputTokens: 10, costUsd: 0.002, calls: 2 });
    assert.equal(await readFile(join(buildDirectory, "demo.html"), "utf8"), goodHtml);
    assert.equal(await readFile(join(buildDirectory, "attempt-1/demo.html"), "utf8"), demoHtml("keydown"));
    assert.equal((await readJson(join(buildDirectory, "attempt-1/checks.json"))).critical[0].code, "missing-input-listener");
    const repairPrompt = await readFile(join(buildDirectory, "attempt-2/prompt.txt"), "utf8");
    assert.ok(repairPrompt.includes("YOUR PREVIOUS ANSWER HAD THESE PROBLEMS:\n- No input listener"));
    assert.ok(repairPrompt.includes(`Previous HTML:\n\`\`\`html\n${demoHtml("keydown")}\n\`\`\``));
    for (const value of [build.startedAt, build.finishedAt, build.attempts[0].startedAt]) assert.match(value, ISO_WITH_OFFSET);

    const check = await readJson(join(runDirectory, "models", alpha.slug, "steps/demos/items/003/check/result.json"));
    assert.equal(check.status, "skipped");
    assert.equal(check.message, "Tarayıcı testi Aşama 2'de etkinleşecek.");

    const demoItems = await readJson(join(runDirectory, "models", alpha.slug, "steps/demos/items.json"));
    assert.deepEqual(demoItems.map((item) => [item.key, item.index, item.label, item.item.title]), titles.map((title, index) => [`00${index + 1}`, index, title, title]));
    const demoItem = await readJson(join(runDirectory, "models", alpha.slug, "steps/demos/items/001/item.json"));
    assert.equal(demoItem.status, "completed");
    assert.equal(demoItem.galleryTitle, "Existing Idea");
    const ideaItem = await readJson(join(runDirectory, "models", alpha.slug, "steps/ideas/items/001/item.json"));
    assert.equal(ideaItem.label, "Var olan oyun");
    assert.equal(Object.hasOwn(ideaItem, "galleryTitle"), false);
    const score = await readJson(join(runDirectory, "models", alpha.slug, "steps/score/result.json"));
    assert.deepEqual(score.output, scores);
    assert.equal(score.item, null);

    const log = await events(runDirectory);
    const types = new Set(log.map((entry) => entry.type));
    for (const type of ["run_started", "model_started", "step_started", "item_started", "attempt_started", "attempt_failed",
      "repair_requested", "step_completed", "step_skipped", "item_completed", "model_finished", "run_finished"]) {
      assert.ok(types.has(type), type);
    }
    assert.equal(log.at(-1).type, "run_finished");
    for (const entry of log) {
      assert.match(entry.time, ISO_WITH_OFFSET);
      assert.deepEqual(Object.keys(entry), ["time", "level", "type", "model", "step", "item", "message"]);
    }
    assert.equal((await stat(statePath)).mode & 0o777, 0o600);
  } finally {
    await rm(base, { recursive: true, force: true });
  }
});

test("retries a schema failure with the problem and the previous answer in the prompt", async () => {
  const schema = { type: "object", required: ["answer"], properties: { answer: { type: "string" } } };
  const { base, fakeDirectory, runDirectory, jobPath, models, statePath } = await fixture({
    template: flowTemplate([{ id: "answer", type: "llm_json", title: "Cevap", prompt: "Give the answer.", schema }]),
    rules: [
      { match: "YOUR PREVIOUS ANSWER WAS REJECTED", response: { text: fenced("json", '{"answer":"ok"}') } },
      { match: "Give the answer.", response: { text: fenced("json", '{"wrong": 1}') } },
    ],
  });
  try {
    const exit = await waitForExit(launch(jobPath));
    const state = await readJson(statePath);
    const observed = await calls(fakeDirectory);
    const result = await readJson(join(runDirectory, "models", models[0].slug, "steps/answer/result.json"));

    assert.deepEqual(exit, { code: 0, signal: null });
    assert.equal(state.status, "completed");
    assert.equal(observed.length, 2);
    assert.ok(observed[1].prompt.startsWith("Give the answer.\n\nOUTPUT FORMAT (mandatory):\n"));
    assert.ok(observed[1].prompt.includes("YOUR PREVIOUS ANSWER WAS REJECTED.\nProblem: $.answer: required\n"));
    assert.ok(observed[1].prompt.includes('Your previous answer (truncated):\nHere it is.\n```json\n{"wrong": 1}\n```\nReturn the corrected JSON now.'));
    assert.deepEqual(result.attempts.map((attempt) => [attempt.kind, attempt.status]), [["initial", "failed"], ["retry", "completed"]]);
    assert.match(result.attempts[0].error, /şemaya uymuyor: \$\.answer: required/);
    assert.deepEqual(await readJson(join(runDirectory, "models", models[0].slug, "steps/answer/attempt-1/output.json")), { wrong: 1 });
    assert.deepEqual(result.output, { answer: "ok" });
    assert.equal(state.models[models[0].slug].steps.answer.attempts, 2);
  } finally {
    await rm(base, { recursive: true, force: true });
  }
});

test("accepts an optional input left empty and renders it as empty text", async () => {
  const { base, fakeDirectory, jobPath, statePath } = await fixture({
    template: flowTemplate([textStep("note", "Count: [{{inputs.count}}]")], {
      inputs: [{ id: "count", type: "integer", label: "Adet", default: 1, required: false }],
    }),
    inputs: { count: null },
    rules: [{ match: "Count:", response: { text: "done" } }],
  });
  try {
    const exit = await waitForExit(launch(jobPath));
    const state = await readJson(statePath);
    const observed = await calls(fakeDirectory);

    assert.deepEqual(exit, { code: 0, signal: null });
    assert.equal(state.status, "completed");
    assert.equal(observed.length, 1);
    assert.equal(observed[0].prompt, "Count: []");
  } finally {
    await rm(base, { recursive: true, force: true });
  }
});

test("waits after a rate limit without spending a content attempt and keeps unknown usage unknown", async () => {
  const { base, runDirectory, jobPath, models, statePath } = await fixture({
    template: flowTemplate([textStep("write", "Write a haiku.", { retry: { attempts: 1 } })]),
    rules: [{
      match: "Write a haiku.",
      responses: [
        { error: { name: "APIError", data: { statusCode: 429, isRetryable: true, message: "Rate limit exceeded" } } },
        { text: "Old pond." },
      ],
    }],
  });
  try {
    const exit = await waitForExit(launch(jobPath));
    const state = await readJson(statePath);
    const entry = state.models[models[0].slug];
    const result = await readJson(join(runDirectory, "models", models[0].slug, "steps/write/result.json"));

    assert.deepEqual(exit, { code: 0, signal: null });
    assert.equal(state.status, "completed");
    assert.deepEqual(result.attempts.map((attempt) => [attempt.kind, attempt.status]), [["initial", "failed"], ["transient", "completed"]]);
    assert.equal(result.output, "Old pond.");
    assert.deepEqual(result.usage, { inputTokens: null, outputTokens: null, costUsd: null, calls: 2 });
    assert.deepEqual(entry.usage, { inputTokens: null, outputTokens: null, costUsd: null, calls: 2 });
    assert.equal(entry.steps.write.attempts, 2);
    const rateLimited = (await events(runDirectory)).filter((event) => event.type === "rate_limited");
    assert.equal(rateLimited.length, 1);
    assert.equal(rateLimited[0].level, "warn");
    assert.match(rateLimited[0].message, /15 sn sonra/);
  } finally {
    await rm(base, { recursive: true, force: true });
  }
});

test("stops the whole run on an account error and ends calls of other models", async () => {
  const { base, fakeDirectory, jobPath, models, statePath } = await fixture({
    template: flowTemplate([textStep("write", "Write a haiku."), textStep("again", "Write another.")]),
    models: 3,
    settings: { maxParallelModels: 2 },
    rules: [
      { match: "Write a haiku.", model: "alpha", response: { delayMs: 300, error: { name: "APIError", data: { statusCode: 401, message: "Invalid key" } } } },
      { match: "Write a haiku.", response: { hang: true } },
    ],
  });
  try {
    const exit = await waitForExit(launch(jobPath));
    const state = await readJson(statePath);
    const observed = await calls(fakeDirectory);

    assert.deepEqual(exit, { code: 0, signal: null });
    assert.equal(state.status, "failed");
    assert.equal(state.message, ACCOUNT_MESSAGE);
    for (const model of models) {
      assert.equal(state.models[model.slug].status, "failed");
      assert.equal(state.models[model.slug].message, ACCOUNT_MESSAGE);
    }
    assert.equal(state.models[models[1].slug].steps.write.status, "failed");
    assert.equal(state.models[models[1].slug].steps.again.status, "pending");
    assert.equal(state.models[models[2].slug].steps.write.status, "pending");
    assert.deepEqual(observed.map((call) => call.model).sort(), [models[0].model, models[1].model].sort());
    for (const pid of await spawnedPids(fakeDirectory)) await waitFor(() => !isAlive(pid), 3000);
  } finally {
    await rm(base, { recursive: true, force: true });
  }
});

test("fails only the model the provider no longer serves", async () => {
  const { base, fakeDirectory, jobPath, models, statePath } = await fixture({
    template: flowTemplate([textStep("first", "First task."), textStep("second", "Second task using {{steps.first.output}}.")]),
    models: 2,
    rules: [
      { match: "First task.", model: "alpha", response: { error: { name: "APIError", data: { statusCode: 410, message: "Model deprecated" } } } },
      { match: "First task.", response: { text: "first answer" } },
      { match: "Second task", response: { text: "second answer" } },
    ],
  });
  try {
    const exit = await waitForExit(launch(jobPath));
    const state = await readJson(statePath);
    const [alpha, beta] = models;

    assert.deepEqual(exit, { code: 0, signal: null });
    assert.equal(state.status, "partial");
    assert.equal(state.models[alpha.slug].status, "failed");
    assert.equal(state.models[alpha.slug].message, "Model sağlayıcıda bulunamadı veya kaldırıldı.");
    assert.equal(state.models[alpha.slug].steps.first.status, "failed");
    assert.equal(state.models[alpha.slug].steps.second.status, "pending");
    assert.equal(state.models[beta.slug].status, "completed");
    const secondCall = (await calls(fakeDirectory)).find((call) => call.prompt.startsWith("Second task"));
    assert.equal(secondCall.prompt, "Second task using first answer.");
  } finally {
    await rm(base, { recursive: true, force: true });
  }
});

test("fails an attempt that runs past its time limit and ends the CLI process group", async () => {
  const { base, fakeDirectory, runDirectory, jobPath, models, statePath } = await fixture({
    template: flowTemplate([textStep("write", "Write slowly.", { retry: { attempts: 1 } })]),
    testing: { timeoutSeconds: 1 },
    rules: [{ match: "Write slowly.", response: { hang: true } }],
  });
  try {
    const exit = await waitForExit(launch(jobPath));
    const state = await readJson(statePath);
    const result = await readJson(join(runDirectory, "models", models[0].slug, "steps/write/result.json"));

    assert.deepEqual(exit, { code: 0, signal: null });
    assert.equal(state.status, "failed");
    assert.equal(state.models[models[0].slug].status, "failed");
    assert.equal(state.models[models[0].slug].steps.write.message, "Süre sınırı (1 sn) doldu.");
    assert.equal(result.status, "failed");
    assert.equal(result.attempts[0].error, "Süre sınırı (1 sn) doldu.");
    for (const pid of await spawnedPids(fakeDirectory)) await waitFor(() => !isAlive(pid), 3000);
  } finally {
    await rm(base, { recursive: true, force: true });
  }
});

test("stops on SIGTERM: active calls end, running work is stopped and pending work stays pending", async () => {
  const { base, fakeDirectory, runDirectory, jobPath, models, statePath } = await fixture({
    template: flowTemplate([textStep("first", "First task."), textStep("second", "Second task.")]),
    rules: [{ match: "First task.", response: { hang: true, text: "partial answer" } }],
  });
  const slug = models[0].slug;
  try {
    const started = Date.now();
    const child = launch(jobPath);
    const firstState = await waitFor(() => readJson(statePath), 2000);
    assert.ok(Date.now() - started < 2000);
    assert.equal(firstState.status, "running");
    assert.equal(firstState.pid, child.pid);

    const running = await waitFor(async () => {
      const state = await readJson(statePath);
      return state.models[slug].active[0]?.tail === "partial answer" ? state : null;
    });
    assert.deepEqual(running.models[slug].active[0], {
      step: "first", child: null, item: null, label: null, attempt: 1, startedAt: running.models[slug].active[0].startedAt, tail: "partial answer",
    });

    child.kill("SIGTERM");
    const stopping = await waitFor(async () => {
      const state = await readJson(statePath);
      return state.status === "stopping" ? state : null;
    }, 1000);
    assert.equal(stopping.message, "Çalıştırma durduruluyor.");
    const exit = await waitForExit(child);
    const state = await readJson(statePath);
    const result = await readJson(join(runDirectory, "models", slug, "steps/first/result.json"));

    assert.deepEqual(exit, { code: 0, signal: null });
    assert.equal(state.status, "stopped");
    assert.equal(state.message, "Çalıştırma durduruldu.");
    assert.equal(state.models[slug].status, "stopped");
    assert.deepEqual(state.models[slug].active, []);
    assert.equal(state.models[slug].steps.first.status, "stopped");
    assert.equal(state.models[slug].steps.second.status, "pending");
    assert.equal(result.status, "stopped");
    assert.deepEqual(result.attempts.map((attempt) => attempt.status), ["stopped"]);
    assert.equal(await readFile(join(runDirectory, "models", slug, "steps/first/attempt-1/response.txt"), "utf8"), "partial answer");
    const types = (await events(runDirectory)).map((event) => event.type);
    assert.ok(types.includes("stop_requested"));
    assert.equal(types.at(-1), "run_finished");
    for (const pid of await spawnedPids(fakeDirectory)) await waitFor(() => !isAlive(pid), 3000);
  } finally {
    await rm(base, { recursive: true, force: true });
  }
});

test("runs foreach items with the foreach concurrency", async () => {
  const { base, fakeDirectory, runDirectory, jobPath, models, statePath } = await fixture({
    template: flowTemplate([{
      id: "each",
      type: "foreach",
      title: "Her öğe",
      over: "constants.names",
      concurrency: 2,
      steps: [textStep("say", "Say {{item}} as item {{loop.number}} of {{loop.total}}.")],
    }], { constants: { names: ["a", "b", "c", "d", "e"] } }),
    settings: { maxConcurrentCalls: 10 },
    rules: [{ match: "Say", response: { text: "said", delayMs: 300 } }],
  });
  try {
    const exit = await waitForExit(launch(jobPath));
    const state = await readJson(statePath);
    const observed = await calls(fakeDirectory);

    assert.deepEqual(exit, { code: 0, signal: null });
    assert.equal(state.status, "completed");
    assert.equal(observed.length, 5);
    assert.equal(Math.max(...observed.map((call) => call.concurrent)), 2);
    assert.ok(observed.some((call) => call.prompt === "Say c as item 3 of 5."));
    assert.deepEqual(state.models[models[0].slug].steps.each.items.map((item) => [item.key, item.label, item.status]), [
      ["001", "Öğe 1", "completed"], ["002", "Öğe 2", "completed"], ["003", "Öğe 3", "completed"], ["004", "Öğe 4", "completed"], ["005", "Öğe 5", "completed"],
    ]);
    const result = await readJson(join(runDirectory, "models", models[0].slug, "steps/each/items/005/say/result.json"));
    assert.equal(result.item, "005");
    assert.equal(result.output, "said");
  } finally {
    await rm(base, { recursive: true, force: true });
  }
});

test("never runs more OpenCode calls at once than maxConcurrentCalls across models", async () => {
  const { base, fakeDirectory, jobPath, statePath } = await fixture({
    template: flowTemplate([{
      id: "each",
      type: "foreach",
      title: "Her öğe",
      over: "constants.names",
      concurrency: 3,
      steps: [textStep("say", "Say {{item}}.")],
    }], { constants: { names: ["a", "b", "c", "d"] } }),
    models: 2,
    settings: { maxConcurrentCalls: 2 },
    rules: [{ match: "Say", response: { text: "said", delayMs: 250 } }],
  });
  try {
    const exit = await waitForExit(launch(jobPath));
    const state = await readJson(statePath);
    const observed = await calls(fakeDirectory);

    assert.deepEqual(exit, { code: 0, signal: null });
    assert.equal(state.status, "completed");
    assert.equal(observed.length, 8);
    assert.equal(Math.max(...observed.map((call) => call.concurrent)), 2);
  } finally {
    await rm(base, { recursive: true, force: true });
  }
});

test("fails code_agent steps for Phase 2 and skips the steps that depend on them", async () => {
  const { base, fakeDirectory, runDirectory, jobPath, models, statePath } = await fixture({
    template: flowTemplate([
      { id: "build", type: "code_text", title: "Demo", modes: ["code_text", "code_agent"], prompt: "Build a demo." },
      textStep("review", "Review {{steps.build.output.file}}."),
      textStep("notes", "Write notes."),
    ]),
    settings: { stepModes: { build: "code_agent" } },
    rules: [{ match: "Write notes.", response: { text: "notes" } }],
  });
  const slug = models[0].slug;
  try {
    const exit = await waitForExit(launch(jobPath));
    const state = await readJson(statePath);
    const steps = state.models[slug].steps;

    assert.deepEqual(exit, { code: 0, signal: null });
    assert.equal(state.status, "partial");
    assert.equal(state.models[slug].status, "partial");
    assert.equal(steps.build.status, "failed");
    assert.equal(steps.build.message, "Bu adım türü (code_agent) Aşama 2'de etkinleşecek.");
    assert.equal(steps.review.status, "skipped");
    assert.equal(steps.review.message, "Bağlı olduğu adım (build) başarısız oldu.");
    assert.equal(steps.notes.status, "completed");
    assert.deepEqual((await calls(fakeDirectory)).map((call) => call.prompt), ["Write notes."]);
    assert.equal((await readJson(join(runDirectory, "models", slug, "steps/review/result.json"))).status, "skipped");
  } finally {
    await rm(base, { recursive: true, force: true });
  }
});

for (const [kind, change] of [
  ["an unrestricted model configuration", async ({ runDirectory, models }) => {
    const config = restrictiveConfig();
    config.agent["benchforge-workflow"].tools = { "*": true };
    await writeFile(join(runDirectory, "models", models[0].slug, "opencode.json"), JSON.stringify(config));
  }],
  ["a config file outside the run directory", async ({ base, jobPath, job }) => {
    await writeFile(join(base, "outside.json"), JSON.stringify(restrictiveConfig()));
    job.models[0].configFile = "../../outside.json";
    await writeFile(jobPath, JSON.stringify(job));
  }],
  ["a wrong run directory", async ({ base, jobPath, job }) => {
    job.runDirectory = base;
    await writeFile(jobPath, JSON.stringify(job));
  }],
  ["six models", async ({ jobPath, job }) => {
    job.models = Array.from({ length: 6 }, (_, index) => ({ ...job.models[0], slug: `or-model-${index}`, model: `openrouter/fixture/m${index}` }));
    await writeFile(jobPath, JSON.stringify(job));
  }],
  ["an unknown test hook", async ({ jobPath, job }) => {
    job.settings.testing.speed = 2;
    await writeFile(jobPath, JSON.stringify(job));
  }],
]) {
  test(`rejects a job with ${kind} before any model call`, async () => {
    const setup = await fixture({
      template: flowTemplate([textStep("write", "Write.")]),
      rules: [{ match: "Write.", response: { text: "done" } }],
    });
    try {
      await change(setup);
      const exit = await waitForExit(launch(setup.jobPath));
      const state = await readJson(setup.statePath);

      assert.equal(exit.code, 1);
      assert.equal(state.status, "failed");
      assert.equal(state.runId, RUN_ID);
      assert.equal(state.message, "İş akışı işi geçersiz; çalıştırma başlatılamadı.");
      assert.deepEqual(await calls(setup.fakeDirectory), []);
    } finally {
      await rm(setup.base, { recursive: true, force: true });
    }
  });
}

test("refuses to start outside the runner gate", async () => {
  const { base, jobPath, statePath } = await fixture({ template: flowTemplate([textStep("write", "Write.")]) });
  try {
    const child = spawn(process.execPath, [workerPath, jobPath], { env: { PATH: process.env.PATH }, stdio: "ignore" });
    assert.deepEqual(await waitForExit(child), { code: 64, signal: null });
    const extra = spawn(process.execPath, [workerPath, jobPath, "extra"], { env: { PATH: process.env.PATH, BENCHFORGE_RUNNER: "1" }, stdio: "ignore" });
    assert.deepEqual(await waitForExit(extra), { code: 64, signal: null });
    await assert.rejects(readFile(statePath, "utf8"), { code: "ENOENT" });
  } finally {
    await rm(base, { recursive: true, force: true });
  }
});

test("rotates events.jsonl once it reaches its size cap, keeping one older file", async () => {
  const root = await realpath(await mkdtemp(join(tmpdir(), "benchforge-workflow-events-")));
  try {
    const log = new EventLog({ root, path: join(root, "events.jsonl"), rotatedPath: join(root, "events.1.jsonl"), maxBytes: 400 });
    for (let index = 0; index < 12; index += 1) {
      void log.append({ type: "step_started", model: "or-a", step: "s", message: `Olay ${index} ${"x".repeat(40)}` });
    }
    await log.close();

    const current = (await readFile(join(root, "events.jsonl"), "utf8")).trim().split("\n").map((line) => JSON.parse(line));
    const rotated = (await readFile(join(root, "events.1.jsonl"), "utf8")).trim().split("\n").map((line) => JSON.parse(line));
    assert.ok((await stat(join(root, "events.1.jsonl"))).size >= 400);
    assert.ok((await stat(join(root, "events.jsonl"))).size < 400 + 200);
    assert.equal(current.at(-1).message.startsWith("Olay 11 "), true);
    assert.equal(rotated.at(-1).message.startsWith(`Olay ${11 - current.length} `), true);
    assert.equal(current.length + rotated.length < 12, true);
    assert.deepEqual(Object.keys(current[0]), ["time", "level", "type", "model", "step", "item", "message"]);
    assert.equal(current[0].item, null);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("coalesces scheduled state writes and flushes the latest snapshot at once", async () => {
  const root = await realpath(await mkdtemp(join(tmpdir(), "benchforge-workflow-state-")));
  try {
    const state = { status: "running", counter: 0, updatedAt: null };
    const writer = new RunStateWriter({ root, path: join(root, "state.json"), state, minIntervalMs: 300 });
    await writer.flush();
    for (let index = 1; index <= 50; index += 1) {
      state.counter = index;
      writer.schedule();
    }
    await sleep(100);
    assert.equal(writer.writes, 1);
    assert.equal((await readJson(join(root, "state.json"))).counter, 0);
    await sleep(350);
    assert.equal(writer.writes, 2);
    assert.equal((await readJson(join(root, "state.json"))).counter, 50);

    state.status = "completed";
    assert.equal(await writer.close(), true);
    const written = await readJson(join(root, "state.json"));
    assert.equal(written.status, "completed");
    assert.match(written.updatedAt, ISO_WITH_OFFSET);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("limits concurrency, ends waits on abort and keeps unknown usage unknown", async () => {
  const semaphore = new Semaphore(1);
  const release = await semaphore.acquire();
  const controller = new AbortController();
  const waiting = semaphore.acquire(controller.signal);
  controller.abort();
  assert.equal(await waiting, null);
  assert.equal(semaphore.waiting, 0);
  release();
  const next = await semaphore.acquire();
  assert.equal(semaphore.active, 1);
  next();

  const sleeper = new AbortController();
  const slept = sleep(10000, sleeper.signal);
  sleeper.abort();
  assert.equal(await slept, false);

  let running = 0;
  let peak = 0;
  await runPool([1, 2, 3, 4, 5], 2, async () => {
    running += 1;
    peak = Math.max(peak, running);
    await sleep(20);
    running -= 1;
  });
  assert.equal(peak, 2);

  const total = { inputTokens: null, outputTokens: null, costUsd: null, calls: 0 };
  mergeUsage(total, { inputTokens: 5, outputTokens: 2, costUsd: 0.1, calls: 1 });
  mergeUsage(total, { inputTokens: 5, outputTokens: null, costUsd: 0.2, calls: 1 });
  assert.deepEqual(total, { inputTokens: 10, outputTokens: null, costUsd: 0.3, calls: 2 });
  assert.match(isoTimestamp(new Date()), ISO_WITH_OFFSET);
});

test("runs a template whose empty objects PHP wrote as empty lists", async () => {
  const goodHtml = demoHtml("pointerdown");
  const { base, fakeDirectory, jobPath, models, statePath } = await fixture({
    template: flowTemplate([
      { id: "pick", type: "llm_json", title: "Seç", prompt: "Pick one.", retry: [], schema: { type: "object", properties: [] } },
      {
        id: "nested",
        type: "llm_json",
        title: "İç içe",
        prompt: "Nest values.",
        schema: { type: "object", required: ["list"], properties: { list: { type: "array", items: [] }, any: [] } },
      },
      { id: "build", type: "code_text", title: "Demo", prompt: "Build a demo.", checks: [], retry: [] },
    ], { constants: [] }),
    rules: [
      { match: "Pick one.", response: { text: fenced("json", '{"anything": 1}') } },
      { match: "Nest values.", response: { text: fenced("json", '{"list": [1, "a", {"b": true}], "any": {"x": 1}}') } },
      { match: "Build a demo.", response: { text: fenced("html", goodHtml) } },
    ],
  });
  const slug = models[0].slug;
  try {
    const exit = await waitForExit(launch(jobPath));
    const state = await readJson(statePath);
    const observed = await calls(fakeDirectory);
    const pickPrompt = observed.find((call) => call.prompt.startsWith("Pick one.")).prompt;
    const nestedPrompt = observed.find((call) => call.prompt.startsWith("Nest values.")).prompt;

    assert.deepEqual(exit, { code: 0, signal: null });
    assert.equal(state.status, "completed");
    assert.equal(observed.length, 3);
    assert.deepEqual(Object.values(state.models[slug].steps).map((step) => step.status), ["completed", "completed", "completed"]);
    assert.ok(pickPrompt.includes('"properties": {}'));
    assert.ok(nestedPrompt.includes('"items": {}'));
    assert.ok(nestedPrompt.includes('"any": {}'));
    for (const prompt of [pickPrompt, nestedPrompt]) assert.equal(prompt.includes("[]"), false);
  } finally {
    await rm(base, { recursive: true, force: true });
  }
});

for (const [kind, template] of [
  ["a non-empty list as retry", flowTemplate([textStep("write", "Write.", { retry: [1] })])],
  ["a non-empty list as constants", flowTemplate([textStep("write", "Write.")], { constants: ["a"] })],
  ["a non-empty list as an llm_json schema", flowTemplate([{ id: "pick", type: "llm_json", title: "Seç", prompt: "Write.", schema: [{ type: "object" }] }])],
  ["a title made only of ASCII whitespace", flowTemplate([textStep("write", "Write.", { title: " \t\n\r\x0B" })])],
  ["a prompt one code point over the limit", flowTemplate([textStep("write", `Write.${"😀".repeat(99995)}`)])],
]) {
  test(`still rejects a template with ${kind}`, async () => {
    const setup = await fixture({ template, rules: [{ match: "Write.", response: { text: "done" } }] });
    try {
      const exit = await waitForExit(launch(setup.jobPath));
      const state = await readJson(setup.statePath);

      assert.equal(exit.code, 1);
      assert.equal(state.status, "failed");
      assert.equal(state.runId, RUN_ID);
      assert.equal(state.message, "İş akışı işi geçersiz; çalıştırma başlatılamadı.");
      assert.deepEqual(await calls(setup.fakeDirectory), []);
    } finally {
      await rm(setup.base, { recursive: true, force: true });
    }
  });
}

test("measures lengths in code points and blank text like PHP trim, with large job and template files", async () => {
  const emojiValue = "😀".repeat(20000);
  const longPrompt = `LONG ${"😀".repeat(50000)}${"a".repeat(49995)}`;
  const inputs = Object.fromEntries(["first", "second", "third", "fourth"].map((id) => [id, emojiValue]));
  const template = flowTemplate([
    textStep("echo", "ECHO {{inputs.first}}", { title: " " }),
    textStep("long", longPrompt),
    {
      id: "each",
      type: "foreach",
      title: "Her öğe",
      over: "constants.names",
      itemLabel: "😀".repeat(500),
      steps: [textStep("say", "Say {{item}}.")],
    },
  ], {
    constants: { names: ["x"] },
    inputs: Object.keys(inputs).map((id) => ({ id, type: "text", label: id, maxLength: 20000 })),
  });
  const { base, fakeDirectory, runDirectory, jobPath, models, statePath } = await fixture({
    template,
    inputs,
    rules: [
      { match: "ECHO", response: { text: "echoed" } },
      { match: "LONG", response: { text: "long done" } },
      { match: "Say", response: { text: "said" } },
    ],
  });
  const templatePath = join(runDirectory, "template.json");
  // A hand-written file may escape every non-ASCII character; the raw file then outgrows PHP's 512 KB.
  await writeFile(templatePath, JSON.stringify(template, null, 2).replace(/[\u0080-￿]/g, (character) => `\\u${character.charCodeAt(0).toString(16).padStart(4, "0")}`));
  try {
    assert.equal(Array.from(longPrompt).length, 100000);
    assert.ok(longPrompt.length > 100000);
    assert.ok((await stat(jobPath)).size > 256 * 1024);
    assert.ok((await stat(templatePath)).size > 512 * 1024);

    const exit = await waitForExit(launch(jobPath));
    const state = await readJson(statePath);
    const observed = await calls(fakeDirectory);

    assert.deepEqual(exit, { code: 0, signal: null });
    assert.equal(state.status, "completed");
    assert.equal(observed.length, 3);
    assert.equal(observed.find((call) => call.prompt.startsWith("ECHO")).prompt, `ECHO ${emojiValue}`);
    assert.equal(observed.find((call) => call.prompt.startsWith("LONG")).prompt, longPrompt);
    const label = state.models[models[0].slug].steps.each.items[0].label;
    assert.ok(label.length <= 120 && label.isWellFormed() && label.startsWith("😀"));
  } finally {
    await rm(base, { recursive: true, force: true });
  }
});

test("keeps the tail of the model's last finished call as lastOutput", async () => {
  const answer = `${"😀".repeat(3000)}\n\`\`\`json\n{"name":"x"}\n\`\`\``;
  const schema = { type: "object", required: ["name"], properties: { name: { type: "string" } } };
  const { base, runDirectory, jobPath, models, statePath } = await fixture({
    template: flowTemplate([
      textStep("first", "First task."),
      {
        id: "each",
        type: "foreach",
        title: "Her öğe",
        over: "constants.names",
        itemLabel: "Ad {{item}}",
        steps: [{ id: "pick", type: "llm_json", title: "Seç", prompt: "Pick {{item}}.", schema }],
      },
    ], { constants: { names: ["x"] } }),
    rules: [
      { match: "First task.", response: { text: "first answer", delayMs: 500 } },
      { match: "Pick x.", response: { text: answer } },
    ],
  });
  const slug = models[0].slug;
  try {
    const child = launch(jobPath);
    const during = await waitFor(async () => {
      const state = await readJson(statePath);
      return state.models[slug].active.length === 1 ? state : null;
    });
    assert.equal(during.models[slug].lastOutput, null);

    const exit = await waitForExit(child);
    const state = await readJson(statePath);
    const { lastOutput } = state.models[slug];
    const expectedTail = Array.from(answer).slice(-4096).join("");

    assert.deepEqual(exit, { code: 0, signal: null });
    assert.equal(state.status, "completed");
    assert.match(lastOutput.finishedAt, ISO_WITH_OFFSET);
    assert.deepEqual(lastOutput, {
      step: "each", child: "pick", item: "001", label: "Ad x", status: "completed", finishedAt: lastOutput.finishedAt, tail: expectedTail,
    });
    assert.equal(answer.slice(-4096).isWellFormed(), false);
    assert.equal(lastOutput.tail.isWellFormed(), true);
    assert.deepEqual(state.models[slug].active, []);
    assert.deepEqual((await readJson(join(runDirectory, "models", slug, "summary.json"))).lastOutput, lastOutput);
  } finally {
    await rm(base, { recursive: true, force: true });
  }
});

test("shows a rejected answer as a failed lastOutput", async () => {
  const schema = { type: "object", required: ["answer"], properties: { answer: { type: "string" } } };
  const { base, jobPath, models, statePath } = await fixture({
    template: flowTemplate([
      textStep("first", "First task."),
      { id: "answer", type: "llm_json", title: "Cevap", prompt: "Give the answer.", schema, retry: { attempts: 1 } },
    ]),
    rules: [
      { match: "First task.", response: { text: "first answer" } },
      { match: "Give the answer.", response: { text: "This is not JSON at all." } },
    ],
  });
  const slug = models[0].slug;
  try {
    const exit = await waitForExit(launch(jobPath));
    const state = await readJson(statePath);
    const { lastOutput } = state.models[slug];

    assert.deepEqual(exit, { code: 0, signal: null });
    assert.equal(state.models[slug].steps.answer.status, "failed");
    assert.match(lastOutput.finishedAt, ISO_WITH_OFFSET);
    assert.deepEqual(lastOutput, {
      step: "answer", child: null, item: null, label: null, status: "failed", finishedAt: lastOutput.finishedAt, tail: "This is not JSON at all.",
    });
  } finally {
    await rm(base, { recursive: true, force: true });
  }
});

// The real OpenCode Go refusal from runs/plush-octopus-v2/_logs/go-gemini-4-argon-contributor-5cbf1a.opencode.events.jsonl.
const GO_PRIVACY_REFUSAL = JSON.parse(String.raw`{"type":"error","timestamp":1792087229006,"sessionID":"ses_ebf4703bcffe9MlbN2w6FPp2BB","error":{"name":"APIError","data":{"message":"Upstream request failed: This Go model trains on request data. Allow paid endpoints that train on request data in your workspace's Privacy settings to use it.","statusCode":400,"isRetryable":false,"responseHeaders":{"cf-ray":"a4b0b93beea9b26e-PIT","connection":"keep-alive","content-length":"212","content-type":"application/json","date":"Thu, 15 Oct 2026 18:00:28 GMT","server":"cloudflare","x-opencode-log-id":"6c416b05-df2c-4ca7-bdea-e61a3bbc3f3c"},"responseBody":"{\"error\":{\"code\":400,\"status\":\"UNKNOWN\",\"message\":\"Upstream request failed: This Go model trains on request data. Allow paid endpoints that train on request data in your workspace's Privacy settings to use it.\"}}","metadata":{"url":"https://opencode.ai/inference/go/v1/models/gemini-4-argon-contributor:streamGenerateContent?alt=sse"}}}}`);
const POLICY_MESSAGE = "Sağlayıcı bu modeli hesabınızın gizlilik ayarı nedeniyle reddetti (model istek verisiyle eğitim yapıyor). OpenCode Console veya OpenRouter gizlilik ayarını kontrol edin.";
const OUTPUT_LIMIT_MESSAGE = "Çıktı token sınırına ulaşıldı; yanıt kesildi.";
const PERSISTENT_MESSAGE = "Sağlayıcı bu modele sürekli geçici hata döndürüyor; model durduruldu.";
const TRANSIENT_MESSAGE = "Sağlayıcı geçici bir hata verdi (istek sınırı veya sunucu hatası).";
const UNEXPECTED_SERVER_ERROR = { name: "UnknownError", data: { message: "Unexpected server error. Check server logs for details." } };

// step_finish parts of real OpenCode 1.19 logs: plush-octopus-v2/_attempts/go-claude-haiku-5-5-a7da46/attempt-2 (stop),
// kurtulus-savasi/_logs/go-qwen38-flash-35538a (length, output at the cap) and go-mimo-v26-pro-d284f8 (length, all reasoning).
const STOP_FINISH = {"id":"prt_14213f5df0013dyXxK2G6zqnWx","reason":"stop","messageID":"msg_14213e512001jWd8HwPkZDFpl4","sessionID":"ses_ebdec6920ffetX1PPnt1Bvk5MV","type":"step-finish","tokens":{"total":85155,"input":2,"output":603,"reasoning":151,"cache":{"write":1353,"read":83046}},"cost":0.001376785};
const LENGTH_FINISH = {"id":"prt_137c21e10001T16l2pmh3wBOp1","reason":"length","messageID":"msg_137b99f85001cUUliV8DoNXVp3","sessionID":"ses_ec847168affevSC9U6rESHT87S","type":"step-finish","tokens":{"total":75578,"input":6,"output":32000,"reasoning":0,"cache":{"write":5005,"read":38567}},"cost":0.016658972};
const REASONING_LENGTH_FINISH = {"id":"prt_137a73be1001s9xJ8laeAp4ZIK","reason":"length","messageID":"msg_137a3c72d001m7EPNrclxeEYkX","sessionID":"ses_ec85d0dd6ffeX5BjbAeG89J6QO","type":"step-finish","tokens":{"total":84353,"input":1281,"output":0,"reasoning":32002,"cache":{"write":0,"read":51072}},"cost":0.028584111};

test("fails only the model the provider refuses under the account privacy setting", async () => {
  const { base, fakeDirectory, runDirectory, jobPath, models, statePath } = await fixture({
    template: flowTemplate([textStep("write", "Write a haiku."), textStep("again", "Write another.")]),
    models: 2,
    rules: [
      { match: "Write a haiku.", model: "alpha", response: { error: GO_PRIVACY_REFUSAL.error } },
      { match: "Write a haiku.", response: { text: "Old pond.", delayMs: 400 } },
      { match: "Write another.", response: { text: "Frog jumps." } },
    ],
  });
  const [alpha, beta] = models;
  try {
    const exit = await waitForExit(launch(jobPath));
    const state = await readJson(statePath);
    const observed = await calls(fakeDirectory);
    const result = await readJson(join(runDirectory, "models", alpha.slug, "steps/write/result.json"));

    assert.deepEqual(exit, { code: 0, signal: null });
    assert.equal(state.status, "partial");
    assert.equal(state.message, "Bazı modeller veya adımlar tamamlanamadı.");
    assert.equal(state.models[alpha.slug].status, "failed");
    assert.equal(state.models[alpha.slug].message, POLICY_MESSAGE);
    assert.equal(state.models[alpha.slug].steps.write.status, "failed");
    assert.equal(state.models[alpha.slug].steps.write.message, POLICY_MESSAGE);
    assert.equal(state.models[alpha.slug].steps.again.status, "pending");
    assert.deepEqual(result.attempts.map((attempt) => [attempt.status, attempt.error]), [["failed", POLICY_MESSAGE]]);
    assert.equal(state.models[beta.slug].status, "completed");
    assert.deepEqual(Object.values(state.models[beta.slug].steps).map((step) => step.status), ["completed", "completed"]);
    assert.equal(observed.filter((call) => call.model === alpha.model).length, 1);
    assert.equal(observed.filter((call) => call.model === beta.model).length, 2);
  } finally {
    await rm(base, { recursive: true, force: true });
  }
});

test("fails a text attempt that hit the output token limit and counts cache and reasoning tokens", async () => {
  const { base, runDirectory, jobPath, models, statePath } = await fixture({
    template: flowTemplate([textStep("story", "Write a story.", { retry: { attempts: 3 } })]),
    rules: [{
      match: "Write a story.",
      responses: [
        { finish: REASONING_LENGTH_FINISH },
        { text: "Once upon a", finish: LENGTH_FINISH },
        { text: "Once upon a time.", finish: STOP_FINISH },
      ],
    }],
  });
  const slug = models[0].slug;
  try {
    const exit = await waitForExit(launch(jobPath));
    const state = await readJson(statePath);
    const result = await readJson(join(runDirectory, "models", slug, "steps/story/result.json"));

    assert.deepEqual(exit, { code: 0, signal: null });
    assert.equal(state.status, "completed");
    assert.equal(result.output, "Once upon a time.");
    assert.deepEqual(result.attempts.map((attempt) => [attempt.kind, attempt.status, attempt.stopReason, attempt.error]), [
      ["initial", "failed", "length", OUTPUT_LIMIT_MESSAGE],
      ["retry", "failed", "length", OUTPUT_LIMIT_MESSAGE],
      ["retry", "completed", "stop", null],
    ]);
    assert.deepEqual(result.attempts.map((attempt) => [attempt.usage.inputTokens, attempt.usage.outputTokens, attempt.usage.costUsd]), [
      [1281 + 51072, 32002, 0.028584111],
      [6 + 38567 + 5005, 32000, 0.016658972],
      [2 + 83046 + 1353, 603 + 151, 0.001376785],
    ]);
    assert.equal(result.usage.inputTokens, 52353 + 43578 + 84401);
    assert.equal(result.usage.outputTokens, 32002 + 32000 + 754);
    assert.ok(Math.abs(result.usage.costUsd - 0.046619868) < 1e-9);
    assert.deepEqual(state.models[slug].usage, result.usage);
    const rejected = (await events(runDirectory)).filter((event) => event.type === "attempt_failed");
    assert.equal(rejected.length, 2);
    assert.ok(rejected.every((event) => event.message.includes(OUTPUT_LIMIT_MESSAGE)));
  } finally {
    await rm(base, { recursive: true, force: true });
  }
});

test("rejects JSON cut off at the output token limit and asks for a shorter answer", async () => {
  const schema = { type: "object", required: ["answer"], properties: { answer: { type: "string" } } };
  const cutOff = '```json\n{"answer": "a very long answer that was cut';
  const { base, fakeDirectory, runDirectory, jobPath, models, statePath } = await fixture({
    template: flowTemplate([{ id: "answer", type: "llm_json", title: "Cevap", prompt: "Give the answer.", schema }]),
    rules: [
      { match: "YOUR PREVIOUS ANSWER WAS REJECTED", response: { text: fenced("json", '{"answer":"short"}') } },
      { match: "Give the answer.", response: { text: cutOff, finish: LENGTH_FINISH } },
    ],
  });
  try {
    const exit = await waitForExit(launch(jobPath));
    const state = await readJson(statePath);
    const observed = await calls(fakeDirectory);
    const result = await readJson(join(runDirectory, "models", models[0].slug, "steps/answer/result.json"));

    assert.deepEqual(exit, { code: 0, signal: null });
    assert.equal(state.status, "completed");
    assert.equal(observed.length, 2);
    assert.deepEqual(result.output, { answer: "short" });
    assert.deepEqual(result.attempts.map((attempt) => [attempt.kind, attempt.status, attempt.stopReason]), [
      ["initial", "failed", "length"], ["retry", "completed", "stop"],
    ]);
    assert.equal(result.attempts[0].error, OUTPUT_LIMIT_MESSAGE);
    assert.equal(result.attempts[0].files.output, null);
    assert.ok(observed[1].prompt.startsWith("Give the answer.\n\nOUTPUT FORMAT (mandatory):\n"));
    assert.ok(observed[1].prompt.includes("YOUR PREVIOUS ANSWER WAS REJECTED.\nProblem: Your previous answer was cut off because it reached the output token limit. Keep any reasoning brief and return a shorter answer that fits within the limit.\n"));
    assert.ok(observed[1].prompt.includes(`Your previous answer (truncated):\n${cutOff}\nReturn the corrected JSON now.`));
  } finally {
    await rm(base, { recursive: true, force: true });
  }
});

test("treats a demo cut off at the output token limit as truncated and asks for a smaller one", async () => {
  const goodHtml = demoHtml("pointerdown");
  const cutHtml = goodHtml.slice(0, goodHtml.indexOf("</body>"));
  const { base, fakeDirectory, runDirectory, jobPath, models, statePath } = await fixture({
    template: flowTemplate([
      { id: "build", type: "code_text", title: "Demo", prompt: "Build a demo." },
      { id: "rebuild", type: "code_text", title: "Demo 2", prompt: "Build another demo." },
    ]),
    rules: [
      { match: "YOUR PREVIOUS ANSWER HAD THESE PROBLEMS", response: { text: fenced("html", goodHtml) } },
      { match: "Build a demo.", response: { text: fenced("html", goodHtml), finish: LENGTH_FINISH } },
      { match: "Build another demo.", response: { text: fenced("html", cutHtml), finish: LENGTH_FINISH } },
    ],
  });
  const slug = models[0].slug;
  try {
    const exit = await waitForExit(launch(jobPath));
    const state = await readJson(statePath);
    const observed = await calls(fakeDirectory);
    const stepDirectory = (id) => join(runDirectory, "models", slug, "steps", id);
    const build = await readJson(join(stepDirectory("build"), "result.json"));
    const rebuild = await readJson(join(stepDirectory("rebuild"), "result.json"));

    assert.deepEqual(exit, { code: 0, signal: null });
    assert.equal(state.status, "completed");
    assert.equal(observed.length, 4);
    assert.deepEqual(build.attempts.map((attempt) => [attempt.kind, attempt.status, attempt.stopReason]), [
      ["initial", "failed", "length"], ["repair", "completed", "stop"],
    ]);
    assert.equal(build.attempts[0].error, "Statik kontrol: Çıktı token sınırına ulaşıldı; demo kesildi.");
    assert.deepEqual((await readJson(join(stepDirectory("build"), "attempt-1/checks.json"))).critical, [
      { code: "output-limit", message: "Çıktı token sınırına ulaşıldı; demo kesildi." },
    ]);
    const buildRepair = await readFile(join(stepDirectory("build"), "attempt-2/prompt.txt"), "utf8");
    assert.ok(buildRepair.includes("- Your answer reached the output token limit and was cut off.\n"));
    assert.ok(buildRepair.includes("Your answer was cut off. Write a smaller version that fits.\n"));
    assert.equal(buildRepair.includes("Previous HTML:"), false);

    assert.deepEqual(rebuild.attempts.map((attempt) => [attempt.kind, attempt.status]), [["initial", "failed"], ["repair", "completed"]]);
    const rebuildChecks = await readJson(join(stepDirectory("rebuild"), "attempt-1/checks.json"));
    assert.deepEqual(rebuildChecks.critical.map((issue) => issue.code), ["missing-html"]);
    assert.ok((await readFile(join(stepDirectory("rebuild"), "attempt-2/prompt.txt"), "utf8")).includes("Your answer was cut off. Write a smaller version that fits.\n"));
  } finally {
    await rm(base, { recursive: true, force: true });
  }
});

test("gives up a model after two calls in a row run out of waits for an unexpected server error", async () => {
  const { base, fakeDirectory, runDirectory, jobPath, models, statePath } = await fixture({
    template: flowTemplate([textStep("write", "Write a haiku.", { retry: { attempts: 3 } }), textStep("again", "Write another.")]),
    models: 2,
    rules: [
      { match: "Write a haiku.", model: "alpha", response: { error: UNEXPECTED_SERVER_ERROR } },
      { match: "Write", response: { text: "Old pond." } },
    ],
  });
  const [alpha, beta] = models;
  try {
    const exit = await waitForExit(launch(jobPath));
    const state = await readJson(statePath);
    const observed = await calls(fakeDirectory);
    const result = await readJson(join(runDirectory, "models", alpha.slug, "steps/write/result.json"));
    const log = await events(runDirectory);

    assert.deepEqual(exit, { code: 0, signal: null });
    assert.equal(state.status, "partial");
    assert.equal(state.models[alpha.slug].status, "failed");
    assert.equal(state.models[alpha.slug].message, PERSISTENT_MESSAGE);
    assert.equal(state.models[alpha.slug].steps.write.status, "failed");
    assert.equal(state.models[alpha.slug].steps.write.message, PERSISTENT_MESSAGE);
    assert.equal(state.models[alpha.slug].steps.again.status, "pending");
    assert.equal(observed.filter((call) => call.model === alpha.model).length, 6);
    assert.deepEqual(result.attempts.map((attempt) => attempt.kind), ["initial", "transient", "transient", "retry", "transient", "transient"]);
    assert.ok(result.attempts.every((attempt) => attempt.status === "failed" && attempt.error === TRANSIENT_MESSAGE));
    const waits = log.filter((event) => event.type === "rate_limited" && event.model === alpha.slug);
    assert.deepEqual(waits.map((event) => /\((\d\/\d)\)\.$/.exec(event.message)[1]), ["1/2", "2/2", "1/2", "2/2"]);
    assert.equal(log.find((event) => event.type === "model_finished" && event.model === alpha.slug).message, PERSISTENT_MESSAGE);
    assert.equal(state.models[beta.slug].status, "completed");
  } finally {
    await rm(base, { recursive: true, force: true });
  }
});

test("starts the count of exhausted calls over after a completed call of the model", async () => {
  const { base, fakeDirectory, jobPath, models, statePath } = await fixture({
    template: flowTemplate([
      textStep("first", "First task.", { retry: { attempts: 1 } }),
      textStep("second", "Second task."),
      textStep("third", "Third task.", { retry: { attempts: 1 } }),
      textStep("fourth", "Fourth task."),
    ]),
    rules: [
      { match: "First task.", response: { error: UNEXPECTED_SERVER_ERROR } },
      { match: "Third task.", response: { error: UNEXPECTED_SERVER_ERROR } },
      { match: "task.", response: { text: "done" } },
    ],
  });
  const slug = models[0].slug;
  try {
    const exit = await waitForExit(launch(jobPath));
    const state = await readJson(statePath);
    const steps = state.models[slug].steps;

    assert.deepEqual(exit, { code: 0, signal: null });
    assert.equal(state.models[slug].status, "partial");
    assert.equal(state.models[slug].message, "Bazı adımlar tamamlanamadı.");
    assert.deepEqual(Object.values(steps).map((step) => [step.status, step.message]), [
      ["failed", TRANSIENT_MESSAGE], ["completed", null], ["failed", TRANSIENT_MESSAGE], ["completed", null],
    ]);
    assert.equal((await calls(fakeDirectory)).length, 3 + 1 + 3 + 1);
  } finally {
    await rm(base, { recursive: true, force: true });
  }
});

test("keeps six waits per call for rate limits", async () => {
  const { base, fakeDirectory, runDirectory, jobPath, models, statePath } = await fixture({
    template: flowTemplate([textStep("write", "Write a haiku.", { retry: { attempts: 1 } })]),
    rules: [{ match: "Write a haiku.", response: { error: { name: "APIError", data: { statusCode: 429, isRetryable: true, message: "Rate limit exceeded" } } } }],
  });
  const slug = models[0].slug;
  try {
    const exit = await waitForExit(launch(jobPath));
    const state = await readJson(statePath);
    const waits = (await events(runDirectory)).filter((event) => event.type === "rate_limited");

    assert.deepEqual(exit, { code: 0, signal: null });
    assert.equal((await calls(fakeDirectory)).length, 7);
    assert.deepEqual(waits.map((event) => /\((\d\/\d)\)\.$/.exec(event.message)[1]), ["1/6", "2/6", "3/6", "4/6", "5/6", "6/6"]);
    assert.match(waits.at(-1).message, /300 sn sonra/);
    assert.equal(state.models[slug].steps.write.status, "failed");
    assert.equal(state.models[slug].steps.write.message, TRANSIENT_MESSAGE);
    assert.equal(state.models[slug].status, "failed");
    assert.equal(state.models[slug].message, "Hiçbir adım tamamlanamadı.");
  } finally {
    await rm(base, { recursive: true, force: true });
  }
});
