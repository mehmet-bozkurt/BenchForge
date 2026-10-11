import { homedir } from "node:os";
import { join } from "node:path";

const INHERITED_KEYS = Object.freeze([
  "PATH", "HOME", "TMPDIR", "TMP", "TEMP", "LANG", "LANGUAGE",
  "LC_ALL", "LC_CTYPE", "LC_NUMERIC", "LC_TIME", "LC_COLLATE", "LC_MONETARY",
  "LC_MESSAGES", "LC_PAPER", "LC_NAME", "LC_ADDRESS", "LC_TELEPHONE",
  "LC_MEASUREMENT", "LC_IDENTIFICATION", "TZ", "TERM", "COLORTERM", "NO_COLOR",
  "XDG_DATA_HOME", "XDG_CONFIG_HOME", "XDG_CACHE_HOME", "XDG_STATE_HOME",
]);

// Only these operational values may cross a subprocess boundary implicitly.
// Credentials, application settings and runtime injection hooks are not inherited.
export function inheritedEnvironment(source = process.env) {
  const environment = {};

  for (const key of INHERITED_KEYS) {
    if (typeof source[key] === "string") environment[key] = source[key];
  }

  return environment;
}

// The caller, not an arbitrary prefix in the inherited environment, owns additions.
export function subprocessEnvironment(additions = {}, source = process.env) {
  return { ...inheritedEnvironment(source), ...additions };
}

export function modelEnvironment(additions = {}, source = process.env) {
  return {
    ...subprocessEnvironment(additions, source),
    OPENCODE_DISABLE_PROJECT_CONFIG: "1",
    OPENCODE_PURE: "1",
    OPENCODE_DISABLE_EXTERNAL_SKILLS: "1",
    OPENCODE_DISABLE_CLAUDE_CODE_SKILLS: "1",
  };
}

// "legacy" = the model sees the host user's OpenCode configuration (runs before isolated-v1).
// "isolated-v1" = a private per-model HOME and state, plus a BenchForge-owned OpenCode config directory
// shared by all models (OpenCode installs its plugin SDK there once instead of once per model).
// The host user's OpenCode configuration is never read; auth, sessions, usage DB and caches stay shared.
export const ENVIRONMENT_PROFILES = Object.freeze(["legacy", "isolated-v1"]);

export function isolatedModelEnvironment(additions = {}, privateHome, source = process.env) {
  if (typeof privateHome !== "string" || !privateHome.startsWith("/")) {
    throw new Error("An isolated model environment needs an absolute private home directory.");
  }

  const hostHome = source.HOME || homedir();
  const cacheHome = source.XDG_CACHE_HOME || join(hostHome, ".cache");

  return modelEnvironment({
    ...additions,
    HOME: privateHome,
    XDG_CONFIG_HOME: join(cacheHome, "benchforge", "opencode-config"),
    XDG_STATE_HOME: join(privateHome, ".local", "state"),
    XDG_DATA_HOME: source.XDG_DATA_HOME || join(hostHome, ".local", "share"),
    XDG_CACHE_HOME: cacheHome,
    npm_config_cache: join(hostHome, ".npm"),
    NEXT_TELEMETRY_DISABLED: "1",
    OPENCODE_DISABLE_AUTOUPDATE: "1",
    OPENCODE_DISABLE_CLAUDE_CODE: "1",
    OPENCODE_DISABLE_CLAUDE_CODE_PROMPT: "1",
    OPENCODE_DISABLE_SHARE: "1",
  }, source);
}

export function runnerControls(source = process.env) {
  const controls = {};
  const timeout = Number(source.BENCHFORGE_ATTEMPT_TIMEOUT_SECONDS);

  if (Number.isInteger(timeout) && timeout >= 60 && timeout <= 7200) {
    controls.BENCHFORGE_ATTEMPT_TIMEOUT_SECONDS = String(timeout);
  }
  if (source.BENCHFORGE_SKIP_BROWSER_SMOKE === "1") controls.BENCHFORGE_SKIP_BROWSER_SMOKE = "1";

  return controls;
}
