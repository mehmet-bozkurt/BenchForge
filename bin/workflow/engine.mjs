// Workflow execution (contract §4-§7): models in a sliding window, top-level steps in order with
// dependency skipping, foreach expansion with item concurrency, one global limit on OpenCode calls,
// llm_text / llm_json / code_text attempts with retries and repairs, provider error handling and stop.
// Every file it writes stays inside the run directory; state.json is the only progress channel to PHP.
import { lstat, mkdir } from "node:fs/promises";
import { join, sep } from "node:path";
import { performance } from "node:perf_hooks";
import { isUnexpectedServerError, providerErrorKind } from "../../engine/tools/classify-opencode-failure.mjs";
import {
  LOOKUP_RETRY_DELAYS_MS,
  LIMIT_MESSAGE,
  OUTPUT_LIMIT_MESSAGE,
  POLICY_REFUSAL_MESSAGE,
  STARTUP_RETRY_DELAYS_MS,
  lookupModelLimit,
  runOpenCodeText,
  withOutputCap,
} from "../opencode-text.mjs";
import { atomicWrite, withinRoot } from "../private-files.mjs";
import { checkDemoHtml, demoRepairSuffix, extractHtml } from "./html-demo.mjs";
import { WORKFLOW_AGENT, loadWorkflowJob, readRunIdQuietly, resolveRunDirectory } from "./job.mjs";
import { clipText, jsonOutputRules, jsonRetrySuffix, parseModelJson, validateJsonSchema } from "./json-output.mjs";
import { Semaphore, childController, runPool, sleep } from "./limiter.mjs";
import {
  EventLog, RunStateWriter, STATE_SCHEMA, callUsage, emptyUsage, isoTimestamp, mergeUsage, secondsFromMs,
} from "./run-state.mjs";
import { findPlaceholders, parsePath, renderTemplate, resolvePath } from "./template-path.mjs";

export const RESULT_SCHEMA = "benchforge-workflow-result/1";
export const TRANSIENT_WAIT_SECONDS = Object.freeze([15, 30, 60, 120, 240, 300]);
/** Waits per call for OpenCode's bare "Unexpected server error", which can persist for a model. */
export const UNEXPECTED_SERVER_ERROR_WAITS = 2;
/** Calls in a row whose transient waits ran out before a model is given up. */
export const PERSISTENT_TRANSIENT_CALLS = 2;
const MAX_PROMPT_BYTES = 262144;
const MIN_EVENT_BYTES = 2 * 1024 * 1024;
const TAIL_CHARS = 4096;
const LABEL_CHARS = 120;
const MAX_FOREACH_ITEMS = 500;
const DEFAULT_ITEM_LABEL = "Öğe {{loop.number}}";
const PHASE_SKIPPED = "phase-skipped";
const OUTPUT_LIMIT_PROBLEM = "Your previous answer was cut off because it reached the output token limit. "
  + "Keep any reasoning brief and return a shorter answer that fits within the limit.";
const OUTPUT_LIMIT_ISSUE = Object.freeze({
  code: "output-limit",
  message: "Çıktı token sınırına ulaşıldı; demo kesildi.",
  detail: "Your answer reached the output token limit and was cut off.",
});

/** Defaults per step type (contract §1.2). code_text tries once per failure budget, plus repairAttempts. */
const STEP_DEFAULTS = Object.freeze({
  llm_text: { timeoutSeconds: 600, attempts: 2, backoffSeconds: 10, maxOutputChars: 100000, maxOutputTokens: 32000 },
  llm_json: { timeoutSeconds: 600, attempts: 3, backoffSeconds: 10, maxOutputChars: 100000, maxOutputTokens: 32000 },
  code_text: { timeoutSeconds: 1200, attempts: 1, backoffSeconds: 10, maxOutputChars: 400000, maxOutputTokens: 100000 },
  code_agent: { timeoutSeconds: 1200, attempts: 1, backoffSeconds: 10, maxOutputChars: 400000, maxOutputTokens: 100000 },
});

export const MESSAGES = Object.freeze({
  stopping: "Çalıştırma durduruluyor.",
  stopped: "Çalıştırma durduruldu.",
  account: "Sağlayıcı hesabı isteği reddetti (anahtar, abonelik veya kullanım sınırı). Anahtarı, aboneliği ve sınırı kontrol edin.",
  unavailable: "Model sağlayıcıda bulunamadı veya kaldırıldı.",
  limit: LIMIT_MESSAGE,
  policy: POLICY_REFUSAL_MESSAGE,
  persistentTransient: "Sağlayıcı bu modele sürekli geçici hata döndürüyor; model durduruldu.",
  outputLimit: OUTPUT_LIMIT_MESSAGE,
  codeAgent: "Bu adım türü (code_agent) Aşama 2'de etkinleşecek.",
  browserCheck: "Tarayıcı testi Aşama 2'de etkinleşecek.",
  promptTooLarge: "Yerleştirilmiş prompt 256 KB sınırını aşıyor.",
  invalidJob: "İş akışı işi geçersiz; çalıştırma başlatılamadı.",
  crash: "İş akışı işçisi beklenmedik bir hatayla durdu.",
  noResponse: "Model yanıtı alınamadı.",
});

function stepSettings(step, settings) {
  const defaults = STEP_DEFAULTS[step.type] ?? STEP_DEFAULTS.llm_text;
  const isCode = step.type === "code_text" || step.type === "code_agent";
  const runTimeout = isCode ? settings.timeouts.code : settings.timeouts.llm;

  return {
    timeoutSeconds: settings.timeoutOverride ?? runTimeout ?? step.timeoutSeconds ?? defaults.timeoutSeconds,
    attempts: step.retry?.attempts ?? defaults.attempts,
    backoffSeconds: step.retry?.backoffSeconds ?? defaults.backoffSeconds,
    maxOutputChars: step.maxOutputChars ?? defaults.maxOutputChars,
    maxOutputTokens: step.maxOutputTokens ?? defaults.maxOutputTokens,
    repairAttempts: step.repairAttempts ?? 2,
  };
}

function codeMode(step, stepModes) {
  if (step.type === "code_agent") return "code_agent";
  if (step.type !== "code_text") return null;
  if (Object.hasOwn(stepModes, step.id)) return stepModes[step.id];

  return Array.isArray(step.modes) && step.modes.length > 0 ? step.modes[0] : "code_text";
}

