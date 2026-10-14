import { spawn } from "node:child_process";
import { randomBytes } from "node:crypto";
import { constants } from "node:fs";
import { access, link, lstat, mkdir, readFile, rename, unlink, writeFile } from "node:fs/promises";
import { homedir } from "node:os";
import { basename, dirname, isAbsolute, join, relative, resolve, sep } from "node:path";
import { subprocessEnvironment } from "./process-environment.mjs";

const MAX_JOB_BYTES = 256 * 1024;
const MAX_CONFIG_BYTES = 128 * 1024;
const MAX_PROMPT_BYTES = 1024 * 1024;
const MAX_EVENT_BYTES = 2 * 1024 * 1024;
const MAX_RESULT_BYTES = 1024 * 1024;
const STOP_GRACE_MS = 1500;

if (process.env.BENCHFORGE_RUNNER !== "1" || process.argv.length !== 3) {
  process.exit(64);
}

const jobPath = resolve(process.argv[2]);
let state;
let stateWriteQueue = Promise.resolve();
let activeChild = null;
let stopRequested = false;
let terminal = false;
let temporaryCounter = 0;

function isPositiveInteger(value) {
  return Number.isSafeInteger(value) && value > 0;
}

function withinRoot(root, path) {
  if (typeof path !== "string" || !isAbsolute(path) || resolve(path) !== path) {
    throw new Error("Invalid job path");
  }

  const suffix = relative(root, path);
  if (!suffix || suffix === ".." || suffix.startsWith(`..${sep}`) || isAbsolute(suffix)) {
    throw new Error("Job path is outside its private directory");
  }

  return suffix;
}

async function checkPath(root, path, kind, allowMissing = false) {
  const suffix = withinRoot(root, path);
  const parts = suffix.split(sep);
  let cursor = root;

  for (let index = 0; index < parts.length; index += 1) {
    cursor = join(cursor, parts[index]);
    let entry;

    try {
      entry = await lstat(cursor);
    } catch (error) {
      if (error.code === "ENOENT" && allowMissing && index === parts.length - 1) {
        return false;
      }
      throw error;
    }

    if (entry.isSymbolicLink()) {
      throw new Error("Symlink in private job path");
    }

    if (index < parts.length - 1 && !entry.isDirectory()) {
      throw new Error("Non-directory in private job path");
    }

    if (index === parts.length - 1 && !(kind === "file" ? entry.isFile() : entry.isDirectory())) {
      throw new Error("Unexpected job path type");
    }
  }

  return true;
}

async function readBounded(path, byteLimit) {
  const metadata = await lstat(path);
  if (!metadata.isFile() || metadata.isSymbolicLink() || metadata.size > byteLimit) {
    throw new Error("Invalid private input file");
  }

  return readFile(path, "utf8");
}

function assertRestrictedConfig(config) {
  const agent = config?.agent?.["benchforge-preparation"];
  const onlyWildcard = (rules, expected) => rules
    && typeof rules === "object"
    && !Array.isArray(rules)
    && Object.keys(rules).length === 1
    && rules["*"] === expected;

  if (!onlyWildcard(config?.permission, "deny")
    || config.autoupdate !== false
    || !agent
    || agent.mode !== "primary"
    || !onlyWildcard(agent.permission, "deny")
    || !onlyWildcard(agent.tools, false)
    || !Array.isArray(config.plugin)
    || config.plugin.length !== 0
    || !Array.isArray(config.instructions)
    || config.instructions.length !== 0
    || !config.mcp
    || typeof config.mcp !== "object"
    || Array.isArray(config.mcp)
    || Object.keys(config.mcp).length !== 0
    || config.share !== "disabled") {
    throw new Error("Preparation configuration is not restricted");
  }
}

