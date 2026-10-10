import { spawn } from "node:child_process";
import { closeSync, openSync } from "node:fs";
import { readFile, rename, rm, stat, writeFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import { runnerControls, subprocessEnvironment } from "./process-environment.mjs";

const boundaryMonitorPath = fileURLToPath(new URL("./model-boundary-monitor.mjs", import.meta.url));

const jobPath = process.argv[2];

if (!jobPath || process.env.BENCHFORGE_RUNNER !== "1") {
  process.exit(64);
}

const job = JSON.parse(await readFile(jobPath, "utf8"));
const configuredTimeoutSeconds = Number(process.env.BENCHFORGE_ATTEMPT_TIMEOUT_SECONDS);
const timeoutSeconds = Number.isInteger(configuredTimeoutSeconds)
  && configuredTimeoutSeconds >= 60
  && configuredTimeoutSeconds <= 7200
  ? configuredTimeoutSeconds
  : null;
let currentState;
let stopRequested = false;
let stopReason = "user";
let finished = false;
let child;
let timeoutHandle;
let stateWriteQueue = Promise.resolve();

async function wasStoppedForBoundary() {
  if (job.mode !== "continue") return false;

  try {
    const reportPath = `${job.attemptPath}/boundary-report.json`;
    if ((await stat(reportPath)).size > 65536) return false;
    const report = JSON.parse(await readFile(reportPath, "utf8"));

    return report.version === 1 && report.stoppedForBoundary === true;
  } catch {
    return false;
  }
}

function writeState(patch) {
  currentState = { ...currentState, ...patch };
  const snapshot = `${JSON.stringify(currentState, null, 2)}\n`;

  stateWriteQueue = stateWriteQueue.then(async () => {
    const temporaryPath = `${job.statePath}.worker-${process.pid}.tmp`;
    await writeFile(temporaryPath, snapshot, { encoding: "utf8", mode: 0o600 });
    await rename(temporaryPath, job.statePath);
  });

  return stateWriteQueue;
}

async function finish(status, exitCode, message) {
  if (finished) return;
  finished = true;
  if (timeoutHandle) clearInterval(timeoutHandle);
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
  runId: job.runPath.split("/").pop(),
  modelSlug: job.modelSlug,
  attemptId: job.attemptId,
  mode: job.mode,
  runnerPid: process.pid,
  processPid: null,
  startedAt: job.startedAt,
  timeoutSeconds,
  finishedAt: null,
  exitCode: null,
  message: job.mode === "continue"
    ? "Model mevcut OpenCode oturumundan devam ediyor."
    : "Model çağrılmadan doğrulamalar çalışıyor.",
  logPath: job.logPath,
};

const requestStop = async (reason = "user") => {
  if (stopRequested || finished) return;
  stopRequested = true;
  stopReason = reason;
  await writeState({
    status: "stopping",
    message: reason === "timeout"
      ? "Güvenlik süresi doldu; deneme ve bağlı süreçler durduruluyor."
      : "Kurtarma denemesi güvenli biçimde durduruluyor.",
  });

  if (child && child.exitCode === null) {
    if (reason === "timeout") {
      try {
        process.kill(-process.pid, "SIGTERM");
      } catch {
        child.kill("SIGTERM");
      }
    } else {
      child.kill("SIGTERM");
    }
  }
};

process.on("SIGTERM", () => void requestStop());
process.on("SIGINT", () => void requestStop());

try {
  const logFd = openSync(job.logPath, "a", 0o600);
  child = spawn(
    Object.hasOwn(job, "shellBinary") ? job.shellBinary : "/bin/zsh",
    [
      "-f",
      job.scriptPath,
      job.runPath,
      job.modelSlug,
      job.model,
      String(job.port),
      job.routes.join(","),
      job.openCodeConfigPath,
      job.attemptPath,
      job.mode,
      job.sourceSessionId,
      job.promptPath,
    ],
    {
      cwd: job.projectRoot,
      env: subprocessEnvironment({ ...runnerControls(), BENCHFORGE_BOUNDARY_MONITOR: boundaryMonitorPath }),
      stdio: ["ignore", logFd, logFd],
    },
  );
  closeSync(logFd);

  child.once("error", async (error) => {
    if (finished) return;
    process.exitCode = 1;
    await finish("failed", null, `Kurtarma denemesi başlatılamadı: ${error.message}`);
  });

  child.once("exit", async (code, signal) => {
    if (finished) return;
    if (stopRequested || signal) {
      await finish("stopped", code, stopReason === "timeout"
        ? "Güvenlik süresi dolduğu için deneme durduruldu."
        : "Kurtarma denemesi kullanıcı tarafından durduruldu.");
      process.exitCode = 0;
      return;
    }

    if (code === 0) {
      const boundaryStopped = await wasStoppedForBoundary();
      await finish("completed", 0, boundaryStopped
        ? "Tekrarlanan çalışma alanı dışı erişim reddi nedeniyle model durduruldu; mevcut kodun doğrulama sonuçları kaydedildi."
        : "Deneme tamamlandı ve doğrulama sonuçları kaydedildi.");
      process.exitCode = 0;
      return;
    }

    await finish("failed", code, `Kurtarma koşucusu ${code ?? "bilinmeyen"} koduyla kapandı.`);
    process.exitCode = code ?? 1;
  });

  await writeState({ processPid: child.pid ?? null });

  if (timeoutSeconds !== null && !finished) {
    const recordedStart = Date.parse(job.startedAt);
    const deadline = (Number.isFinite(recordedStart) ? recordedStart : Date.now()) + timeoutSeconds * 1000;
    const checkDeadline = () => {
      if (Date.now() >= deadline) void requestStop("timeout");
    };
    timeoutHandle = setInterval(checkDeadline, 5000);
    checkDeadline();
  }
} catch (error) {
  await finish("failed", null, `Koşucu hatası: ${error instanceof Error ? error.message : String(error)}`);
  process.exitCode = 1;
}