/** Ids of top-level steps that a template text refers to through `steps.<id>`. */
function referencedSteps(texts, paths) {
  const ids = [];
  const add = (parsed) => {
    const first = parsed.segments[0];
    if (parsed.root === "steps" && first?.type === "field" && !ids.includes(first.name)) ids.push(first.name);
  };

  for (const text of texts) {
    if (typeof text !== "string") continue;
    for (const path of findPlaceholders(text).paths) {
      try { add(parsePath(path)); } catch { /* the template validator reported it */ }
    }
  }
  for (const path of paths) {
    try { add(parsePath(path)); } catch { /* idem */ }
  }

  return ids;
}

/** Explicit `dependsOn` plus every earlier top-level step a step (or its children) refers to. */
function topLevelDependencies(template) {
  const dependencies = new Map();
  const earlier = new Set();

  for (const step of template.steps) {
    const texts = [];
    const paths = [];
    if (step.type === "foreach") {
      paths.push(step.over);
      texts.push(step.itemLabel);
      for (const child of step.steps) texts.push(child.prompt);
      if (template.gallery?.from === step.id) texts.push(template.gallery.title);
    } else {
      texts.push(step.prompt);
    }

    const ids = [...(step.dependsOn ?? [])];
    for (const id of referencedSteps(texts, paths)) {
      if (earlier.has(id) && !ids.includes(id)) ids.push(id);
    }
    dependencies.set(step.id, ids);
    earlier.add(step.id);
  }

  return dependencies;
}

function emptyCounts() {
  return { total: 0, completed: 0, failed: 0, running: 0, skipped: 0, stopped: 0 };
}

function countItems(items) {
  const counts = emptyCounts();
  counts.total = items.length;
  for (const item of items) {
    if (Object.hasOwn(counts, item.status) && item.status !== "total") counts[item.status] += 1;
  }

  return counts;
}

function stepEntry(step) {
  const entry = {
    status: "pending",
    message: null,
    startedAt: null,
    finishedAt: null,
    durationSeconds: null,
    attempts: 0,
    usage: emptyUsage(),
  };
  if (step.type === "foreach") {
    entry.items = [];
    entry.counts = emptyCounts();
  }

  return entry;
}

function initialState(job) {
  const now = isoTimestamp();
  const models = {};

  for (const model of job.models) {
    const steps = {};
    for (const step of job.template.steps) steps[step.id] = stepEntry(step);
    models[model.slug] = {
      model: model.model,
      name: model.name,
      status: "pending",
      message: null,
      startedAt: null,
      finishedAt: null,
      durationSeconds: null,
      usage: emptyUsage(),
      items: { total: 0, completed: 0, failed: 0 },
      steps,
      active: [],
      lastOutput: null,
    };
  }

  return {
    schema: STATE_SCHEMA,
    runId: job.runId,
    pid: process.pid,
    status: "running",
    message: null,
    startedAt: now,
    updatedAt: now,
    finishedAt: null,
    models,
  };
}

/** Creates a directory and its missing parents inside the run directory, refusing symlinks on the way. */
async function ensureDirectory(root, path, mode = 0o755) {
  const parts = withinRoot(root, path).split(sep);
  let cursor = root;

  for (const part of parts) {
    cursor = join(cursor, part);
    try {
      await mkdir(cursor, { mode });
    } catch (error) {
      if (error.code !== "EEXIST") throw error;
    }
    const info = await lstat(cursor);
    if (info.isSymbolicLink() || !info.isDirectory()) {
      throw new Error("Unsafe directory in the run directory");
    }
  }
}

function jsonText(value) {
  return `${JSON.stringify(value, null, 2)}\n`;
}

/**
 * The last TAIL_CHARS code points of a text. Never starts inside a surrogate pair: a lone surrogate
 * would be written as a "\udXXX" escape, which PHP's json_decode rejects, making state.json unreadable.
 */
function tail(text) {
  if (text.length <= TAIL_CHARS) return text;

  return Array.from(text.slice(-2 * TAIL_CHARS)).slice(-TAIL_CHARS).join("");
}

function label(text, fallback) {
  const clean = String(text ?? "").replace(/\s+/g, " ").trim();
  return clean === "" ? fallback : clipText(clean, LABEL_CHARS);
}

function publicIssues(issues) {
  return issues.map(({ code, message }) => ({ code, message }));
}

function isUsable(status) {
  return status === "completed" || status === PHASE_SKIPPED;
}

/**
 * Usage of one call as the workflow counts it: input with the cached prompt tokens (read and written)
 * and output with the reasoning tokens, as OpenCode's step total does. A missing reasoning or cache count
 * adds nothing; a missing input or output count stays unknown.
 */
function workflowCallUsage(result) {
  const withParts = (base, ...parts) => (Number.isSafeInteger(base)
    ? parts.reduce((sum, value) => sum + (Number.isSafeInteger(value) ? value : 0), base)
    : null);

  return callUsage({
    inputTokens: withParts(result.inputTokens, result.cacheReadTokens, result.cacheWriteTokens),
    outputTokens: withParts(result.outputTokens, result.reasoningTokens),
    costUsd: result.costUsd,
  });
}

/** One model's pass through the template. */
class ModelRun {
  constructor(engine, spec) {
    this.spec = spec;
    this.slug = spec.slug;
    this.entry = engine.state.models[spec.slug];
    this.inputs = engine.job.inputs;
    this.constants = engine.template.constants ?? {};
    this.controller = childController(engine.runController.signal);
    this.fatal = null;
    this.outputs = {};
    this.usable = new Map();
    this.phaseSkipped = new Set();
    this.startedAt = null;
    this.exhaustedTransientCalls = 0;
    /** Promise of the model's real token limits (null until the first call asks for them). */
    this.modelLimit = null;
    this.limitWarned = false;
  }

  get signal() {
    return this.controller.signal;
  }

  context(extra = {}) {
    return { inputs: this.inputs, constants: this.constants, steps: this.outputs, ...extra };
  }
}

export class WorkflowEngine {
  constructor(job) {
    this.job = job;
    this.root = job.root;
    this.template = job.template;
    this.settings = job.settings;
    this.state = initialState(job);
    this.writer = new RunStateWriter({ root: this.root, path: join(this.root, "state.json"), state: this.state });
    this.events = new EventLog({
      root: this.root,
      path: join(this.root, "events.jsonl"),
      rotatedPath: join(this.root, "events.1.jsonl"),
    });
    this.runController = new AbortController();
    this.calls = new Semaphore(this.settings.maxConcurrentCalls);
    this.dependencies = topLevelDependencies(this.template);
    this.stopRequested = false;
    this.fatal = null;
    this.crashed = null;
    this.terminal = false;
  }