async function validateJob() {
  if (basename(jobPath) !== "job.json") {
    throw new Error("Invalid job file");
  }

  const root = dirname(jobPath);
  const rootInfo = await lstat(root);
  if (!rootInfo.isDirectory() || rootInfo.isSymbolicLink()) {
    throw new Error("Invalid private job directory");
  }
  await checkPath(root, jobPath, "file");

  const job = JSON.parse(await readBounded(jobPath, MAX_JOB_BYTES));
  if (job.version !== 1
    || !isPositiveInteger(job.comparisonId)
    || !Array.isArray(job.models)
    || job.models.length < 1
    || job.models.length > 5
    || typeof job.startedAt !== "string"
    || !Number.isFinite(Date.parse(job.startedAt))) {
    throw new Error("Invalid preparation job");
  }

  const timeoutSeconds = job.timeoutSeconds === undefined || job.timeoutSeconds === null
    ? 900
    : job.timeoutSeconds;
  if (!Number.isSafeInteger(timeoutSeconds) || timeoutSeconds < 1 || timeoutSeconds > 1800) {
    throw new Error("Invalid preparation timeout");
  }

  await checkPath(root, job.statePath, "file", true);
  await checkPath(root, job.promptPath, "file");
  if (job.statePath === jobPath || job.promptPath === jobPath || job.statePath === job.promptPath) {
    throw new Error("Private job files must be distinct");
  }
  const prompt = await readBounded(job.promptPath, MAX_PROMPT_BYTES);
  if (prompt.trim() === "") {
    throw new Error("Empty preparation prompt");
  }

  if (typeof job.opencodeBinary !== "string" || !isAbsolute(job.opencodeBinary)) {
    throw new Error("Invalid OpenCode executable");
  }
  await access(job.opencodeBinary, constants.X_OK);

  const occupiedPaths = new Set([jobPath, job.statePath, job.promptPath]);
  const packageIds = new Set();
  const preparedModels = [];

  for (const model of job.models) {
    if (!isPositiveInteger(model?.packageId)
      || packageIds.has(model.packageId)
      || typeof model.modelId !== "string"
      || model.modelId.length < 1
      || model.modelId.length > 256
      || typeof model.modelName !== "string"
      || model.modelName.length < 1
      || Array.from(model.modelName).length > 256
      || typeof model.model !== "string"
      || !/^(?:openrouter\/[A-Za-z0-9._:-]+\/[A-Za-z0-9._:/-]+|opencode-go\/[A-Za-z0-9._:/-]+)$/.test(model.model)) {
      throw new Error("Invalid preparation model");
    }

    packageIds.add(model.packageId);
    await checkPath(root, model.directory, "directory");
    await checkPath(root, model.configPath, "file");
    const resultExists = await checkPath(root, model.resultPath, "file", true);
    if (resultExists || occupiedPaths.has(model.configPath) || occupiedPaths.has(model.resultPath)
      || model.configPath === model.resultPath) {
      throw new Error("Preparation output path is already occupied");
    }

    occupiedPaths.add(model.configPath);
    occupiedPaths.add(model.resultPath);
    const configContent = await readBounded(model.configPath, MAX_CONFIG_BYTES);
    assertRestrictedConfig(JSON.parse(configContent));
    preparedModels.push({ ...model, configContent });
  }

  return { job, root, prompt, timeoutSeconds, models: preparedModels };
}

async function atomicWrite(root, path, content, replaceExisting = true) {
  await checkPath(root, path, "file", true);
  const temporaryPath = join(dirname(path), `.benchforge-${process.pid}-${temporaryCounter += 1}-${randomBytes(6).toString("hex")}.tmp`);
  await checkPath(root, temporaryPath, "file", true);
  try {
    await writeFile(temporaryPath, content, { encoding: "utf8", mode: 0o600, flag: "wx" });
    await checkPath(root, path, "file", true);
    if (replaceExisting) {
      await rename(temporaryPath, path);
    } else {
      await link(temporaryPath, path);
      await unlink(temporaryPath);
    }
  } catch (error) {
    // The private directory is retained for diagnosis; leave no partial result.
    try { await unlink(temporaryPath); } catch { /* best effort */ }
    throw error;
  }
}

