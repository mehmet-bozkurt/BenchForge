import { spawn } from "node:child_process";
import { homedir } from "node:os";
import { isAbsolute, join } from "node:path";
import { performance } from "node:perf_hooks";
import { isOutputLimitError, isStartupFailure, providerErrorKind } from "../engine/tools/classify-opencode-failure.mjs";
import { subprocessEnvironment } from "./process-environment.mjs";

// A tool-less OpenCode call: the prompt goes in on stdin, the model may only answer with text, and any
// attempt to use a tool or ask for a permission ends the call. Used by the preparation and workflow workers.

export const STOP_GRACE_MS = 1500;
const DEFAULT_MAX_RESULT_BYTES = 1024 * 1024;
const DEFAULT_MAX_EVENT_BYTES = 2 * 1024 * 1024;
const SESSION_ID_PATTERN = /^ses_[A-Za-z0-9_-]+$/;
const MAX_STOP_REASON_CHARS = 64;

/** Turkish message for a model the provider refuses under the account's privacy or data policy. */
export const POLICY_REFUSAL_MESSAGE = "Sağlayıcı bu modeli hesabınızın gizlilik ayarı nedeniyle reddetti (model istek verisiyle eğitim yapıyor). OpenCode Console veya OpenRouter gizlilik ayarını kontrol edin.";

/** Shown when a response was cut off at the output token limit. */
export const OUTPUT_LIMIT_MESSAGE = "Çıktı token sınırına ulaşıldı; yanıt kesildi.";

/** The spend limit of one model is used up (OpenCode 2.x `provider.model-budget-limit`). */
export const LIMIT_MESSAGE = "Bu model için harcama sınırı doldu.";

/** Keys that keep the call isolated from the host OpenCode setup; `extra` may not replace them. */
const ISOLATION_KEYS = new Set([
  "HOME", "XDG_CONFIG_HOME", "XDG_DATA_HOME", "OPENCODE_CONFIG_DIR", "OPENCODE_CONFIG", "OPENCODE_CONFIG_CONTENT",
]);

export function isForbiddenEvent(event) {
  const type = String(event.type ?? "").toLowerCase();
  const partType = String(event.part?.type ?? "").toLowerCase();
  return type.includes("tool")
    || type.includes("permission")
    || partType === "tool"
    || partType.includes("permission")
    || (type === "step_finish" && event.part?.reason === "tool-calls");
}

// macOS answers EPERM instead of ESRCH when the group only holds an exited, not yet reaped child,
// e.g. when the CLI writes its last output and exits just as the worker aborts it.
export function signalChildGroup(child, signal) {
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

export function isChildGroupAlive(child) {
  if (!child?.pid) return false;
  try {
    process.kill(-child.pid, 0);
    return true;
  } catch (error) {
    if (error.code === "ESRCH" || error.code === "EPERM") return false;
    throw error;
  }
}

export function assertRestrictedConfig(config, agentName = "benchforge-preparation") {
  const agent = config?.agent?.[agentName];
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
    throw new Error("OpenCode configuration is not restricted");
  }
}

/**
 * The environment of a tool-less call: a private HOME and config directory, the host's data directory
 * (shared authentication and session database), the restricted config, and every optional OpenCode
 * feature switched off. `extra` is added last.
 *
 * OpenCode 2.x dropped several switches this once set (OPENCODE_PURE, OPENCODE_PERMISSION,
 * OPENCODE_TUI_CONFIG, OPENCODE_DISABLE_DEFAULT_PLUGINS, OPENCODE_DISABLE_EXTERNAL_SKILLS,
 * OPENCODE_DISABLE_LSP_DOWNLOAD, OPENCODE_DISABLE_CLAUDE_CODE_PROMPT, OPENCODE_DISABLE_SHARE). Its replacements
 * are the restricted config (no plugins, instructions or MCP servers, sharing disabled), the disabled project
 * config and the empty private HOME, which has no external skill folders.
 */