  event(level, type, { model = null, step = null, item = null, message = "" } = {}) {
    void this.events.append({ level, type, model, step, item, message });
  }

  /** SIGTERM / SIGINT / SIGHUP: write "stopping" at once, end every call and wait, keep pending work pending. */
  requestStop() {
    if (this.stopRequested || this.terminal) return;
    this.stopRequested = true;

    if (this.fatal === null) {
      this.state.status = "stopping";
      this.state.message = MESSAGES.stopping;
    }
    void this.writer.flush();
    this.event("warn", "stop_requested", { message: "Durdurma istendi; etkin model çağrıları sonlandırılıyor." });
    this.runController.abort();
  }

  /** An account error (or a worker crash) ends the whole run as failed. */
  failRun(message) {
    if (this.fatal !== null) return;
    this.fatal = { message };
    this.state.message = message;
    void this.writer.flush();
    this.runController.abort();
  }

  /** A model the provider does not know (any more), refuses or keeps failing for fails; the other models continue. */
  failModel(run, message) {
    if (run.fatal !== null || this.fatal !== null) return;
    run.fatal = { message };
    run.entry.message = message;
    void this.writer.flush();
    run.controller.abort();
  }

  /** How work that was interrupted ends: failed with the fatal message, or stopped. */
  haltOf(run) {
    if (this.fatal !== null) return { status: "failed", message: this.fatal.message };
    if (run.fatal !== null) return { status: "failed", message: run.fatal.message };

    return { status: "stopped", message: MESSAGES.stopped };
  }

  async run() {
    await this.writer.flush();
    this.event("info", "run_started", { message: `Çalıştırma başladı (${this.job.models.length} model).` });

    try {
      for (const model of this.job.models) {
        await ensureDirectory(this.root, model.directory);
        await ensureDirectory(this.root, model.workspace, 0o700);
        await ensureDirectory(this.root, join(model.privateHome, ".config"), 0o700);
      }

      await runPool(this.job.models, this.settings.maxParallelModels, async (spec) => {
        try {
          await this.runModel(spec);
        } catch (error) {
          this.crash(error);
        }
      }, () => !this.runController.signal.aborted);
    } catch (error) {
      this.crash(error);
    }

    await this.finish();

    return this.crashed === null ? 0 : 1;
  }

  crash(error) {
    this.crashed ??= error;
    this.failRun(MESSAGES.crash);
  }

  async runModel(spec) {
    const run = new ModelRun(this, spec);
    run.startedAt = performance.now();
    run.entry.status = "running";
    run.entry.startedAt = isoTimestamp();
    void this.writer.flush();
    this.event("info", "model_started", { model: run.slug, message: `${spec.name} çalışmaya başladı.` });

    try {
      for (const step of this.template.steps) {
        if (run.signal.aborted) break;

        const blocker = (this.dependencies.get(step.id) ?? []).find((id) => run.usable.get(id) !== true);
        if (blocker !== undefined) {
          await this.skipStep(run, step, run.entry.steps[step.id], null, join(spec.directory, "steps", step.id), blocker);
          run.usable.set(step.id, false);
          continue;
        }

        if (step.type === "foreach") {
          await this.runForeach(run, step);
        } else {
          await this.runTopLevelStep(run, step);
        }
      }
    } catch (error) {
      this.crash(error);
    } finally {
      this.finishModel(run);
    }
    await this.writeSummary(spec.slug);
  }

  /** Steps, items and children still marked running when a model ends were interrupted. */
  closeInterrupted(run) {
    const halted = this.haltOf(run);
    const now = isoTimestamp();

    for (const step of this.template.steps) {
      const entry = run.entry.steps[step.id];
      if (entry.status === "running") {
        entry.status = halted.status;
        entry.message = halted.message;
        entry.finishedAt ??= now;
      }
      if (!Array.isArray(entry.items)) continue;

      for (const item of entry.items) {
        if (item.status === "running") item.status = halted.status;
        for (const [child, status] of Object.entries(item.steps)) {
          if (status === "running") item.steps[child] = halted.status;
        }
      }
      entry.counts = countItems(entry.items);
    }
  }

  finishModel(run) {
    const { entry } = run;
    this.closeInterrupted(run);
    const statuses = this.template.steps.map((step) => ({ step, status: entry.steps[step.id].status }));
    const fatal = this.fatal ?? run.fatal;

    let status;
    let message = null;
    if (fatal !== null) {
      status = "failed";
      message = fatal.message;
    } else if (this.stopRequested && statuses.some(({ status: value }) => ["pending", "running", "stopped"].includes(value))) {
      status = "stopped";
      message = MESSAGES.stopped;
    } else if (statuses.every(({ step, status: value }) => value === "completed" || run.phaseSkipped.has(step.id))) {
      status = "completed";
    } else if (statuses.some(({ status: value }) => value === "completed" || value === "partial")) {
      status = "partial";
      message = "Bazı adımlar tamamlanamadı.";
    } else {
      status = "failed";
      message = "Hiçbir adım tamamlanamadı.";
    }

    entry.status = status;
    entry.message = message;
    entry.finishedAt = isoTimestamp();
    entry.durationSeconds = secondsFromMs(performance.now() - run.startedAt);
    entry.active = [];
    void this.writer.flush();
    this.event(status === "completed" ? "info" : status === "partial" || status === "stopped" ? "warn" : "error", "model_finished", {
      model: run.slug,
      message: message ?? "Model iş akışını tamamladı.",
    });
  }

  async writeSummary(slug) {
    const { active: _active, ...summary } = this.state.models[slug];
    const directory = join(this.root, "models", slug);
    await ensureDirectory(this.root, directory);
    await atomicWrite(this.root, join(directory, "summary.json"), jsonText(summary));
  }