function queueState(root, patch) {
  state = { ...state, ...patch };
  const snapshot = `${JSON.stringify(state, null, 2)}\n`;
  stateWriteQueue = stateWriteQueue.then(() => atomicWrite(root, state.statePath, snapshot));
  return stateWriteQueue;
}

// macOS answers EPERM instead of ESRCH when the group only holds an exited, not yet reaped child,
// e.g. when the CLI writes its last output and exits just as the worker aborts it.
function signalChildGroup(child, signal) {
  if (!child?.pid) return;
  try {
    process.kill(-child.pid, signal);
  } catch (error) {
    if (error.code === "EPERM") {
      try { child.kill(signal); } catch { /* the child has already exited */ }
    } else if (error.code !== "ESRCH") {
      throw error;
    }
  }
}

function isChildGroupAlive(child) {
  if (!child?.pid) return false;
  try {
    process.kill(-child.pid, 0);
    return true;
  } catch (error) {
    if (error.code === "ESRCH" || error.code === "EPERM") return false;
    throw error;
  }
}

function requestStop(root) {
  if (stopRequested || terminal) return;
  stopRequested = true;
  void queueState(root, { status: "stopping", message: "Hazırlık güvenli biçimde durduruluyor." }).catch(() => {});
  activeChild?.abort("stopped");
}

function isForbiddenEvent(event) {
  const type = String(event.type ?? "").toLowerCase();
  const partType = String(event.part?.type ?? "").toLowerCase();
  return type.includes("tool")
    || type.includes("permission")
    || partType === "tool"
    || partType.includes("permission")
    || (type === "step_finish" && event.part?.reason === "tool-calls");
}

function classifyError(event) {
  const code = event.error?.code ?? event.error?.status ?? event.part?.error?.code;
  if (code === 429 || code === "429" || code === "rate_limit_exceeded") {
    return "İstek sınırına ulaşıldı.";
  }
  return "Model isteği hata verdi.";
}