export function textModelEnvironment({ privateHome, configPath, configContent, extra = {} }) {
  if (typeof privateHome !== "string" || !isAbsolute(privateHome)) {
    throw new Error("The private OpenCode home must be an absolute path");
  }

  const additions = {};
  for (const [key, value] of Object.entries(extra ?? {})) {
    if (ISOLATION_KEYS.has(key) || key.startsWith("OPENCODE_DISABLE_")) {
      throw new Error(`Extra environment may not replace ${key}`);
    }
    if (value !== undefined && value !== null) additions[key] = String(value);
  }

  const originalHome = process.env.HOME || homedir();
  const authDataHome = process.env.XDG_DATA_HOME || join(originalHome, ".local", "share");
  return subprocessEnvironment({
    HOME: privateHome,
    XDG_CONFIG_HOME: join(privateHome, ".config"),
    XDG_DATA_HOME: authDataHome,
    OPENCODE_CONFIG_DIR: "",
    OPENCODE_CONFIG: configPath,
    OPENCODE_CONFIG_CONTENT: configContent,
    OPENCODE_DISABLE_PROJECT_CONFIG: "1",
    OPENCODE_DISABLE_CLAUDE_CODE_SKILLS: "1",
    OPENCODE_DISABLE_AUTOUPDATE: "1",
    OPENCODE_DISABLE_CLAUDE_CODE: "1",
    OPENCODE_DISABLE_MODELS_FETCH: "1",
    ...additions,
  });
}

/** Turkish message for the first error event of a call, by what the provider error means. */
export function defaultDescribeError(event) {
  if (isStartupFailure(event)) return "OpenCode başlatılamadı (geçici bir başlatma hatası).";

  switch (providerErrorKind(event)) {
    case "transient":
      return "Sağlayıcı geçici bir hata verdi (istek sınırı veya sunucu hatası).";
    case "account":
      return "Sağlayıcı hesabı isteği reddetti (anahtar, abonelik veya kullanım sınırı). Anahtarı, aboneliği ve sınırı kontrol edin.";
    case "policy":
      return POLICY_REFUSAL_MESSAGE;
    case "unavailable":
      return "Model sağlayıcıda bulunamadı veya kaldırıldı.";
    case "limit":
      return LIMIT_MESSAGE;
    default:
      return "Model isteği hata verdi.";
  }
}

function sessionIdOf(event) {
  for (const candidate of [event.sessionID, event.part?.sessionID]) {
    if (typeof candidate === "string" && SESSION_ID_PATTERN.test(candidate)) return candidate;
  }
  return null;
}

/** A token count of one step_finish: a non-negative safe integer, or null. */
function tokenCount(value) {
  return Number.isSafeInteger(value) && value >= 0 ? value : null;
}

/** Adds a step's count to a running total; an invalid count or an overflow leaves the total as it is. */
function addTokens(total, value) {
  if (value === null || !Number.isSafeInteger((total ?? 0) + value)) return total;

  return (total ?? 0) + value;
}

/**
 * Runs one tool-less OpenCode call and collects its text and usage. Never rejects for model or CLI
 * failures: the result says what happened. `onHandle({ abort(reason) })` is called once the CLI runs,
 * `onHandle(null)` once it has finished; `abort("stopped")` ends the call as stopped.
 *
 * Usage sums every step_finish. `inputTokens` and `outputTokens` are OpenCode's `tokens.input` and
 * `tokens.output` alone; `reasoningTokens`, `cacheReadTokens` and `cacheWriteTokens` are the other parts of
 * its total (a step that reports `tokens.input` without them counts 0 for them). Each stays null until a
 * valid value arrives. `stopReason` is the `reason` of the last step_finish ("length" means the answer hit
 * the output token limit), or null. OpenCode 2.x continues a cut-off answer by itself, so the last step can
 * look normal; `stopReason` is "length" whenever any step of the call was cut off or the call ended with
 * OpenCode's output-limit error, and the joined text of such a call is never a complete answer.
 */