  async finish() {
    for (const entry of Object.values(this.state.models)) {
      if (entry.status === "pending" || entry.status === "running") {
        const halted = this.fatal !== null
          ? { status: "failed", message: this.fatal.message }
          : { status: "stopped", message: MESSAGES.stopped };
        entry.status = halted.status;
        entry.message = halted.message;
        entry.finishedAt ??= isoTimestamp();
        entry.active = [];
      }
    }

    const statuses = Object.values(this.state.models).map((entry) => entry.status);
    let status;
    let message = null;
    if (this.fatal !== null) {
      status = "failed";
      message = this.fatal.message;
    } else if (this.stopRequested && statuses.includes("stopped")) {
      status = "stopped";
      message = MESSAGES.stopped;
    } else if (statuses.every((value) => value === "completed")) {
      status = "completed";
    } else if (statuses.every((value) => value === "failed")) {
      status = "failed";
      message = "Hiçbir model iş akışını tamamlayamadı.";
    } else {
      status = "partial";
      message = "Bazı modeller veya adımlar tamamlanamadı.";
    }

    this.terminal = true;
    this.state.status = status;
    this.state.message = message;
    this.state.finishedAt = isoTimestamp();

    for (const slug of Object.keys(this.state.models)) {
      try {
        await this.writeSummary(slug);
      } catch (error) {
        this.crashed ??= error;
      }
    }

    this.event(status === "completed" ? "info" : status === "failed" ? "error" : "warn", "run_finished", {
      message: message ?? "Çalıştırma tamamlandı.",
    });
    await this.events.close();
    if (!(await this.writer.close())) this.crashed ??= new Error("The final state could not be written");
  }

  async runTopLevelStep(run, step) {
    const entry = run.entry.steps[step.id];
    const target = {
      run,
      step,
      directory: join(run.spec.directory, "steps", step.id),
      itemKey: null,
      foreachId: null,
      label: null,
      context: run.context(),
      usage: entry.usage,
      extraUsage: [run.entry.usage],
      attempts: [],
      setStatus: (status) => { entry.status = status; },
      setAttempts: (count) => { entry.attempts = count; },
      entry,
    };

    const outcome = await this.executeStep(target);
    if (outcome.status === "completed") run.outputs[step.id] = { output: outcome.output };
    run.usable.set(step.id, outcome.status === "completed" || outcome.status === PHASE_SKIPPED);
    if (outcome.status === PHASE_SKIPPED) run.phaseSkipped.add(step.id);
  }

  /** Marks a step skipped because a dependency has no usable output. */
  async skipStep(run, step, entry, item, directory, blocker) {
    const message = `Bağlı olduğu adım (${blocker}) başarısız oldu.`;
    const now = isoTimestamp();

    if (entry !== null) {
      entry.status = "skipped";
      entry.message = message;
      entry.finishedAt = now;
    }
    if (step.type !== "foreach") {
      await this.writeResult(directory, step, item?.key ?? null, {
        status: "skipped", message, startedAt: null, finishedAt: now, durationSeconds: null, usage: emptyUsage(), attempts: [], output: null,
      });
    }
    void this.writer.flush();
    this.event("info", "step_skipped", { model: run.slug, step: step.id, item: item?.key ?? null, message });

    return message;
  }

  /** Runs one non-foreach step (top level or foreach child) and writes its result.json. */
  async executeStep(target) {
    const { run, step } = target;
    const eventScope = { model: run.slug, step: step.id, item: target.itemKey };
    const startedAt = isoTimestamp();
    const started = performance.now();

    if (step.type === "browser_check") {
      target.setStatus("skipped");
      if (target.entry) {
        target.entry.message = MESSAGES.browserCheck;
        target.entry.finishedAt = startedAt;
      }
      await this.writeResult(target.directory, step, target.itemKey, {
        status: "skipped", message: MESSAGES.browserCheck, startedAt, finishedAt: startedAt, durationSeconds: 0,
        usage: emptyUsage(), attempts: [], output: null,
      });
      void this.writer.flush();
      this.event("info", "step_skipped", { ...eventScope, message: MESSAGES.browserCheck });

      return { status: PHASE_SKIPPED, output: null };
    }

    target.setStatus("running");
    if (target.entry) target.entry.startedAt = startedAt;
    void this.writer.flush();
    this.event("info", "step_started", { ...eventScope, message: `Adım başladı: ${step.title}` });

    let outcome;
    if (codeMode(step, this.settings.stepModes) === "code_agent") {
      outcome = { status: "failed", message: MESSAGES.codeAgent, output: null };
    } else {
      outcome = await this.runPromptStep(target);
    }

    const finishedAt = isoTimestamp();
    const durationSeconds = secondsFromMs(performance.now() - started);
    target.setStatus(outcome.status);
    if (target.entry) {
      target.entry.message = outcome.message ?? null;
      target.entry.finishedAt = finishedAt;
      target.entry.durationSeconds = durationSeconds;
    }

    await this.writeResult(target.directory, step, target.itemKey, {
      status: outcome.status,
      message: outcome.message ?? null,
      startedAt,
      finishedAt,
      durationSeconds,
      usage: target.usage,
      attempts: target.attempts,
      output: outcome.status === "completed" ? outcome.output : null,
    });
    void this.writer.flush();

    if (outcome.status === "completed") {
      this.event("info", "step_completed", { ...eventScope, message: `Adım tamamlandı: ${step.title}` });
    } else if (outcome.status === "failed") {
      this.event("error", "step_failed", { ...eventScope, message: `${step.title}: ${outcome.message}` });
    }

    return outcome;
  }

  async writeResult(directory, step, itemKey, result) {
    await ensureDirectory(this.root, directory);
    await atomicWrite(this.root, join(directory, "result.json"), jsonText({
      schema: RESULT_SCHEMA,
      stepId: step.id,
      type: step.type,
      item: itemKey,
      status: result.status,
      message: result.message,
      startedAt: result.startedAt,
      finishedAt: result.finishedAt,
      durationSeconds: result.durationSeconds,
      usage: result.usage,
      attempts: result.attempts,
      output: result.output,
    }));
  }

  async runPromptStep(target) {
    const { step } = target;
    const settings = stepSettings(step, this.settings);

    let rendered;
    try {
      rendered = renderTemplate(step.prompt, target.context);
    } catch (error) {
      return { status: "failed", message: `Prompt şablonu işlenemedi: ${clipText(String(error.message), 300)}` };
    }

    if (Buffer.byteLength(rendered, "utf8") > MAX_PROMPT_BYTES) {
      const now = isoTimestamp();
      target.attempts.push({
        n: 1, kind: "initial", status: "failed", error: MESSAGES.promptTooLarge, startedAt: now, finishedAt: now,
        durationSeconds: 0, usage: emptyUsage(), sessionId: null, stopReason: null,
        files: { prompt: null, response: null, output: null, demo: null, checks: null },
      });
      target.setAttempts(1);

      return { status: "failed", message: MESSAGES.promptTooLarge };
    }

    if (step.type === "llm_json") return this.runJsonStep(target, rendered, settings);
    if (step.type === "code_text") return this.runCodeStep(target, rendered, settings);

    return this.runTextStep(target, rendered, settings);
  }

