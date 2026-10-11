import { spawn } from "node:child_process";
import { closeSync, openSync } from "node:fs";
import { readFile, rename, rm, writeFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import { subprocessEnvironment } from "./process-environment.mjs";

const boundaryMonitorPath = fileURLToPath(new URL("./model-boundary-monitor.mjs", import.meta.url));

const jobPath = process.argv[2];

if (!jobPath || process.env.BENCHFORGE_RUNNER !== "1") {
  process.exit(64);
}

const job = JSON.parse(await readFile(jobPath, "utf8"));
let currentState;
let stopRequested = false;
let finished = false;
let child;
let stateWriteQueue = Promise.resolve();

function writeState(patch) {
  currentState = { ...currentState, ...patch };
  const snapshot = `${JSON.stringify(currentState, null, 2)}\n`;

  stateWriteQueue = stateWriteQueue.then(async () => {
    const temporaryPath = `${job.statePath}.worker-${process.pid}.tmp`;
    await writeFile(temporaryPath, snapshot, "utf8");
    await rename(temporaryPath, job.statePath);
  });

  return stateWriteQueue;
}

async function finish(status, exitCode, message) {
  if (finished) return;
  finished = true;
  await writeState({
    status,
    exitCode,
    finishedAt: new Date().toISOString(),
    message,
  });
  await Promise.allSettled([rm(job.lockPath, { force: true }), rm(jobPath, { force: true })]);
}

currentState = {
  version: 1,
  status: "running",
  runId: job.runId,
  prompt: job.promptRelative,
  config: job.configRelative,
  routes: job.routes,
  runnerPid: process.pid,
  benchmarkPid: null,
  modelCount: job.modelCount,
  startedAt: job.startedAt,
  finishedAt: null,
  exitCode: null,
  message: "Benchmark çalışıyor.",
  logPath: job.logPath,
};

const requestStop = async () => {
  if (stopRequested || finished) return;
  stopRequested = true;
  await writeState({ status: "stopping", message: "Benchmark güvenli biçimde durduruluyor." });

  if (child && child.exitCode === null) {
    child.kill("SIGTERM");
  }
};

process.on("SIGTERM", () => void requestStop());
process.on("SIGINT", () => void requestStop());

try {
  const logFd = openSync(job.logPath, "a", 0o600);
  child = spawn(
    Object.hasOwn(job, "shellBinary") ? job.shellBinary : "/bin/zsh",
    ["-f", job.scriptPath, job.runId, job.promptPath, job.configPath, job.routes.join(","), job.openCodeConfigPath],
    {
      cwd: job.projectRoot,
      env: subprocessEnvironment({ BENCHFORGE_BOUNDARY_MONITOR: boundaryMonitorPath, BENCHFORGE_DATA_ROOT: job.projectRoot }),
      stdio: ["ignore", logFd, logFd],
    },
  );
  closeSync(logFd);

  child.once("error", async (error) => {
    if (finished) return;
    process.exitCode = 1;
    await finish("failed", null, `Benchmark başlatılamadı: ${error.message}`);
  });

  child.once("exit", async (code, signal) => {
    if (finished) return;
    if (stopRequested || signal) {
      await finish("stopped", code, "Benchmark kullanıcı tarafından durduruldu.");
      process.exitCode = 0;
      return;
    }

    if (code === 0) {
      await finish("completed", 0, "Benchmark tamamlandı ve sonuçlar kaydedildi.");
      process.exitCode = 0;
      return;
    }

    await finish("failed", code, `Benchmark koşucusu ${code ?? "bilinmeyen"} koduyla kapandı.`);
    process.exitCode = code ?? 1;
  });

  await writeState({ benchmarkPid: child.pid ?? null });
} catch (error) {
  await finish("failed", null, `Koşucu hatası: ${error instanceof Error ? error.message : String(error)}`);
  process.exitCode = 1;
}