async function runOpenCodeTextOnce({
  opencodeBinary,
  agent,
  model,
  directory,
  privateHome,
  configPath,
  configContent,
  prompt,
  timeoutSeconds,
  maxResultBytes = DEFAULT_MAX_RESULT_BYTES,
  maxEventBytes = DEFAULT_MAX_EVENT_BYTES,
  stopGraceMs = STOP_GRACE_MS,
  title = null,
  extraEnv = {},
  isStopRequested = () => false,
  onHandle = () => {},
  onText = () => {},
  describeError = defaultDescribeError,
}) {
  if (!Number.isFinite(timeoutSeconds) || timeoutSeconds <= 0) {
    throw new Error("Invalid OpenCode timeout");
  }

  const env = textModelEnvironment({ privateHome, configPath, configContent, extra: extraEnv });
  // --standalone keeps the call in its own server process with this environment instead of handing it to
  // the user's background OpenCode service; the working directory is the spawn cwd.
  const args = ["run", "--standalone", "--format", "json", "--agent", agent, "--model", model];
  if (typeof title === "string" && title !== "") {
    args.push("--title", title);
  }

  return new Promise((resolveRun) => {
    const textParts = new Map();
    let textBytes = 0;
    let eventBytes = 0;
    let buffer = "";
    let abortReason = null;
    let errorMessage = null;
    let errorEvent = null;
    let sessionId = null;
    let costUsd = null;
    let inputTokens = null;
    let outputTokens = null;
    let reasoningTokens = null;
    let cacheReadTokens = null;
    let cacheWriteTokens = null;
    let stopReason = null;
    let cutOff = false;
    let partCounter = 0;
    let closed = false;
    const started = Date.now();
    const startedAt = performance.now();
    const child = spawn(opencodeBinary, args, { cwd: directory, detached: true, env, stdio: ["pipe", "pipe", "pipe"] });
    let killTimer;
    let resolveCleanup;
    let cleanupDone = Promise.resolve();
    const timeoutTimer = setTimeout(() => abort("timeout"), timeoutSeconds * 1000);

    function abort(reason) {
      if (abortReason || closed) return;
      abortReason = reason;
      cleanupDone = new Promise((resolve) => { resolveCleanup = resolve; });
      signalChildGroup(child, "SIGTERM");
      killTimer = setTimeout(() => {
        if (isChildGroupAlive(child)) signalChildGroup(child, "SIGKILL");
        resolveCleanup();
      }, stopGraceMs);
    }

    onHandle({ abort });
    child.stdin.on("error", () => {});
    child.stderr.on("data", () => {});
    child.stdin.end(prompt);
    if (isStopRequested()) abort("stopped");

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
        return;
      }

      sessionId ??= sessionIdOf(event);
      if (isForbiddenEvent(event)) {
        errorMessage = "Model araç veya izin kullanımı denedi.";
        abort("forbidden-activity");
      } else if (isOutputLimitError(event)) {
        // OpenCode gave up after continuing the cut-off answer twice. The call ends by itself (exit 1); it is a
        // cut-off answer, not a provider error, so it is neither aborted nor reported as errorEvent.
        cutOff = true;
        errorMessage ??= OUTPUT_LIMIT_MESSAGE;
      } else if (event.type === "error") {
        errorEvent ??= event;
        errorMessage = describeError(event);
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
        if (textBytes > maxResultBytes - 1) {
          errorMessage = "Model yanıtı izin verilen boyutu aştı.";
          abort("result-limit");
        } else {
          try { onText(textParts.get(key)); } catch { /* a progress callback must not end the call */ }
        }
      } else if (event.type === "step_finish") {
        const cost = event.part?.cost;
        const tokens = event.part?.tokens;
        if (Number.isFinite(cost) && cost >= 0 && Number.isFinite((costUsd ?? 0) + cost)) {
          costUsd = (costUsd ?? 0) + cost;
        }
        const input = tokenCount(tokens?.input);
        // A step that reports its input tokens but no reasoning or cache field used none of them.
        const part = (value) => (value === undefined && input !== null ? 0 : tokenCount(value));
        inputTokens = addTokens(inputTokens, input);
        outputTokens = addTokens(outputTokens, tokenCount(tokens?.output));
        reasoningTokens = addTokens(reasoningTokens, part(tokens?.reasoning));
        cacheReadTokens = addTokens(cacheReadTokens, part(tokens?.cache?.read));
        cacheWriteTokens = addTokens(cacheWriteTokens, part(tokens?.cache?.write));
        const reason = event.part?.reason;
        stopReason = typeof reason === "string" && reason !== "" && reason.length <= MAX_STOP_REASON_CHARS ? reason : null;
        if (stopReason === "length") cutOff = true;
      }
    }

    child.stdout.setEncoding("utf8");
    child.stdout.on("data", (chunk) => {
      if (abortReason) return;
      eventBytes += Buffer.byteLength(chunk, "utf8");
      if (eventBytes > maxEventBytes) {
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
      closed = true;
      if (abortReason && !isChildGroupAlive(child)) {
        if (killTimer) clearTimeout(killTimer);
        resolveCleanup();
      }
      await cleanupDone;
      if (killTimer) clearTimeout(killTimer);
      onHandle(null);
      const text = [...textParts.values()].join("\n").trim();
      let status = "completed";
      if (abortReason === "stopped" || isStopRequested()) {
        status = "stopped";
        errorMessage = "Kullanıcı tarafından durduruldu.";
      } else if (abortReason || signal || code !== 0 || text === "") {
        status = "failed";
        errorMessage ??= code !== null && code !== 0
          ? `Model isteği başarısız oldu (CLI kodu ${Number.isInteger(code) ? code : "bilinmiyor"}).`
          : "Model yanıtı alınamadı.";
      }

      resolveRun({
        status,
        text,
        errorMessage,
        durationSeconds: Math.max(0, Math.ceil((Date.now() - started) / 1000)),
        elapsedMs: Math.max(0, performance.now() - startedAt),
        costUsd,
        inputTokens,
        outputTokens,
        reasoningTokens,
        cacheReadTokens,
        cacheWriteTokens,
        stopReason: cutOff ? "length" : stopReason,
        abortReason,
        exitCode: code,
        signal,
        sessionId,
        errorEvent,
      });
    });
  });
}