  async contentBackoff(target, settings) {
    return sleep(settings.backoffSeconds * 1000 * this.settings.backoffScale, target.run.signal);
  }

  async runTextStep(target, rendered, settings) {
    let failures = 0;
    let kind = "initial";

    for (;;) {
      const call = await this.callModel(target, rendered, kind, settings);
      if (call.outcome === "halted") return this.haltOf(target.run);
      if (call.outcome === "ok") {
        this.showLastOutput(target.run, call.lastOutput, "completed");
        return { status: "completed", message: null, output: call.text };
      }

      failures += 1;
      if (failures >= settings.attempts) return { status: "failed", message: call.record.error };
      if (!(await this.contentBackoff(target, settings))) return this.haltOf(target.run);
      kind = "retry";
    }
  }

  async runJsonStep(target, rendered, settings) {
    const { step, run } = target;
    const base = `${rendered}\n\n${jsonOutputRules(step.schema)}`;
    let prompt = base;
    let failures = 0;
    let kind = "initial";

    for (;;) {
      const call = await this.callModel(target, prompt, kind, settings);
      if (call.outcome === "halted") return this.haltOf(run);

      if (call.outcome === "ok") {
        const { record } = call;
        const parsed = parseModelJson(call.text);
        let problem;

        if (parsed.ok) {
          const outputFile = `attempt-${record.n}/output.json`;
          await atomicWrite(this.root, join(target.directory, outputFile), jsonText(parsed.value));
          record.files.output = outputFile;
          const errors = validateJsonSchema(parsed.value, step.schema);
          if (errors.length === 0) {
            this.showLastOutput(run, call.lastOutput, "completed");
            return { status: "completed", message: null, output: parsed.value };
          }

          problem = errors.join("\n");
          record.error = clipText(`Yanıt şemaya uymuyor: ${errors.slice(0, 5).join("; ")}`, 1000);
        } else {
          problem = parsed.error;
          record.error = clipText(`Yanıt geçerli JSON değil: ${parsed.error}`, 1000);
        }

        record.status = "failed";
        this.showLastOutput(run, call.lastOutput, "failed");
        this.event("warn", "attempt_failed", {
          model: run.slug, step: step.id, item: target.itemKey, message: `${record.n}. deneme reddedildi: ${record.error}`,
        });
        prompt = base + jsonRetrySuffix(problem, call.text);
      } else if (call.record.stopReason === "length") {
        prompt = base + jsonRetrySuffix(OUTPUT_LIMIT_PROBLEM, call.text);
      } else {
        prompt = base;
      }

      failures += 1;
      if (failures >= settings.attempts) return { status: "failed", message: call.record.error };
      if (!(await this.contentBackoff(target, settings))) return this.haltOf(run);
      kind = "retry";
    }
  }

  async runCodeStep(target, rendered, settings) {
    const { step, run } = target;
    let prompt = rendered;
    let failures = 0;
    let repairs = 0;
    let kind = "initial";

    for (;;) {
      const call = await this.callModel(target, prompt, kind, settings);
      if (call.outcome === "halted") return this.haltOf(run);

      if (call.outcome !== "ok") {
        failures += 1;
        if (failures >= settings.attempts) return { status: "failed", message: call.record.error };
        if (!(await this.contentBackoff(target, settings))) return this.haltOf(run);
        kind = "retry";
        continue;
      }

      const { record } = call;
      const extracted = extractHtml(call.text);
      const { html } = extracted;
      const checks = checkDemoHtml(html, step.checks);
      // An answer that hit the output token limit is cut off even when its HTML looks complete.
      const cutOff = record.stopReason === "length";
      const truncated = extracted.truncated || cutOff;
      if (cutOff && !extracted.truncated) checks.critical.unshift(OUTPUT_LIMIT_ISSUE);
      const visibleChecks = { critical: publicIssues(checks.critical), warnings: publicIssues(checks.warnings) };

      if (html !== null) {
        record.files.demo = `attempt-${record.n}/demo.html`;
        await atomicWrite(this.root, join(target.directory, record.files.demo), html);
      }
      record.files.checks = `attempt-${record.n}/checks.json`;
      await atomicWrite(this.root, join(target.directory, record.files.checks), jsonText(visibleChecks));

      if (checks.critical.length === 0) {
        await atomicWrite(this.root, join(target.directory, "demo.html"), html);
        this.showLastOutput(run, call.lastOutput, "completed");

        return {
          status: "completed",
          message: null,
          output: { file: "demo.html", bytes: Buffer.byteLength(html, "utf8"), checks: visibleChecks },
        };
      }

      record.status = "failed";
      record.error = clipText(`Statik kontrol: ${checks.critical.map((issue) => issue.message).join(" ")}`, 1000);
      this.showLastOutput(run, call.lastOutput, "failed");
      this.event("warn", "attempt_failed", {
        model: run.slug, step: step.id, item: target.itemKey, message: `${record.n}. deneme reddedildi: ${record.error}`,
      });

      if (repairs >= settings.repairAttempts) {
        return { status: "failed", message: `Demo statik kontrollerden geçemedi: ${checks.critical[0].message}` };
      }

      repairs += 1;
      this.event("info", "repair_requested", {
        model: run.slug, step: step.id, item: target.itemKey, message: `Onarım isteniyor (${repairs}/${settings.repairAttempts}).`,
      });
      prompt = rendered + demoRepairSuffix(checks.critical, html, truncated);
      if (!(await this.contentBackoff(target, settings))) return this.haltOf(run);
      kind = "repair";
    }
  }

