// Run bookkeeping of the workflow worker (contract §4 and §6): the coalesced, atomic state.json writer,
// the capped events.jsonl log and the small value helpers (timestamps, durations, usage sums).
import { constants } from "node:fs";
import { lstat, open, rename } from "node:fs/promises";
import { performance } from "node:perf_hooks";
import { atomicWrite, checkPath } from "../private-files.mjs";

export const STATE_SCHEMA = "benchforge-workflow-state/1";
export const STATE_WRITE_INTERVAL_MS = 1000;
export const EVENTS_MAX_BYTES = 5 * 1024 * 1024;
const MAX_EVENT_MESSAGE_CHARS = 1000;
const EVENT_LEVELS = new Set(["info", "warn", "error"]);

function pad(value) {
  return String(value).padStart(2, "0");
}

/** Local time as ISO 8601 with the UTC offset and whole seconds, e.g. 2026-10-22T18:30:12+03:00. */
export function isoTimestamp(date = new Date()) {
  const offset = -date.getTimezoneOffset();
  const sign = offset >= 0 ? "+" : "-";
  const absolute = Math.abs(offset);

  return `${String(date.getFullYear()).padStart(4, "0")}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
    + `T${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`
    + `${sign}${pad(Math.floor(absolute / 60))}:${pad(absolute % 60)}`;
}

/** Seconds with two decimals from a performance.now() delta in milliseconds. */
export function secondsFromMs(milliseconds) {
  return Math.round(Math.max(0, milliseconds) / 10) / 100;
}

/** Usage of nothing yet: every value unknown, no calls. */
export function emptyUsage() {
  return { inputTokens: null, outputTokens: null, costUsd: null, calls: 0 };
}

function sumKnown(left, right, isCost) {
  if (left === null || right === null || left === undefined || right === undefined) return null;
  const total = left + right;

  return isCost ? Math.round(total * 1e10) / 1e10 : total;
}

/**
 * Adds `source` (a usage object) to `target` in place. A value stays known only while every
 * contributing call reported it; one unknown call makes the sum unknown (null). `calls` always adds up.
 */
export function mergeUsage(target, source) {
  if (!source || !(source.calls > 0)) return target;

  if (target.calls === 0) {
    target.inputTokens = source.inputTokens ?? null;
    target.outputTokens = source.outputTokens ?? null;
    target.costUsd = source.costUsd ?? null;
  } else {
    target.inputTokens = sumKnown(target.inputTokens, source.inputTokens, false);
    target.outputTokens = sumKnown(target.outputTokens, source.outputTokens, false);
    target.costUsd = sumKnown(target.costUsd, source.costUsd, true);
  }
  target.calls += source.calls;

  return target;
}

/** Usage of one OpenCode call from its result (`inputTokens`, `outputTokens`, `costUsd`, each possibly null). */
export function callUsage(result) {
  return {
    inputTokens: Number.isSafeInteger(result?.inputTokens) ? result.inputTokens : null,
    outputTokens: Number.isSafeInteger(result?.outputTokens) ? result.outputTokens : null,
    costUsd: Number.isFinite(result?.costUsd) ? Math.round(result.costUsd * 1e10) / 1e10 : null,
    calls: 1,
  };
}

/**
 * Writes the worker-owned state object to state.json atomically (temp file + rename). `schedule()`
 * coalesces progress updates into at most one write per interval; `flush()` writes right away (status
 * transitions and exit). The JSON snapshot is taken when a write starts, so it is always the latest.
 */
export class RunStateWriter {
  constructor({ root, path, state, minIntervalMs = STATE_WRITE_INTERVAL_MS }) {
    this.root = root;
    this.path = path;
    this.state = state;
    this.minIntervalMs = minIntervalMs;
    this.chain = Promise.resolve();
    this.queued = null;
    this.timer = null;
    this.lastWriteAt = -Infinity;
    this.lastError = null;
    this.writes = 0;
  }

  schedule() {
    if (this.timer !== null || this.queued !== null) return;

    const delay = Math.max(0, this.lastWriteAt + this.minIntervalMs - performance.now());
    this.timer = setTimeout(() => {
      this.timer = null;
      void this.flush();
    }, delay);
  }

  flush() {
    if (this.timer !== null) {
      clearTimeout(this.timer);
      this.timer = null;
    }
    if (this.queued !== null) return this.queued;

    const write = this.chain.then(async () => {
      this.queued = null;
      this.lastWriteAt = performance.now();
      if (Object.hasOwn(this.state, "updatedAt")) this.state.updatedAt = isoTimestamp();
      const snapshot = `${JSON.stringify(this.state, null, 2)}\n`;
      try {
        await atomicWrite(this.root, this.path, snapshot);
        this.writes += 1;
        this.lastError = null;
      } catch (error) {
        this.lastError = error;
      }
    });
    this.queued = write;
    this.chain = write;

    return write;
  }

  /** Writes the final state and reports whether that last write succeeded. */
  async close() {
    await this.flush();
    if (this.timer !== null) {
      clearTimeout(this.timer);
      this.timer = null;
    }

    return this.lastError === null;
  }
}

/**
 * Append-only events.jsonl with one JSON object per line. Before an append, a file of `maxBytes` or more
 * is renamed to the rotated path (replacing the older copy) and a new file is started. Appends are
 * serialized, opened without following symlinks and never throw: a failed append is dropped.
 */
export class EventLog {
  constructor({ root, path, rotatedPath, maxBytes = EVENTS_MAX_BYTES }) {
    this.root = root;
    this.path = path;
    this.rotatedPath = rotatedPath;
    this.maxBytes = maxBytes;
    this.bytes = null;
    this.chain = Promise.resolve();
    this.failures = 0;
  }

  append({ level = "info", type, model = null, step = null, item = null, message = "" }) {
    const entry = {
      time: isoTimestamp(),
      level: EVENT_LEVELS.has(level) ? level : "info",
      type,
      model,
      step,
      item,
      message: clipMessage(message),
    };
    const line = `${JSON.stringify(entry)}\n`;
    this.chain = this.chain.then(() => this.write(line)).catch(() => {
      this.failures += 1;
    });

    return this.chain;
  }

  async write(line) {
    if (this.bytes === null) {
      const exists = await checkPath(this.root, this.path, "file", true);
      this.bytes = exists ? (await lstat(this.path)).size : 0;
    }

    if (this.bytes >= this.maxBytes) {
      await checkPath(this.root, this.path, "file", true);
      await checkPath(this.root, this.rotatedPath, "file", true);
      await rename(this.path, this.rotatedPath);
      this.bytes = 0;
    }

    await checkPath(this.root, this.path, "file", true);
    const handle = await open(
      this.path,
      constants.O_WRONLY | constants.O_APPEND | constants.O_CREAT | constants.O_NOFOLLOW,
      0o600,
    );
    try {
      await handle.write(line);
    } finally {
      await handle.close();
    }
    this.bytes += Buffer.byteLength(line, "utf8");
  }

  async close() {
    await this.chain;
  }
}

function clipMessage(message) {
  const text = String(message ?? "").replace(/\s+/g, " ").trim();
  if (text.length <= MAX_EVENT_MESSAGE_CHARS) return text;

  const code = text.charCodeAt(MAX_EVENT_MESSAGE_CHARS - 2);
  const end = code >= 0xd800 && code <= 0xdbff ? MAX_EVENT_MESSAGE_CHARS - 2 : MAX_EVENT_MESSAGE_CHARS - 1;

  return `${text.slice(0, end)}…`;
}