/**
 * Waits before the 1st, 2nd and 3rd new try of a call whose OpenCode server did not start (see
 * runOpenCodeText). The waits differ so that calls that failed together do not start together again.
 */
export const STARTUP_RETRY_DELAYS_MS = Object.freeze([300, 900, 2000]);

/**
 * Runs one tool-less OpenCode call (see runOpenCodeTextOnce). When the call's own server process dies while
 * it starts, which happens now and then when several standalone OpenCode processes start at the same moment,
 * the call is run again after a short wait, at most `startupRetryDelaysMs.length` times. Nothing was sent to
 * the provider and no session exists, so the new try cannot spend or repeat anything. After the last try the
 * failure is returned like any other (`errorEvent` is the startup error). A stop request (`isStopRequested()` or
 * an aborted `signal`) during the wait ends the call as stopped without starting another process.
 */
export async function runOpenCodeText({ startupRetryDelaysMs = STARTUP_RETRY_DELAYS_MS, signal = undefined, ...options }) {
  const stopRequested = () => signal?.aborted === true || options.isStopRequested?.() === true;

  for (let retry = 0; ; retry += 1) {
    const result = await runOpenCodeTextOnce(options);
    const startedNothing = result.status === "failed" && result.errorEvent !== null && isStartupFailure(result.errorEvent)
      && result.sessionId === null && result.text === "";

    if (!startedNothing || retry >= startupRetryDelaysMs.length) return result;

    // A stop request ends the call without starting anything new; `signal` also wakes the wait.
    await pause(startupRetryDelaysMs[retry], signal);
    if (stopRequested()) return { ...result, status: "stopped", errorMessage: "Kullanıcı tarafından durduruldu." };
  }
}

const MAX_MODEL_LIST_BYTES = 16 * 1024 * 1024;

/** Splits "provider/model" at the first slash; the model id may contain slashes itself. */
function splitModel(model) {
  const slash = typeof model === "string" ? model.indexOf("/") : -1;

  return slash > 0 && slash < model.length - 1 ? { providerId: model.slice(0, slash), modelId: model.slice(slash + 1) } : null;
}

const positiveInteger = (value) => Number.isSafeInteger(value) && value > 0;

/**
 * The token limits OpenCode resolved for `model` from the JSON of `opencode api GET /api/model`, or null.
 * An override needs the context size as well as the output size, so a model without both is unknown.
 */
export function modelLimitFrom(json, model) {
  const parts = splitModel(model);
  if (parts === null) return null;

  let models;
  try {
    models = JSON.parse(json)?.data;
  } catch {
    return null;
  }

  const entry = Array.isArray(models)
    ? models.find((candidate) => candidate?.providerID === parts.providerId && candidate?.modelID === parts.modelId)
    : null;
  const limit = entry?.limit;
  if (!positiveInteger(limit?.context) || !positiveInteger(limit?.output)) return null;

  return { context: limit.context, output: limit.output, ...(positiveInteger(limit.input) ? { input: limit.input } : {}) };
}

/** Pauses before the next lookup try; resolves early when `signal` aborts. */
function pause(milliseconds, signal) {
  return new Promise((resolvePause) => {
    const timer = setTimeout(done, milliseconds);
    function done() {
      clearTimeout(timer);
      signal?.removeEventListener("abort", done);
      resolvePause();
    }
    signal?.addEventListener("abort", done, { once: true });
  });
}