function runModel(model, prompt, timeoutSeconds) {
  return new Promise((resolveRun) => {
    const textParts = new Map();
    let textBytes = 0;
    let eventBytes = 0;
    let buffer = "";
    let abortReason = null;
    let errorMessage = null;
    let costUsd = null;
    let inputTokens = null;
    let outputTokens = null;
    let partCounter = 0;
    const started = Date.now();
    const privateHome = join(model.directory, ".benchforge-home");
    const originalHome = process.env.HOME || homedir();
    const authDataHome = process.env.XDG_DATA_HOME || join(originalHome, ".local", "share");
    const env = subprocessEnvironment({
      HOME: privateHome,
      XDG_CONFIG_HOME: join(privateHome, ".config"),
      XDG_DATA_HOME: authDataHome,
      OPENCODE_CONFIG_DIR: "",
      OPENCODE_TUI_CONFIG: "",
      OPENCODE_PERMISSION: "",
      OPENCODE_CONFIG: model.configPath,
      OPENCODE_CONFIG_CONTENT: model.configContent,
      OPENCODE_DISABLE_PROJECT_CONFIG: "1",
      OPENCODE_PURE: "1",
      OPENCODE_DISABLE_EXTERNAL_SKILLS: "1",
      OPENCODE_DISABLE_CLAUDE_CODE_SKILLS: "1",
      OPENCODE_DISABLE_DEFAULT_PLUGINS: "1",
      OPENCODE_DISABLE_AUTOUPDATE: "1",
      OPENCODE_DISABLE_LSP_DOWNLOAD: "1",
      OPENCODE_DISABLE_CLAUDE_CODE_PROMPT: "1",
      OPENCODE_DISABLE_CLAUDE_CODE: "1",
      OPENCODE_DISABLE_MODELS_FETCH: "1",
      OPENCODE_DISABLE_SHARE: "1",
    });
    const child = spawn(state.opencodeBinary, [
      "run", "--pure", "--format", "json", "--agent", "benchforge-preparation",
      "--model", model.model, "--dir", model.directory,
    ], { cwd: model.directory, detached: true, env, stdio: ["pipe", "pipe", "pipe"] });
    let killTimer;
    let resolveCleanup;
    let cleanupDone = Promise.resolve();
    const timeoutTimer = setTimeout(() => abort("timeout"), timeoutSeconds * 1000);

    function abort(reason) {
      if (abortReason) return;
      abortReason = reason;
      cleanupDone = new Promise((resolve) => { resolveCleanup = resolve; });
      signalChildGroup(child, "SIGTERM");
      killTimer = setTimeout(() => {
        if (isChildGroupAlive(child)) signalChildGroup(child, "SIGKILL");
        resolveCleanup();
      }, STOP_GRACE_MS);
    }

    activeChild = { abort };
    child.stdin.on("error", () => {});
    child.stderr.on("data", () => {});
    child.stdin.end(prompt);

    function consumeLine(line) {
      if (!line.trim()) return;
      let event;
      try {
        event = JSON.parse(line);
      } catch {
        errorMessage = "Model çıktısı okunamadı.";
        abort("invalid-output");
        return;
      }

      if (!event || typeof event !== "object" || Array.isArray(event)) {
        errorMessage = "Model çıktısı okunamadı.";
        abort("invalid-output");
      } else if (isForbiddenEvent(event)) {
        errorMessage = "Model araç veya izin kullanımı denedi.";
        abort("forbidden-activity");
      } else if (event.type === "error") {
        errorMessage = classifyError(event);
        abort("cli-error");
      } else if (event.type === "text" && typeof event.part?.text === "string") {
        const key = typeof event.part.id === "string"
          ? event.part.id
          : `${event.messageID ?? "message"}:${partCounter += 1}`;
        const previous = textParts.get(key);
        if (previous === undefined || event.part.text.length >= previous.length) {
          textBytes += Buffer.byteLength(event.part.text, "utf8")
            - (previous === undefined ? 0 : Buffer.byteLength(previous, "utf8"));
          if (previous === undefined && textParts.size > 0) textBytes += 1;
          textParts.set(key, event.part.text);
        }
        if (textBytes > MAX_RESULT_BYTES - 1) {
          errorMessage = "Model yanıtı izin verilen boyutu aştı.";
          abort("result-limit");
        }
      } else if (event.type === "step_finish") {
        const cost = event.part?.cost;
        const tokens = event.part?.tokens;
        if (Number.isFinite(cost) && cost >= 0 && Number.isFinite((costUsd ?? 0) + cost)) {
          costUsd = (costUsd ?? 0) + cost;
        }
        if (Number.isSafeInteger(tokens?.input) && tokens.input >= 0
          && Number.isSafeInteger((inputTokens ?? 0) + tokens.input)) {
          inputTokens = (inputTokens ?? 0) + tokens.input;
        }
        if (Number.isSafeInteger(tokens?.output) && tokens.output >= 0
          && Number.isSafeInteger((outputTokens ?? 0) + tokens.output)) {
          outputTokens = (outputTokens ?? 0) + tokens.output;
        }
      }
    }

    child.stdout.setEncoding("utf8");
    child.stdout.on("data", (chunk) => {
      if (abortReason) return;
      eventBytes += Buffer.byteLength(chunk, "utf8");
      if (eventBytes > MAX_EVENT_BYTES) {
        errorMessage = "Model olay çıktısı izin verilen boyutu aştı.";
        abort("event-limit");
        return;
      }
      buffer += chunk;
      let newline;
      while (!abortReason && (newline = buffer.indexOf("\n")) !== -1) {
        const line = buffer.slice(0, newline);
        buffer = buffer.slice(newline + 1);
        consumeLine(line);
      }
    });

    child.once("error", () => {
      errorMessage = "OpenCode başlatılamadı.";
    });
    child.once("close", async (code, signal) => {
      clearTimeout(timeoutTimer);
      if (!abortReason && buffer.trim()) consumeLine(buffer);
      if (abortReason && !isChildGroupAlive(child)) {
        if (killTimer) clearTimeout(killTimer);
        resolveCleanup();
      }
      await cleanupDone;
      if (killTimer) clearTimeout(killTimer);
      activeChild = null;
      const text = [...textParts.values()].join("\n").trim();
      let status = "completed";
      if (abortReason === "stopped" || stopRequested) {
        status = "stopped";
        errorMessage = "Kullanıcı tarafından durduruldu.";
      } else if (abortReason || signal || code !== 0 || text === "") {
        status = "failed";
        errorMessage ??= code !== null && code !== 0
          ? `Model isteği başarısız oldu (CLI kodu ${Number.isInteger(code) ? code : "bilinmiyor"}).`
          : "Model yanıtı alınamadı.";
      }

      resolveRun({
        status, text, errorMessage,
        durationSeconds: Math.max(0, Math.ceil((Date.now() - started) / 1000)),
        costUsd, inputTokens, outputTokens,
      });
    });
  });
}

