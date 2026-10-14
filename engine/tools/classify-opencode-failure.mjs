import { createReadStream } from "node:fs";
import { stat } from "node:fs/promises";
import { resolve } from "node:path";
import { createInterface } from "node:readline";
import { fileURLToPath } from "node:url";

const MAX_EVENT_LOG_BYTES = 64 * 1024 * 1024;

/**
 * A failed model call is a provider failure when OpenCode reports a server-side or
 * capacity error (5xx, 429, a retryable API error or an unexpected server error).
 * Such runs say nothing about the model's ability and are not scored.
 */
export function isProviderError(event) {
  if (event?.type !== "error" || typeof event.error !== "object" || event.error === null) {
    return false;
  }

  const { name, data = {} } = event.error;
  const status = Number(data.statusCode);
  const message = String(data.message ?? "");

  if (Number.isInteger(status) && (status >= 500 || status === 429)) return true;
  if (name === "APIError" && data.isRetryable === true) return true;
  // The provider refuses the model for this account (for example a data-training privacy setting):
  // an account configuration problem, not something the model did.
  if (status === 400 && /privacy settings|trains on request data/iu.test(message)) return true;

  return name === "UnknownError" && /unexpected server error/iu.test(message);
}

export async function classifyEventLog(path) {
  try {
    if ((await stat(path)).size > MAX_EVENT_LOG_BYTES) return "model";
  } catch {
    return "model";
  }

  let lastError = null;
  const lines = createInterface({ input: createReadStream(path, { encoding: "utf8" }), crlfDelay: Infinity });

  for await (const line of lines) {
    if (!line.includes('"type":"error"')) continue;

    try {
      const event = JSON.parse(line);
      if (event.type === "error") lastError = event;
    } catch {
      // Ignore partial or malformed lines.
    }
  }

  return lastError !== null && isProviderError(lastError) ? "provider" : "model";
}

/** Reason of the last finished step ("length" means the response hit the output token limit). */
export async function lastStopReason(path) {
  try {
    if ((await stat(path)).size > MAX_EVENT_LOG_BYTES) return "unknown";
  } catch {
    return "unknown";
  }

  let reason = "unknown";
  const lines = createInterface({ input: createReadStream(path, { encoding: "utf8" }), crlfDelay: Infinity });

  for await (const line of lines) {
    if (!line.includes('"type":"step_finish"')) continue;

    try {
      const event = JSON.parse(line);
      if (event.type === "step_finish" && typeof event.part?.reason === "string") reason = event.part.reason;
    } catch {
      // Ignore partial or malformed lines.
    }
  }

  return reason;
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const args = process.argv.slice(2);
  if (args[0] === "--stop-reason") {
    process.stdout.write(`${args[1] ? await lastStopReason(args[1]) : "unknown"}\n`);
  } else {
    process.stdout.write(`${args[0] ? await classifyEventLog(args[0]) : "model"}\n`);
  }
}
