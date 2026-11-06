import { createReadStream } from "node:fs";
import { stat } from "node:fs/promises";
import { resolve } from "node:path";
import { createInterface } from "node:readline";
import { fileURLToPath } from "node:url";

const MAX_EVENT_LOG_BYTES = 64 * 1024 * 1024;

/**
 * A failed model call is a provider failure when OpenCode reports a server-side or
 * capacity error (5xx, 429, a retryable API error or an unexpected server error), or when
 * the provider refuses this account (an expired key, a missing subscription or credential, a used-up
 * usage limit). OpenCode 2.x reports a failure that happened before any request left the machine (its
 * server process did not start) the same way. Such runs say nothing about the model's ability and are
 * not scored.
 */
export function isProviderError(event) {
  if (event?.type !== "error" || typeof event.error !== "object" || event.error === null) {
    return false;
  }

  const { status, names, messages, retryable } = errorFields(event);

  if (isCapacityStatus(status)) return true;
  if (names.includes("APIError") && retryable) return true;
  // The provider refuses the model for this account (for example a data-training privacy setting):
  // an account configuration problem, not something the model did.
  if (status === 400 && messages.some((message) => PRIVACY_REFUSAL.test(message))) return true;
  // The key or subscription is not accepted (for example "This API key has expired" or
  // "An active OpenCode Go subscription is required"), no credential is configured at all, or the
  // account's usage limit is used up.
  if (status === 401 || status === 403 || names.includes("ProviderAuthError") || names.some((name) => ACCOUNT_TYPES.has(name) || MODEL_LIMIT_TYPES.has(name))) return true;
  if (names.some((name) => TRANSIENT_TYPES.has(name))) return true;
  if (messages.some((message) => TRANSPORT_FAILURE.test(message) || STARTUP_FAILURE.test(message))) return true;

  return isBareUnexpectedServerError(names, messages);
}

const PRIVACY_REFUSAL = /privacy settings|trains on request data/iu;

// OpenCode 2.x names its provider errors in `error.type`:
// - the key, subscription or usage limit of the account is the problem (the run stops);
// - this one model's spend limit is used up (only that model stops);
// - the provider or the network is in trouble for a while (wait and retry).
// `provider.rate-limit` and `provider.internal` also carry a 429 or 5xx `status`; the type is enough without it.
const ACCOUNT_TYPES = new Set([
  "provider.auth",
  "provider.quota",
  "provider.free-limit",
  "provider.go-limit",
  "provider.budget-limit",
  "provider.chatgpt-limit",
]);
const MODEL_LIMIT_TYPES = new Set(["provider.model-budget-limit"]);
// `provider.unknown` is OpenCode's catch-all for a failure that has no 4xx status and matched nothing else
// (for example an error frame inside a 200 stream). OpenCode itself retries it, so it is transient.
const TRANSIENT_TYPES = new Set(["provider.rate-limit", "provider.transport", "provider.timeout", "provider.internal", "provider.unknown"]);
// OpenCode has no route to the model (`Model unavailable: <provider>/<model>`): the model cannot be called at all.
const UNAVAILABLE_TYPES = new Set(["provider.no-route"]);
// 2.x reports an error of its own making (a network failure that is not tied to one request, an unexpected
// server error) as `type: "unknown"` with only a message.
const UNKNOWN_ERROR_NAMES = new Set(["UnknownError", "unknown", "provider.internal"]);
const TRANSPORT_FAILURE = /^Transport: /u;
// Several standalone OpenCode processes started at the same moment sometimes fail like this while the shared
// database is set up for the first time. No request was sent; a second try works.
const STARTUP_FAILURE = /^Standalone server exited before reporting readiness/u;

// The provider refuses this model because of the account's data or privacy policy: OpenCode Go's 400
// "This Go model trains on request data ... Privacy settings" and OpenRouter's 404 "No endpoints found
// matching your data policy ... Configure: https://openrouter.ai/settings/privacy".
const POLICY_REFUSAL = /privacy settings|trains on request data|data policy|openrouter\.ai\/settings\/privacy/iu;
const UNEXPECTED_SERVER_ERROR = /unexpected server error/iu;
// OpenRouter answers a withdrawn or mistyped model id with a 400 "<id> is not a valid model ID".
const UNAVAILABLE_MODEL = /ModelDeprecated|ModelNotFound|model\b.*\b(?:not found|deprecated|does not exist)|not a valid model\b/iu;
const NETWORK_CODES = new Set(["ECONNRESET", "ECONNREFUSED", "ETIMEDOUT", "EAI_AGAIN", "ENOTFOUND", "EPIPE", "UND_ERR_SOCKET", "UND_ERR_CONNECT_TIMEOUT"]);