async function main() {
  const { job, root, prompt, timeoutSeconds, models } = await validateJob();
  state = {
    version: 1,
    comparisonId: job.comparisonId,
    runnerPid: process.pid,
    opencodeBinary: job.opencodeBinary,
    statePath: job.statePath,
    status: "running",
    message: "Hazırlık modelleri sırayla çalışıyor.",
    startedAt: job.startedAt,
    finishedAt: null,
    models: models.map((model) => ({
      packageId: model.packageId,
      status: "pending",
      errorMessage: null,
      durationSeconds: null,
      costUsd: null,
      inputTokens: null,
      outputTokens: null,
      resultPath: model.resultPath,
    })),
  };

  process.on("SIGTERM", () => requestStop(root));
  process.on("SIGINT", () => requestStop(root));
  await queueState(root, {});

  for (const model of models) {
    const privateHome = join(model.directory, ".benchforge-home");
    await checkPath(root, privateHome, "directory", true);
    await mkdir(privateHome, { mode: 0o700 });
    await mkdir(join(privateHome, ".config"), { mode: 0o700 });
  }

  for (let index = 0; index < models.length; index += 1) {
    if (stopRequested) break;
    const model = models[index];
    const nextModels = state.models.map((entry, position) => position === index
      ? { ...entry, status: "running" }
      : entry);
    await queueState(root, { models: nextModels });
    if (stopRequested) break;
    const result = await runModel(model, prompt, timeoutSeconds);

    if (result.status === "completed") {
      try {
        await atomicWrite(root, model.resultPath, `${result.text}\n`, false);
      } catch {
        result.status = "failed";
        result.errorMessage = "Model sonucu kaydedilemedi.";
      }
    }

    await queueState(root, {
      models: state.models.map((entry, position) => position === index
        ? {
          ...entry,
          status: result.status,
          errorMessage: result.errorMessage,
          durationSeconds: result.durationSeconds,
          costUsd: result.costUsd,
          inputTokens: result.inputTokens,
          outputTokens: result.outputTokens,
        }
        : entry),
    });
  }

  if (stopRequested) {
    await queueState(root, {
      models: state.models.map((entry) => entry.status === "pending" || entry.status === "running"
        ? { ...entry, status: "stopped", errorMessage: "Kullanıcı tarafından durduruldu." }
        : entry),
    });
  }

  const completed = state.models.filter((entry) => entry.status === "completed").length;
  const failed = state.models.filter((entry) => entry.status === "failed").length;
  const status = stopRequested ? "stopped" : failed === 0 ? "completed" : completed > 0 ? "partial" : "failed";
  terminal = true;
  await queueState(root, {
    status,
    finishedAt: new Date().toISOString(),
    message: status === "completed"
      ? "Hazırlık yanıtları kaydedildi."
      : status === "partial"
        ? "Bazı hazırlık yanıtları kaydedildi; diğerleri başarısız oldu."
        : status === "stopped"
          ? "Hazırlık durduruldu."
          : "Hazırlık yanıtları alınamadı.",
  });
}

try {
  await main();
} catch {
  // Job content, configuration and provider errors must not reach process logs.
  process.stderr.write("Preparation worker could not complete.\n");
  process.exitCode = 1;
}