/**
 * One `opencode api GET /api/model` run. `retry` is true only when the process itself failed (it exited with an
 * error on its own): several standalone OpenCode processes started at the same moment sometimes fail with
 * "Standalone server exited before reporting readiness", and a second try works.
 */
function listModels({ opencodeBinary, model, directory, env, timeoutSeconds, stopGraceMs, signal }) {
  return new Promise((resolveList) => {
    if (signal?.aborted) {
      resolveList({ limit: null, retry: false });
      return;
    }

    let output = "";
    let stopped = false;
    let killTimer;
    let child;
    const stop = () => {
      if (stopped) return;
      stopped = true;
      signalChildGroup(child, "SIGTERM");
      killTimer = setTimeout(() => {
        if (isChildGroupAlive(child)) signalChildGroup(child, "SIGKILL");
      }, stopGraceMs);
    };

    try {
      child = spawn(opencodeBinary, ["api", "--standalone", "GET", "/api/model"], { cwd: directory, detached: true, env, stdio: ["ignore", "pipe", "ignore"] });
    } catch {
      resolveList({ limit: null, retry: false });
      return;
    }

    const timeoutTimer = setTimeout(stop, timeoutSeconds * 1000);
    signal?.addEventListener("abort", stop, { once: true });
    child.stdout.setEncoding("utf8");
    child.stdout.on("data", (chunk) => {
      if (stopped) return;
      output += chunk;
      if (output.length > MAX_MODEL_LIST_BYTES) stop();
    });
    child.once("error", () => {
      clearTimeout(timeoutTimer);
      signal?.removeEventListener("abort", stop);
      resolveList({ limit: null, retry: false });
    });
    child.once("close", (code, closeSignal) => {
      clearTimeout(timeoutTimer);
      signal?.removeEventListener("abort", stop);
      if (killTimer) clearTimeout(killTimer);
      // Whatever of the process group outlived the process (a stopped lookup may leave a child that ignored SIGTERM).
      if (isChildGroupAlive(child)) signalChildGroup(child, "SIGKILL");
      const finished = code === 0 && closeSignal === null && !stopped;
      resolveList({ limit: finished ? modelLimitFrom(output, model) : null, retry: !finished && !stopped && closeSignal === null });
    });
  });
}

/** Waits before the second and third try of a failed model limit lookup. */
export const LOOKUP_RETRY_DELAYS_MS = Object.freeze([400, 1200]);

/**
 * Asks OpenCode for the real context and output limits of `model`, with the environment a call of that model
 * runs in (the host's credentials are needed to list a provider's models). Resolves null when the limits cannot
 * be read: the model is not listed, the lookup runs past `timeoutSeconds`, `signal` aborts, or the process kept
 * failing. A process that failed by itself is tried again after each of `retryDelaysMs`. Never rejects.
 */
export async function lookupModelLimit({
  opencodeBinary, model, directory, privateHome, configPath, configContent,
  timeoutSeconds = 30, stopGraceMs = STOP_GRACE_MS, retryDelaysMs = LOOKUP_RETRY_DELAYS_MS, signal,
}) {
  if (splitModel(model) === null) return null;

  let env;
  try {
    env = textModelEnvironment({ privateHome, configPath, configContent });
  } catch {
    return null;
  }

  for (let attempt = 0; ; attempt += 1) {
    const { limit, retry } = await listModels({ opencodeBinary, model, directory, env, timeoutSeconds, stopGraceMs, signal });
    if (limit !== null || !retry || attempt >= retryDelaysMs.length || signal?.aborted) return limit;

    await pause(retryDelaysMs[attempt], signal);
  }
}

/**
 * The call configuration with the model's answer limited to `cap` tokens. OpenCode 2.x has no switch that caps
 * one answer (1.x had OPENCODE_EXPERIMENTAL_OUTPUT_TOKEN_MAX); an answer is limited by the model's
 * `limit.output`, and a limit override needs the model's real `limit.context` too. A cap that is not below the
 * model's own limit changes nothing, so the configuration is returned as it is.
 */
export function withOutputCap(configContent, model, limit, cap) {
  const parts = splitModel(model);
  if (parts === null || limit === null || !positiveInteger(cap) || cap >= limit.output) return configContent;

  const config = JSON.parse(configContent);
  const providers = (config.provider ??= {});
  const provider = (providers[parts.providerId] ??= {});
  const models = (provider.models ??= {});
  models[parts.modelId] = { ...models[parts.modelId], limit: { ...limit, output: cap } };

  return JSON.stringify(config);
}
