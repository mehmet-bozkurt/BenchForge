import { spawn } from "node:child_process";
import { homedir } from "node:os";
import { isAbsolute, join } from "node:path";
import { performance } from "node:perf_hooks";
import { providerErrorKind } from "../engine/tools/classify-opencode-failure.mjs";
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

/** Keys that keep the call isolated from the host OpenCode setup; `extra` may not replace them. */
const ISOLATION_KEYS = new Set([
  "HOME", "XDG_CONFIG_HOME", "XDG_DATA_HOME", "OPENCODE_CONFIG_DIR", "OPENCODE_TUI_CONFIG",
  "OPENCODE_PERMISSION", "OPENCODE_CONFIG", "OPENCODE_CONFIG_CONTENT", "OPENCODE_PURE",
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
 * feature switched off. `extra` is added last, for example OPENCODE_EXPERIMENTAL_OUTPUT_TOKEN_MAX.
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
    OPENCODE_TUI_CONFIG: "",
    OPENCODE_PERMISSION: "",
    OPENCODE_CONFIG: configPath,
    OPENCODE_CONFIG_CONTENT: configContent,
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
    ...additions,
  });
}

/** Turkish message for the first error event of a call, by what the provider error means. */
export function defaultDescribeError(event) {
  switch (providerErrorKind(event)) {
    case "transient":
      return "Sağlayıcı geçici bir hata verdi (istek sınırı veya sunucu hatası).";
    case "account":
      return "Sağlayıcı hesabı isteği reddetti (401/403). Anahtarı veya aboneliği kontrol edin.";
    case "policy":
      return POLICY_REFUSAL_MESSAGE;
    case "unavailable":
      return "Model sağlayıcıda bulunamadı veya kaldırıldı.";
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
 * the output token limit), or null.
 */
export async function runOpenCodeText({
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
  const args = ["run", "--pure", "--format", "json", "--agent", agent, "--model", model, "--dir", directory];
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
        stopReason,
        abortReason,
        exitCode: code,
        signal,
        sessionId,
        errorEvent,
      });
    });
  });
}