const objectOrEmpty = (value) => (typeof value === "object" && value !== null && !Array.isArray(value) ? value : {});

function httpStatus(value) {
  const status = typeof value === "string" && /^\d{3}$/u.test(value) ? Number(value) : value;
  return Number.isInteger(status) && status >= 100 && status <= 599 ? status : null;
}

/**
 * The parts of an error event that the provider error checks read, from the OpenCode 1.x shape
 * ({ error: { name, data: { statusCode, isRetryable, message } } }), the 2.x shape
 * ({ error: { type, message, status } }) and the older { error: { code | status } } /
 * { part: { error: { code } } } shape.
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
 * - "limit": the spend limit of this one model is used up (2.x `provider.model-budget-limit`); give up on this model.
 * - null: anything else, which is treated as the model's own failure.
 */
export function providerErrorKind(event) {
  if (event?.type !== "error") return null;

  const { status, codes, names, messages, retryable } = errorFields(event);

  if (names.some((name) => MODEL_LIMIT_TYPES.has(name))) return "limit";
  if (status === 401 || status === 403 || names.includes("ProviderAuthError") || names.some((name) => ACCOUNT_TYPES.has(name))) return "account";
  if ((status === 400 || status === 404) && messages.some((message) => POLICY_REFUSAL.test(message))) return "policy";
  if (status === 404 || status === 410 || names.some((name) => UNAVAILABLE_TYPES.has(name))) return "unavailable";
  if (isCapacityStatus(status)) return "transient";
  if (codes.includes("rate_limit_exceeded")) return "transient";
  if ([...names, ...codes, ...messages].some((text) => UNAVAILABLE_MODEL.test(text))) return "unavailable";
  if (names.includes("APIError") && retryable) return "transient";
  if (names.some((name) => TRANSIENT_TYPES.has(name))) return "transient";
  if (messages.some((message) => TRANSPORT_FAILURE.test(message) || STARTUP_FAILURE.test(message))) return "transient";
  if (isBareUnexpectedServerError(names, messages)) return "transient";
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

  return !isCapacityStatus(status) && isBareUnexpectedServerError(names, messages);
}

function isBareUnexpectedServerError(names, messages) {
  return names.some((name) => UNKNOWN_ERROR_NAMES.has(name)) && messages.some((message) => UNEXPECTED_SERVER_ERROR.test(message));
}

/**
 * True for OpenCode 2.x's "Standalone server exited before reporting readiness": the call's own server
 * process died while it started, before any request was made. Starting the call again works; nothing was
 * spent and the model has not been asked anything.
 */
export function isStartupFailure(event) {
  if (event?.type !== "error") return false;

  return errorFields(event).messages.some((message) => STARTUP_FAILURE.test(message));
}

/**
 * OpenCode 2.x continues a response that hit the output token limit twice by itself. When the answer is
 * cut off a third time it ends the call with this error (exit 1). That is the output-limit stop 1.x
 * reported as a finished step with reason "length", not a failure of the model.
 */
export function isOutputLimitError(event) {
  return event?.type === "error" && event.error?.type === "output-limit";
}

/** "output-limit", "provider" or "model": what the last error event of a finished call means. */
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

  if (isOutputLimitError(lastError)) return "output-limit";

  return lastError !== null && isProviderError(lastError) ? "provider" : "model";
}

/**
 * True when the call in an event log never got going: its last error is the start race (see isStartupFailure)
 * and no step ever started. Such a call asked the model nothing, so it can simply be run again.
 */
export async function isStartupFailureLog(path) {
  try {
    if ((await stat(path)).size > MAX_EVENT_LOG_BYTES) return false;
  } catch {
    return false;
  }

  let lastError = null;
  const lines = createInterface({ input: createReadStream(path, { encoding: "utf8" }), crlfDelay: Infinity });

  for await (const line of lines) {
    if (line.includes('"type":"step_start"')) return false;
    if (!line.includes('"type":"error"')) continue;

    try {
      const event = JSON.parse(line);
      if (event.type === "error") lastError = event;
    } catch {
      // Ignore partial or malformed lines.
    }
  }

  return lastError !== null && isStartupFailure(lastError);
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
  } else if (args[0] === "--startup-failure") {
    process.stdout.write(`${args[1] && await isStartupFailureLog(args[1]) ? "yes" : "no"}\n`);
  } else {
    process.stdout.write(`${args[0] ? await classifyEventLog(args[0]) : "model"}\n`);
  }
}