  /**
   * One content attempt: an OpenCode call, re-sent after transient provider errors with growing waits (at
   * most 6 per attempt, of which at most 2 for a bare "Unexpected server error"). When the waits run out
   * for PERSISTENT_TRANSIENT_CALLS attempts of a model in a row, with no completed call in between, the
   * model is given up and its remaining work ends.
   */
  async callModel(target, prompt, kind, settings) {
    const { run } = target;
    let waits = 0;
    let unexpectedWaits = 0;
    let attemptKind = kind;

    for (;;) {
      const call = await this.singleCall(target, prompt, attemptKind, settings);
      if (call.outcome !== "transient") return call;

      const unexpected = isUnexpectedServerError(call.errorEvent);
      if (waits >= TRANSIENT_WAIT_SECONDS.length || (unexpected && unexpectedWaits >= UNEXPECTED_SERVER_ERROR_WAITS)) {
        run.exhaustedTransientCalls += 1;
        if (run.exhaustedTransientCalls < PERSISTENT_TRANSIENT_CALLS) return { ...call, outcome: "failed" };

        this.failModel(run, MESSAGES.persistentTransient);
        return { ...call, outcome: "halted" };
      }

      const seconds = TRANSIENT_WAIT_SECONDS[waits];
      waits += 1;
      if (unexpected) unexpectedWaits += 1;
      const progress = unexpected
        ? `${unexpectedWaits}/${UNEXPECTED_SERVER_ERROR_WAITS}`
        : `${waits}/${TRANSIENT_WAIT_SECONDS.length}`;
      this.event("warn", "rate_limited", {
        model: run.slug,
        step: target.step.id,
        item: target.itemKey,
        message: `Sağlayıcı geçici bir hata verdi; ${seconds} sn sonra yeniden denenecek (${progress}).`,
      });
      if (!(await sleep(seconds * 1000 * this.settings.backoffScale, run.signal))) {
        return { outcome: "halted", record: null, text: "" };
      }
      attemptKind = "transient";
    }
  }

  /**
   * The model's call configuration with the step's maxOutputTokens applied as its answer limit. OpenCode 2.x
   * has no switch for that, so the model's real limits are read once per model and the step limit is written
   * into the call configuration when it is below the model's own. When the limits cannot be read the call runs
   * with the model's own limit, which is said once.
   */
  async configWithOutputCap(run, cap, eventScope) {
    run.modelLimit ??= lookupModelLimit({
      opencodeBinary: this.job.opencodeBinary,
      model: run.spec.model,
      directory: run.spec.workspace,
      privateHome: run.spec.privateHome,
      configPath: run.spec.configPath,
      configContent: run.spec.configContent,
      retryDelaysMs: LOOKUP_RETRY_DELAYS_MS.map((milliseconds) => milliseconds * this.settings.backoffScale),
      signal: run.signal,
    });
    const limit = await run.modelLimit;

    if (limit !== null) {
      try {
        return withOutputCap(run.spec.configContent, run.spec.model, limit, cap);
      } catch {
        // A configuration of an unexpected shape is treated like unreadable limits.
      }
    }

    if (!run.limitWarned && !run.signal.aborted) {
      run.limitWarned = true;
      this.event("warn", "output_limit_unknown", {
        model: eventScope.model,
        message: "Modelin çıktı sınırı OpenCode'dan okunamadı; adımların maxOutputTokens sınırı uygulanamıyor, model kendi sınırıyla çalışır.",
      });
    }

    return run.spec.configContent;
  }

  /** One OpenCode call under the global call limit; records attempt-<n>/ files and the attempt entry. */
  async singleCall(target, prompt, kind, settings) {
    const { run, step } = target;
    const release = await this.calls.acquire(run.signal);
    if (release === null) return { outcome: "halted", record: null, text: "" };

    const eventScope = { model: run.slug, step: step.id, item: target.itemKey };
    const configContent = await this.configWithOutputCap(run, settings.maxOutputTokens, eventScope);
    if (run.signal.aborted) {
      release();
      return { outcome: "halted", record: null, text: "" };
    }

    const n = target.attempts.length + 1;
    const record = {
      n,
      kind,
      status: "running",
      error: null,
      startedAt: isoTimestamp(),
      finishedAt: null,
      durationSeconds: null,
      usage: emptyUsage(),
      sessionId: null,
      stopReason: null,
      files: { prompt: `attempt-${n}/prompt.txt`, response: null, output: null, demo: null, checks: null },
    };
    const active = {
      step: target.foreachId ?? step.id,
      child: target.foreachId === null ? null : step.id,
      item: target.itemKey,
      label: target.label,
      attempt: n,
      startedAt: record.startedAt,
      tail: "",
    };
    let handle = null;
    let overLimit = false;
    const onAbort = () => handle?.abort("stopped");
    let result;

    try {
      target.attempts.push(record);
      target.setAttempts(n);
      await ensureDirectory(this.root, join(target.directory, `attempt-${n}`));
      await atomicWrite(this.root, join(target.directory, record.files.prompt), prompt);
      run.entry.active.push(active);
      void this.writer.flush();
      this.event("info", "attempt_started", { ...eventScope, message: `${step.title}: ${n}. deneme başladı.` });

      run.signal.addEventListener("abort", onAbort, { once: true });
      result = await runOpenCodeText({
        opencodeBinary: this.job.opencodeBinary,
        agent: WORKFLOW_AGENT,
        model: run.spec.model,
        directory: run.spec.workspace,
        privateHome: run.spec.privateHome,
        configPath: run.spec.configPath,
        configContent,
        prompt,
        startupRetryDelaysMs: STARTUP_RETRY_DELAYS_MS.map((milliseconds) => milliseconds * this.settings.backoffScale),
        signal: run.signal,
        timeoutSeconds: settings.timeoutSeconds,
        maxResultBytes: settings.maxOutputChars * 4,
        maxEventBytes: Math.max(MIN_EVENT_BYTES, settings.maxOutputChars * 8),
        title: `benchforge:workflow:${this.job.runId}:${run.slug}:${step.id}:${target.itemKey ?? "-"}:${n}`,
        isStopRequested: () => run.signal.aborted,
        onHandle: (value) => {
          handle = value;
          if (value !== null && run.signal.aborted) value.abort("stopped");
        },
        onText: (text) => {
          if (text.length > settings.maxOutputChars && !overLimit) {
            overLimit = true;
            handle?.abort("result-limit");
          }
          active.tail = tail(text);
          this.writer.schedule();
        },
      });
    } finally {
      run.signal.removeEventListener("abort", onAbort);
      release();
      const index = run.entry.active.indexOf(active);
      if (index !== -1) run.entry.active.splice(index, 1);
    }

    record.finishedAt = isoTimestamp();
    record.durationSeconds = secondsFromMs(result.elapsedMs);
    record.sessionId = result.sessionId;
    record.stopReason = result.stopReason ?? null;
    record.usage = workflowCallUsage(result);
    // A completed call shows that the provider serves this model again.
    if (result.status === "completed") run.exhaustedTransientCalls = 0;
    mergeUsage(target.usage, record.usage);
    for (const usage of target.extraUsage) mergeUsage(usage, record.usage);

    if (result.text !== "") {
      record.files.response = `attempt-${n}/response.txt`;
      await atomicWrite(this.root, join(target.directory, record.files.response), result.text);
    }

    const outcome = this.classifyCall(target, record, result, overLimit, settings);
    // The text of a call that ended on its own (not one ended by a stop or a run/model failure). A
    // completed answer is judged by its step (schema, static checks), which then shows it as completed
    // or failed; a failed call is shown as failed right away.
    const lastOutput = result.text === "" || outcome === "halted" ? null : {
      step: active.step,
      child: active.child,
      item: active.item,
      label: active.label,
      status: null,
      finishedAt: record.finishedAt,
      tail: tail(result.text),
    };
    if (outcome !== "ok") this.showLastOutput(run, lastOutput, "failed");
    void this.writer.flush();
    if (outcome === "failed" || outcome === "transient") {
      this.event("warn", "attempt_failed", { ...eventScope, message: `${n}. deneme başarısız: ${record.error}` });
    }

    return { outcome, record, text: result.text, lastOutput, errorEvent: result.errorEvent };
  }

