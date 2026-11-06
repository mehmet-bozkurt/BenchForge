import { constants } from "node:fs";
import { access, lstat, mkdir } from "node:fs/promises";
import { basename, dirname, isAbsolute, join, resolve } from "node:path";
import { OUTPUT_LIMIT_MESSAGE, assertRestrictedConfig, runOpenCodeText } from "./opencode-text.mjs";
import { atomicWrite, checkPath, readBounded } from "./private-files.mjs";

const MAX_JOB_BYTES = 256 * 1024;
const MAX_CONFIG_BYTES = 128 * 1024;
const MAX_PROMPT_BYTES = 1024 * 1024;
const MAX_EVENT_BYTES = 2 * 1024 * 1024;
const MAX_RESULT_BYTES = 1024 * 1024;

if (process.env.BENCHFORGE_RUNNER !== "1" || process.argv.length !== 3) {
  process.exit(64);
}

const jobPath = resolve(process.argv[2]);
let state;
let stateWriteQueue = Promise.resolve();
let activeChild = null;
let stopRequested = false;
let terminal = false;

function isPositiveInteger(value) {
  return Number.isSafeInteger(value) && value > 0;
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

function queueState(root, patch) {
  state = { ...state, ...patch };
  const snapshot = `${JSON.stringify(state, null, 2)}\n`;
  stateWriteQueue = stateWriteQueue.then(() => atomicWrite(root, state.statePath, snapshot));
  return stateWriteQueue;
}

function requestStop(root) {
  if (stopRequested || terminal) return;
  stopRequested = true;
  void queueState(root, { status: "stopping", message: "Hazırlık güvenli biçimde durduruluyor." }).catch(() => {});
  activeChild?.abort("stopped");
}

function classifyError(event) {
  const code = event.error?.code ?? event.error?.status ?? event.part?.error?.code;
  if (code === 429 || code === "429" || code === "rate_limit_exceeded") {
    return "İstek sınırına ulaşıldı.";
  }
  return "Model isteği hata verdi.";
}

function runModel(model, prompt, timeoutSeconds) {
  return runOpenCodeText({
    opencodeBinary: state.opencodeBinary,
    agent: "benchforge-preparation",
    model: model.model,
    directory: model.directory,
    privateHome: join(model.directory, ".benchforge-home"),
    configPath: model.configPath,
    configContent: model.configContent,
    prompt,
    timeoutSeconds,
    maxResultBytes: MAX_RESULT_BYTES,
    maxEventBytes: MAX_EVENT_BYTES,
    isStopRequested: () => stopRequested,
    onHandle: (handle) => { activeChild = handle; },
    describeError: classifyError,
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

    // OpenCode 2.x continues a cut-off answer by itself and the parts are joined, so a call that was cut off at
    // any point has no complete answer even when it finished normally.
    if (result.status === "completed" && result.stopReason === "length") {
      result.status = "failed";
      result.errorMessage = OUTPUT_LIMIT_MESSAGE;
    }

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
