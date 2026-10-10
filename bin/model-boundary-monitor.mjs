import { spawn } from "node:child_process";
import { closeSync, constants, openSync, writeSync } from "node:fs";
import { lstat, mkdir, readFile, realpath, rename, writeFile } from "node:fs/promises";
import { basename, dirname, isAbsolute, join, relative, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { ENVIRONMENT_PROFILES, isolatedModelEnvironment, modelEnvironment } from "./process-environment.mjs";

const MAX_LINE_BYTES = 1024 * 1024;
const MAX_ENTRIES = 50;
const MAX_PENDING_DENIALS = 20;
const DENIAL_PAIR_WINDOW_MS = 10000;
const STOP_AFTER_DENIALS = 3;
const POLICY_VERSION = "workspace-boundary-v1";

function safeText(value, limit = 240) {
  return String(value ?? "")
    .replace(/\u001b\[[0-9;]*m/g, "")
    .replace(/[\u0000-\u001f\u007f]/g, "")
    .replace(/(?:sk-|Bearer\s+)[A-Za-z0-9_-]{8,}/gi, "[redacted]")
    .replace(/((?:api[_-]?key|token|password|authorization)=)[^\s&]+/gi, "$1[redacted]")
    .slice(0, limit);
}

function isOutside(workspace, target) {
  const localPath = relative(workspace, resolve(workspace, target));

  return localPath === ".." || localPath.startsWith("../") || isAbsolute(localPath);
}

function requestedPaths(input) {
  const directPaths = [input?.filePath, input?.path, input?.directory]
    .filter((value) => typeof value === "string" && value.length <= 4096);
  const command = typeof input?.command === "string" ? input.command.slice(0, 16384) : "";
  const commandPaths = [...command.matchAll(/(?:^|[\s'"=(])((?:\.\.\/|\/)[^\s'";|<>)]*)/g)]
    .map((match) => match[1]);

  return [...directPaths, ...commandPaths];
}

function matchesDirectory(workspace, path, directory) {
  if (!path) return false;
  const target = resolve(workspace, path);
  const prefix = resolve(workspace, directory.replace(/\*+$/, ""));

  return target === prefix || target.startsWith(`${prefix}/`);
}

async function verifyOutputParent(runPath, directory, create = false) {
  const localPath = relative(runPath, directory);
  if (localPath === ".." || localPath.startsWith("../") || isAbsolute(localPath)) throw new Error("Output parent is outside the run.");
  let current = runPath;

  for (const part of ["", ...localPath.split("/").filter(Boolean)]) {
    if (part) current = join(current, part);
    let information;

    try {
      information = await lstat(current);
    } catch (error) {
      if (error.code !== "ENOENT" || !create) throw error;
      try {
        await mkdir(current, { mode: 0o700 });
      } catch (mkdirError) {
        if (mkdirError.code !== "EEXIST") throw mkdirError;
      }
      information = await lstat(current);
    }

    if (information.isSymbolicLink() || !information.isDirectory() || await realpath(current) !== current) {
      throw new Error("Output parent must be a canonical directory without symbolic links.");
    }
  }
}

async function verifyRegularArtifact(path, allowMissing = false) {
  try {
    const information = await lstat(path);
    if (information.isSymbolicLink() || !information.isFile()) throw new Error("Output artifact cannot be a link or directory.");
  } catch (error) {
    if (!allowMissing || error.code !== "ENOENT") throw error;
  }
}

async function prepareOutputPaths(workspace, eventPath, stderrPath, reportPath) {
  if (![eventPath, stderrPath, reportPath].every(isAbsolute)) throw new Error("Output paths must be absolute.");
  const runPath = dirname(workspace);
  const modelSlug = basename(workspace);
  const paths = [eventPath, stderrPath, reportPath].map((path) => resolve(path));
  const [eventOutput, stderrOutput, reportOutput] = paths;
  const reportRelative = relative(runPath, reportOutput);
  let expectedEvent;
  let expectedStderr;

  if (reportRelative === `_boundary/${modelSlug}.json`) {
    expectedEvent = join(runPath, "_logs", `${modelSlug}.opencode.events.jsonl`);
    expectedStderr = join(runPath, "_logs", `${modelSlug}.opencode.stderr.log`);
  } else {
    const attemptParts = reportRelative.split("/");
    if (attemptParts.length !== 4 || attemptParts[0] !== "_attempts" || attemptParts[1] !== modelSlug
      || !/^attempt-[1-9][0-9]*$/.test(attemptParts[2]) || attemptParts[3] !== "boundary-report.json") {
      throw new Error("Boundary report is not assigned to this model.");
    }
    expectedEvent = join(dirname(reportOutput), "opencode.events.jsonl");
    expectedStderr = join(dirname(reportOutput), "opencode.stderr.log");
  }

  if (eventOutput !== expectedEvent || stderrOutput !== expectedStderr) throw new Error("Event logs are not assigned to this model.");

  for (const path of paths) {
    await verifyOutputParent(runPath, dirname(path), true);
    await verifyRegularArtifact(path, true);
  }

  return { runPath, eventOutput, stderrOutput, reportOutput };
}

export class ModelBoundaryMonitor {
  constructor({ workspace, policyVersion = POLICY_VERSION, now = () => Date.now(), onChange = () => {}, onStop = () => {} }) {
    this.workspace = resolve(workspace);
    this.runPath = dirname(this.workspace);
    this.modelSlug = basename(this.workspace);
    this.policyVersion = safeText(policyVersion, 80);
    this.now = now;
    this.onChange = onChange;
    this.onStop = onStop;
    this.blockedCount = 0;
    this.observedCrossModelReadCount = 0;
    this.entries = [];
    this.stoppedForBoundary = false;
    this.seenEvents = new Set();
    this.pendingDenials = [];
    this.pendingStderr = [];
    this.eventBuffer = "";
    this.stderrBuffer = "";
    this.droppingEventLine = false;
    this.droppingStderrLine = false;
  }

  snapshot() {
    return {
      version: 1,
      policyVersion: this.policyVersion,
      blockedCount: this.blockedCount,
      observedCrossModelReadCount: this.observedCrossModelReadCount,
      stoppedForBoundary: this.stoppedForBoundary,
      stopAfterDenials: STOP_AFTER_DENIALS,
      entries: [...this.entries],
    };
  }

  consumeEvents(chunk) {
    this.consumeLines("event", chunk, (line) => this.consumeEventLine(line));
  }

  consumeStderr(chunk) {
    this.consumeLines("stderr", chunk, (line) => this.consumeStderrLine(line));
  }

  finishInput() {
    if (!this.droppingEventLine && this.eventBuffer) this.consumeEventLine(this.eventBuffer);
    if (!this.droppingStderrLine && this.stderrBuffer) this.consumeStderrLine(this.stderrBuffer);
    this.eventBuffer = "";
    this.stderrBuffer = "";
  }

  consumeLines(type, chunk, acceptLine) {
    const bufferKey = type === "event" ? "eventBuffer" : "stderrBuffer";
    const droppingKey = type === "event" ? "droppingEventLine" : "droppingStderrLine";
    const segments = String(chunk).split("\n");

    for (let index = 0; index < segments.length; index++) {
      const complete = index < segments.length - 1;
      if (!this[droppingKey]) {
        if (Buffer.byteLength(this[bufferKey]) + Buffer.byteLength(segments[index]) > MAX_LINE_BYTES) {
          this[bufferKey] = "";
          this[droppingKey] = true;
        } else {
          this[bufferKey] += segments[index];
        }
      }

      if (complete) {
        if (!this[droppingKey]) acceptLine(this[bufferKey]);
        this[bufferKey] = "";
        this[droppingKey] = false;
      }
    }
  }

  consumeEventLine(line) {
    let event;

    try {
      event = JSON.parse(line);
    } catch {
      return;
    }

    if (event?.type !== "tool_use" || !event.part?.state) return;
    const { part } = event;
    const state = part.state;
    const tool = /^[a-zA-Z0-9_-]{1,40}$/.test(part.tool ?? "") ? part.tool : "unknown";
    const eventKey = safeText(part.callID ?? part.id ?? `${event.timestamp}:${tool}:${state.status}`, 200);

    if (this.seenEvents.has(eventKey)) return;
    if (state.status !== "error" && state.status !== "completed") return;
    this.seenEvents.add(eventKey);
    if (this.seenEvents.size > 2048) this.seenEvents.delete(this.seenEvents.values().next().value);

    if (state.status === "completed") {
      this.inspectCompletedRead(tool, state);
      return;
    }

    const error = typeof state.error === "string" ? state.error.slice(0, 8192) : "";
    const ruleDenial = /The user has specified a rule which prevents you from using this specific tool call\./i.test(error);
    const permissionDenial = /(?:permission|external_directory)/i.test(error)
      && /(?:reject|denied|forbidden|not allowed|\bdeny\b)/i.test(error);
    if (!ruleDenial && !permissionDenial) return;

    const paths = requestedPaths(state.input);
    const outsidePath = paths.find((path) => isOutside(this.workspace, path));
    const now = this.now();
    const stderr = this.pendingStderr.find((item) => now - item.time <= DENIAL_PAIR_WINDOW_MS
      && (!outsidePath || matchesDirectory(this.workspace, outsidePath, item.path)));
    if (stderr) this.pendingStderr.splice(this.pendingStderr.indexOf(stderr), 1);
    const target = outsidePath ?? stderr?.path;
    if (!target && paths.length > 0) return;
    const denial = { time: now, tool, path: target, recorded: Boolean(target), paired: Boolean(stderr) };
    this.pendingDenials.push(denial);
    this.pendingDenials = this.pendingDenials.filter((item) => now - item.time <= DENIAL_PAIR_WINDOW_MS).slice(-MAX_PENDING_DENIALS);

    if (target && !stderr?.recorded) this.recordBlocked(tool, target);
  }

  consumeStderrLine(line) {
    const plainLine = line.replace(/\u001b\[[0-9;]*m/g, "");
    const match = plainLine.match(/permission requested:\s*external_directory\s*\(([^\n]+)\).*auto-rejecting/i);
    if (!match || !isOutside(this.workspace, match[1])) return;
    const now = this.now();
    const pending = this.pendingDenials.filter((item) => !item.paired && now - item.time <= DENIAL_PAIR_WINDOW_MS);
    const denial = pending.find((item) => matchesDirectory(this.workspace, item.path, match[1]))
      ?? pending.find((item) => !item.recorded);

    if (denial) {
      denial.paired = true;
      if (!denial.recorded) {
        denial.recorded = true;
        this.recordBlocked(denial.tool, match[1]);
      }
      return;
    }

    this.recordBlocked("unknown", match[1]);
    this.pendingStderr.push({ time: now, path: match[1], recorded: true });
    this.pendingStderr = this.pendingStderr.filter((item) => now - item.time <= DENIAL_PAIR_WINDOW_MS).slice(-MAX_PENDING_DENIALS);
  }

  inspectCompletedRead(tool, state) {
    if (tool !== "read" && tool !== "bash") return;
    const exitCode = state.metadata?.exit ?? state.metadata?.exitCode;
    if (tool === "bash" && typeof exitCode === "number" && exitCode !== 0) return;
    if (tool === "bash" && !/(?:^|[\s;|])(cat|head|tail|sed|grep|rg)(?:\s|$)/.test(state.input?.command ?? "")) return;
    const output = typeof state.output === "string" ? state.output.slice(0, MAX_LINE_BYTES) : "";
    if (!output.trim()) return;
    const paths = requestedPaths(state.input).filter((path) => isOutside(this.workspace, path));

    for (const path of paths) {
      const target = resolve(this.workspace, path);
      const runRelative = relative(this.runPath, target);
      const sibling = runRelative.match(/^([^_./][^/]*)\//)?.[1];
      const attemptSibling = runRelative.match(/^_attempts\/([^/]+)\//)?.[1];
      const logSibling = runRelative.match(/^_logs\/([^/]+?)\.(?:opencode|lint|build|export|runtime|routes)(?:\.|$)/)?.[1];
      const escapedRunPath = this.runPath.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
      const outputMarkers = /^(?:_logs|_attempts)(?:\/|$)/.test(runRelative)
        ? [
          ...output.matchAll(new RegExp(`${escapedRunPath}/([^_/\\s\"']+)(?=/|[\"'])`, "g")),
          ...output.matchAll(new RegExp(`${escapedRunPath}/_attempts/([^/\\s\"']+)/`, "g")),
          ...output.matchAll(new RegExp(`${escapedRunPath}/_logs/([^/\\s\"']+?)\\.(?:opencode|lint|build|export|runtime|routes)(?=\\.|[\\s\"']|$)`, "g")),
        ]
        : [];
      const outputSibling = outputMarkers.find((match) => match[1] !== this.modelSlug)?.[1];
      const otherSlug = sibling ?? attemptSibling ?? logSibling ?? outputSibling;
      if (!otherSlug || otherSlug === this.modelSlug) continue;
      if (tool === "bash" && !output.includes(otherSlug) && !output.includes(target)) continue;

      this.observedCrossModelReadCount++;
      this.addEntry("observed_cross_model_read", tool, path, "Başka bir modele ait içerik bu araç çıktısında gözlemlendi.");
      return;
    }
  }

  recordBlocked(tool, path) {
    this.blockedCount++;
    this.addEntry("blocked", tool, path, "Çalışma alanı dışı erişim izin politikası tarafından reddedildi.");

    if (this.blockedCount >= STOP_AFTER_DENIALS && !this.stoppedForBoundary) {
      this.stoppedForBoundary = true;
      this.onChange(this.snapshot());
      this.onStop();
    }
  }

  addEntry(kind, tool, path, reason) {
    const displayPath = relative(this.workspace, resolve(this.workspace, path)) || ".";
    this.entries.push({ timestamp: new Date(this.now()).toISOString(), path: safeText(displayPath), tool, reason, kind });
    if (this.entries.length > MAX_ENTRIES) this.entries.shift();
    this.onChange(this.snapshot());
  }
}

export async function runMonitoredModel(args) {
  const delimiter = args.indexOf("--");
  if (delimiter === -1 || delimiter === args.length - 1) return 64;
  const options = new Map();

  for (let index = 0; index < delimiter; index += 2) {
    if (!args[index]?.startsWith("--") || !args[index + 1] || options.has(args[index])) return 64;
    options.set(args[index], args[index + 1]);
  }

  const workspaceOption = options.get("--workspace");
  const eventOption = options.get("--event-log");
  const stderrOption = options.get("--stderr-log");
  const reportOption = options.get("--report");
  if (!workspaceOption || !eventOption || !stderrOption || !reportOption) return 64;
  const workspace = await realpath(workspaceOption);
  const { runPath, eventOutput: eventPath, stderrOutput: stderrPath, reportOutput: reportPath } = await prepareOutputPaths(workspace, eventOption, stderrOption, reportOption);
  const environmentAdditions = {};
  const configOption = options.get("--opencode-config");
  const runtimeOption = options.get("--runtime-config");
  const basePathOption = options.get("--base-path");
  const timeoutOption = options.get("--timeout-seconds");
  const timeoutSeconds = timeoutOption === undefined ? null : Number(timeoutOption);
  if (timeoutSeconds !== null && (!Number.isInteger(timeoutSeconds) || timeoutSeconds < 1 || timeoutSeconds > 86400)) return 64;
  const environmentProfile = options.get("--environment-profile") ?? "legacy";
  if (!ENVIRONMENT_PROFILES.includes(environmentProfile)) return 64;

  if (configOption) environmentAdditions.OPENCODE_CONFIG = await realpath(configOption);
  if (runtimeOption) {
    const information = await lstat(runtimeOption);
    if (!information.isFile() || information.isSymbolicLink() || information.size > 128 * 1024) return 65;
    environmentAdditions.OPENCODE_CONFIG_CONTENT = await readFile(runtimeOption, "utf8");
    JSON.parse(environmentAdditions.OPENCODE_CONFIG_CONTENT);
  }
  if (basePathOption) {
    if (basePathOption !== `/${basename(workspace)}`) return 65;
    environmentAdditions.NEXT_PUBLIC_BASE_PATH = basePathOption;
  }
  let reportQueue = Promise.resolve();
  let reportCreated = false;
  let child;
  let stopSignal;
  let forceStopTimer;
  let timeoutTimer;
  let timedOut = false;
  let eventFd;
  let stderrFd;
  let modelClosed = false;
  let ioFailed = false;

  const writeReport = (report) => {
    reportQueue = reportQueue.then(async () => {
      await verifyOutputParent(runPath, dirname(reportPath));
      if (!reportCreated) {
        await writeFile(reportPath, `${JSON.stringify(report, null, 2)}\n`, { encoding: "utf8", mode: 0o600, flag: "wx" });
        reportCreated = true;
        return;
      }

      await verifyRegularArtifact(reportPath);
      const temporaryPath = `${reportPath}.monitor-${process.pid}.tmp`;
      await writeFile(temporaryPath, `${JSON.stringify(report, null, 2)}\n`, { encoding: "utf8", mode: 0o600, flag: "wx" });
      await verifyOutputParent(runPath, dirname(reportPath));
      await verifyRegularArtifact(reportPath);
      await rename(temporaryPath, reportPath);
    });
    return reportQueue;
  };

  const stopChild = (signal = "SIGTERM") => {
    if (!child?.pid || modelClosed) return;
    try {
      process.kill(-child.pid, signal);
    } catch {
      child.kill(signal);
    }
  };

  const requestStop = (signal = "SIGTERM") => {
    if (stopSignal) return;
    stopSignal = signal;
    stopChild(signal);
    forceStopTimer = setTimeout(() => stopChild("SIGKILL"), 4000);
    forceStopTimer.unref();
  };

  const onTerminate = () => requestStop("SIGTERM");
  const onInterrupt = () => requestStop("SIGINT");
  const onOutputError = () => {
    ioFailed = true;
    requestStop();
  };
  const monitor = new ModelBoundaryMonitor({
    workspace,
    policyVersion: options.get("--policy-version") ?? POLICY_VERSION,
    onChange: (report) => void writeReport(report).catch(onOutputError),
    onStop: () => requestStop(),
  });
  process.on("SIGTERM", onTerminate);
  process.on("SIGINT", onInterrupt);
  process.stdout.on("error", onOutputError);

  try {
    await writeReport(monitor.snapshot());
    await verifyOutputParent(runPath, dirname(eventPath));
    eventFd = openSync(eventPath, constants.O_WRONLY | constants.O_CREAT | constants.O_EXCL | constants.O_NOFOLLOW, 0o600);
    await verifyOutputParent(runPath, dirname(stderrPath));
    stderrFd = openSync(stderrPath, constants.O_WRONLY | constants.O_CREAT | constants.O_EXCL | constants.O_NOFOLLOW, 0o600);
    let environment = modelEnvironment(environmentAdditions);
    if (environmentProfile === "isolated-v1") {
      // One private home per model, outside its workspace, shared by its first run and its recovery attempts.
      const privateHome = join(runPath, "_opencode-home", basename(workspace));
      await verifyOutputParent(runPath, privateHome, true);
      environment = isolatedModelEnvironment(environmentAdditions, privateHome);
    }
    child = spawn(args[delimiter + 1], args.slice(delimiter + 2), {
      cwd: workspace,
      env: environment,
      detached: true,
      stdio: ["ignore", "pipe", "pipe"],
    });
    if (timeoutSeconds !== null) {
      // A model that never finishes would otherwise hold the run's only slot and keep spending tokens.
      timeoutTimer = setTimeout(() => {
        timedOut = true;
        requestStop("SIGTERM");
      }, timeoutSeconds * 1000);
    }
    child.stdout.setEncoding("utf8");
    child.stderr.setEncoding("utf8");
    child.stdout.on("data", (chunk) => {
      try {
        writeSync(eventFd, chunk);
        process.stdout.write(chunk);
        monitor.consumeEvents(chunk);
      } catch {
        onOutputError();
      }
    });
    child.stderr.on("data", (chunk) => {
      try {
        writeSync(stderrFd, chunk);
        monitor.consumeStderr(chunk);
      } catch {
        onOutputError();
      }
    });

    const result = await new Promise((accept) => {
      child.once("error", () => {
        modelClosed = true;
        accept({ code: 69, signal: null });
      });
      child.once("close", (code, signal) => {
        modelClosed = true;
        accept({ code, signal });
      });
    });
    if (timeoutTimer) clearTimeout(timeoutTimer);
    monitor.finishInput();
    await writeReport(monitor.snapshot());

    if (ioFailed) return 74;

    if (monitor.stoppedForBoundary) {
      process.stdout.write("\nBENCHFORGE: Üç çalışma alanı dışı erişim reddi nedeniyle yalnızca bu model durduruldu.\n");
      return 78;
    }
    if (timedOut) {
      process.stdout.write(`\nBENCHFORGE: Model ${timeoutSeconds} saniyelik süre sınırını aştığı için durduruldu.\n`);
      return 124;
    }
    if (stopSignal || result.signal) return stopSignal === "SIGINT" ? 130 : 143;
    return result.code ?? 1;
  } finally {
    if (forceStopTimer) clearTimeout(forceStopTimer);
    if (timeoutTimer) clearTimeout(timeoutTimer);
    process.off("SIGTERM", onTerminate);
    process.off("SIGINT", onInterrupt);
    process.stdout.off("error", onOutputError);
    if (eventFd !== undefined) closeSync(eventFd);
    if (stderrFd !== undefined) closeSync(stderrFd);
  }
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try {
    process.exitCode = await runMonitoredModel(process.argv.slice(2));
  } catch {
    process.stderr.write("BENCHFORGE: Model sınır gözetimi başlatılamadı veya raporu kaydedilemedi.\n");
    process.exitCode = 74;
  }
}