  /** Shows the text of a finished call as the model's last output (state.models[slug].lastOutput). */
  showLastOutput(run, lastOutput, status) {
    if (!lastOutput) return;
    run.entry.lastOutput = { ...lastOutput, status };
    this.writer.schedule();
  }

  /** Sets the attempt status and error; returns ok | failed | transient | halted. */
  classifyCall(target, record, result, overLimit, settings) {
    const { run } = target;

    if (result.status === "stopped" || run.signal.aborted) {
      const halted = this.haltOf(run);
      record.status = halted.status;
      record.error = halted.message;
      return "halted";
    }

    record.status = "failed";
    // OpenCode 2.x ends a call that stayed cut off after its two own continuations with an error (exit 1). For a
    // demo that is a cut-off demo as well: it goes to the static checks, and a repair asks for a smaller one.
    const cutOffDemo = result.abortReason === null && result.stopReason === "length" && result.text !== ""
      && target.step.type === "code_text";
    if (result.status === "completed" || cutOffDemo) {
      if (result.text.length > settings.maxOutputChars) {
        record.error = `Model yanıtı ${settings.maxOutputChars} karakter sınırını aştı.`;
        return "failed";
      }
      // A cut-off text or JSON answer is rejected; a cut-off demo goes to the static checks and a repair.
      if (result.stopReason === "length" && target.step.type !== "code_text") {
        record.error = MESSAGES.outputLimit;
        return "failed";
      }
      record.status = "completed";
      return "ok";
    }

    if (overLimit || result.abortReason === "result-limit") {
      record.error = `Model yanıtı ${settings.maxOutputChars} karakter sınırını aştı.`;
      return "failed";
    }
    if (result.abortReason === "timeout") {
      record.error = `Süre sınırı (${settings.timeoutSeconds} sn) doldu.`;
      return "failed";
    }
    if (result.abortReason === null && result.stopReason === "length") {
      record.error = MESSAGES.outputLimit;
      return "failed";
    }

    const kind = result.errorEvent === null ? null : providerErrorKind(result.errorEvent);
    if (kind === "account") {
      record.error = MESSAGES.account;
      this.failRun(MESSAGES.account);
      return "halted";
    }
    if (kind === "unavailable" || kind === "policy" || kind === "limit") {
      record.error = MESSAGES[kind];
      this.failModel(run, MESSAGES[kind]);
      return "halted";
    }

    record.error = result.errorMessage ?? MESSAGES.noResponse;
    return kind === "transient" ? "transient" : "failed";
  }

  async runForeach(run, step) {
    const entry = run.entry.steps[step.id];
    const directory = join(run.spec.directory, "steps", step.id);
    const started = performance.now();
    const scope = { model: run.slug, step: step.id };

    entry.status = "running";
    entry.startedAt = isoTimestamp();
    void this.writer.flush();
    this.event("info", "step_started", { ...scope, message: `Adım başladı: ${step.title}` });

    const items = this.expandItems(run, step);
    let status;
    let message = null;

    if (typeof items === "string") {
      status = "failed";
      message = items;
    } else {
      await ensureDirectory(this.root, directory);
      await atomicWrite(this.root, join(directory, "items.json"), jsonText(items.map(({ key, index, label: itemLabel, item }) => ({
        key, index, label: itemLabel, item,
      }))));

      entry.items = items.map((item) => ({
        key: item.key,
        label: item.label,
        status: "pending",
        steps: Object.fromEntries(step.steps.map((child) => [child.id, "pending"])),
      }));
      entry.counts = countItems(entry.items);
      run.entry.items.total += items.length;
      for (const item of items) await this.writeItem(directory, item, "pending");
      void this.writer.flush();

      const records = new Map();
      await runPool(
        items,
        step.concurrency ?? 1,
        (item) => this.runItem(run, step, directory, item, entry, records),
        () => !run.signal.aborted,
      );

      const counts = countItems(entry.items);
      entry.counts = counts;
      run.outputs[step.id] = { items: [...records.keys()].sort((a, b) => a - b).map((index) => records.get(index)) };

      const fatal = this.fatal ?? run.fatal;
      if (counts.completed === counts.total) {
        status = "completed";
      } else if (fatal === null && this.stopRequested) {
        status = "stopped";
        message = MESSAGES.stopped;
      } else if (counts.completed > 0) {
        status = "partial";
        message = `${counts.completed}/${counts.total} öğe tamamlandı.`;
      } else {
        status = "failed";
        message = fatal?.message ?? "Hiçbir öğe tamamlanamadı.";
      }
    }

    entry.status = status;
    entry.message = message;
    entry.finishedAt = isoTimestamp();
    entry.durationSeconds = secondsFromMs(performance.now() - started);
    run.usable.set(step.id, (run.outputs[step.id]?.items.length ?? 0) > 0);
    void this.writer.flush();

    if (status === "completed" || status === "partial") {
      this.event(status === "completed" ? "info" : "warn", "step_completed", {
        ...scope, message: status === "completed" ? `Adım tamamlandı: ${step.title}` : `${step.title}: ${message}`,
      });
    } else if (status === "failed") {
      this.event("error", "step_failed", { ...scope, message: `${step.title}: ${message}` });
    }
  }

