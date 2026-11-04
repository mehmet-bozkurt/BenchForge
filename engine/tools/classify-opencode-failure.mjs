import { createReadStream } from "node:fs";
import { stat } from "node:fs/promises";
import { resolve } from "node:path";
import { createInterface } from "node:readline";
import { fileURLToPath } from "node:url";

const MAX_EVENT_LOG_BYTES = 64 * 1024 * 1024;

/**
 * A failed model call is a provider failure when OpenCode reports a server-side or
 * capacity error (5xx, 429, a retryable API error or an unexpected server error), or when
 * the provider refuses this account (an expired key, a missing subscription or credential).
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
  // The key or subscription is not accepted (for example "This API key has expired" or
  // "An active OpenCode Go subscription is required"), or no credential is configured at all.
  if (status === 401 || status === 403 || name === "ProviderAuthError") return true;

  return name === "UnknownError" && /unexpected server error/iu.test(message);
}

// The provider refuses this model because of the account's data or privacy policy: OpenCode Go's 400
// "This Go model trains on request data ... Privacy settings" and OpenRouter's 404 "No endpoints found
// matching your data policy ... Configure: https://openrouter.ai/settings/privacy".
const POLICY_REFUSAL = /privacy settings|trains on request data|data policy|openrouter\.ai\/settings\/privacy/iu;
const UNEXPECTED_SERVER_ERROR = /unexpected server error/iu;
const UNAVAILABLE_MODEL = /ModelDeprecated|ModelNotFound|model\b.*\b(?:not found|deprecated|does not exist)/iu;
const NETWORK_CODES = new Set(["ECONNRESET", "ECONNREFUSED", "ETIMEDOUT", "EAI_AGAIN", "ENOTFOUND", "EPIPE", "UND_ERR_SOCKET", "UND_ERR_CONNECT_TIMEOUT"]);

const objectOrEmpty = (value) => (typeof value === "object" && value !== null && !Array.isArray(value) ? value : {});

function httpStatus(value) {
  const status = typeof value === "string" && /^\d{3}$/u.test(value) ? Number(value) : value;
  return Number.isInteger(status) && status >= 100 && status <= 599 ? status : null;
}

/**
 * The parts of an error event that the provider error checks read, from both the real OpenCode shape
 * ({ error: { name, data: { statusCode, isRetryable, message } } }) and the older
 * { error: { code | status } } / { part: { error: { code } } } shape.
 *
 * @returns {{ status: number|null, codes: string[], names: string[], messages: string[], retryable: boolean }}
 */
function errorFields(event) {
  const error = objectOrEmpty(event.error);
  const partError = objectOrEmpty(event.part?.error);
  const data = objectOrEmpty(error.data);

  return {
    status: [data.statusCode, error.statusCode, error.code, error.status, partError.code, partError.status]
      .map(httpStatus)
      .find((value) => value !== null) ?? null,
    codes: [error.code, partError.code, data.code].filter((value) => typeof value === "string"),
    names: [error.name, partError.name, error.type, data.type].filter((value) => typeof value === "string"),
    messages: [data.message, error.message, partError.message].filter((value) => typeof value === "string"),
    retryable: data.isRetryable === true || error.isRetryable === true,
  };
}

const isCapacityStatus = (status) => status !== null && (status >= 500 || status === 429);

/**
 * What a provider error means for the next call:
 * - "transient": capacity or server trouble (429, 5xx, a retryable API error, a network failure); wait and retry.
 * - "account": the provider refuses this key or subscription (401, 403, ProviderAuthError); stop.
 * - "policy": the provider refuses this model under the account's privacy or data policy (OpenCode Go's
 *   privacy 400, OpenRouter's data-policy 404); give up on this model only.
 * - "unavailable": the model is unknown or withdrawn (404, 410, deprecated / not found); give up on this model.
 * - null: anything else, which is treated as the model's own failure.
 */
export function providerErrorKind(event) {
  if (event?.type !== "error") return null;

  const { status, codes, names, messages, retryable } = errorFields(event);

  if (status === 401 || status === 403 || names.includes("ProviderAuthError")) return "account";
  if ((status === 400 || status === 404) && messages.some((message) => POLICY_REFUSAL.test(message))) return "policy";
  if (status === 404 || status === 410) return "unavailable";
  if (isCapacityStatus(status)) return "transient";
  if (codes.includes("rate_limit_exceeded")) return "transient";
  if ([...names, ...codes, ...messages].some((text) => UNAVAILABLE_MODEL.test(text))) return "unavailable";
  if (names.includes("APIError") && retryable) return "transient";
  if (names.includes("UnknownError") && messages.some((message) => UNEXPECTED_SERVER_ERROR.test(message))) return "transient";
  if (codes.some((code) => NETWORK_CODES.has(code))) return "transient";

  return null;
}

/**
 * True for OpenCode's bare "Unexpected server error" (an UnknownError without a 429 or 5xx status). It is
 * transient, but it can also persist for one model, so callers wait less often for it than for 429/5xx.
 */
export function isUnexpectedServerError(event) {
  if (providerErrorKind(event) !== "transient") return false;

  const { status, names, messages } = errorFields(event);

  return !isCapacityStatus(status)
    && names.includes("UnknownError")
    && messages.some((message) => UNEXPECTED_SERVER_ERROR.test(message));
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