  /** The foreach source as item descriptors, or a Turkish message when it cannot be expanded. */
  expandItems(run, step) {
    const source = resolvePath(run.context(), step.over);
    if (!source.found || !Array.isArray(source.value)) return "Döngü kaynağı (over) bir dizi olarak çözümlenemedi.";
    if (source.value.length === 0) return "Döngü kaynağında öğe yok.";
    if (source.value.length > MAX_FOREACH_ITEMS) return `Döngü kaynağında en fazla ${MAX_FOREACH_ITEMS} öğe olabilir.`;

    const total = source.value.length;
    const gallery = this.template.gallery?.from === step.id ? this.template.gallery : null;

    return source.value.map((item, index) => {
      const fallback = `Öğe ${index + 1}`;
      const context = run.context({ item, loop: { index, number: index + 1, total } });
      const render = (text, defaultText) => {
        try {
          return label(renderTemplate(text, context), defaultText);
        } catch {
          return defaultText;
        }
      };
      const itemLabel = render(step.itemLabel ?? DEFAULT_ITEM_LABEL, fallback);
      const descriptor = { key: String(index + 1).padStart(3, "0"), index, label: itemLabel, item };
      if (gallery !== null) descriptor.galleryTitle = render(gallery.title, itemLabel);

      return descriptor;
    });
  }

  async writeItem(directory, item, status) {
    const itemDirectory = join(directory, "items", item.key);
    await ensureDirectory(this.root, itemDirectory);
    const record = { key: item.key, index: item.index, label: item.label, item: item.item, status };
    if (Object.hasOwn(item, "galleryTitle")) record.galleryTitle = item.galleryTitle;
    await atomicWrite(this.root, join(itemDirectory, "item.json"), jsonText(record));
  }

  async runItem(run, step, directory, item, foreachEntry, records) {
    const itemEntry = foreachEntry.items[item.index];
    const itemDirectory = join(directory, "items", item.key);
    const scope = { model: run.slug, step: step.id, item: item.key };
    if (run.signal.aborted) return;

    itemEntry.status = "running";
    foreachEntry.counts = countItems(foreachEntry.items);
    await this.writeItem(directory, item, "running");
    void this.writer.flush();
    this.event("info", "item_started", { ...scope, message: `Öğe başladı: ${item.label}` });

    const previous = [...records.keys()]
      .filter((index) => index < item.index)
      .sort((a, b) => a - b)
      .map((index) => records.get(index));
    const context = run.context({
      item: item.item,
      loop: { index: item.index, number: item.index + 1, total: foreachEntry.items.length, previous },
    });
    const record = { item: item.item, index: item.index, key: item.key, label: item.label };
    const childStatus = new Map();

    for (const child of step.steps) {
      if (run.signal.aborted) break;
      const childDirectory = join(itemDirectory, child.id);

      const blocker = (child.dependsOn ?? []).find((id) => !isUsable(childStatus.get(id)));
      if (blocker !== undefined) {
        itemEntry.steps[child.id] = "skipped";
        childStatus.set(child.id, "skipped");
        await this.skipStep(run, child, null, item, childDirectory, blocker);
        continue;
      }

      const outcome = await this.executeStep({
        run,
        step: child,
        directory: childDirectory,
        itemKey: item.key,
        foreachId: step.id,
        label: item.label,
        context,
        usage: emptyUsage(),
        extraUsage: [foreachEntry.usage, run.entry.usage],
        attempts: [],
        setStatus: (status) => { itemEntry.steps[child.id] = status === PHASE_SKIPPED ? "skipped" : status; },
        setAttempts: () => {},
        entry: null,
      });
      childStatus.set(child.id, outcome.status);
      if (outcome.status === "completed") record[child.id] = { output: outcome.output };
    }

    const statuses = step.steps.map((child) => childStatus.get(child.id));
    let status;
    if (statuses.every(isUsable)) {
      status = "completed";
    } else if (statuses.some((value) => value === "failed" || value === "skipped")) {
      status = "failed";
    } else {
      status = this.haltOf(run).status;
    }

    itemEntry.status = status;
    foreachEntry.counts = countItems(foreachEntry.items);
    if (status === "completed") {
      records.set(item.index, record);
      run.entry.items.completed += 1;
    } else if (status === "failed") {
      run.entry.items.failed += 1;
    }
    await this.writeItem(directory, item, status);
    void this.writer.flush();

    if (status === "completed") {
      this.event("info", "item_completed", { ...scope, message: `Öğe tamamlandı: ${item.label}` });
    } else if (status === "failed") {
      this.event("warn", "item_failed", { ...scope, message: `Öğe başarısız: ${item.label}` });
    }
  }
}

/** Best-effort failed state for a job that could not be validated (the run directory itself is trusted). */
async function writeInvalidJobState(root, jobPath) {
  const now = isoTimestamp();
  const state = {
    schema: STATE_SCHEMA,
    runId: await readRunIdQuietly(jobPath),
    pid: process.pid,
    status: "failed",
    message: MESSAGES.invalidJob,
    startedAt: now,
    updatedAt: now,
    finishedAt: now,
    models: {},
  };

  try {
    await atomicWrite(root, join(root, "state.json"), jsonText(state));
  } catch {
    // Nothing else can report the problem; the exit code does.
  }
}

/**
 * Entry point of bin/workflow-worker.mjs. Returns the exit code: 0 when the run ended (completed,
 * partial, failed or stopped), 1 for an invalid job or a worker crash.
 */
export async function runWorkflowWorker(jobPath) {
  let engine = null;
  let stopBeforeStart = false;
  const onSignal = () => {
    if (engine === null) {
      stopBeforeStart = true;
    } else {
      engine.requestStop();
    }
  };
  for (const signal of ["SIGTERM", "SIGINT", "SIGHUP"]) process.on(signal, onSignal);

  let root;
  try {
    root = await resolveRunDirectory(jobPath);
  } catch {
    return 1;
  }

  let job;
  try {
    job = await loadWorkflowJob(root, jobPath);
  } catch {
    await writeInvalidJobState(root, jobPath);
    return 1;
  }

  engine = new WorkflowEngine(job);
  if (stopBeforeStart) engine.requestStop();

  return engine.run();
}
