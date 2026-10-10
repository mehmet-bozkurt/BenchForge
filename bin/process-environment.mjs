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

export function runnerControls(source = process.env) {
  const controls = {};
  const timeout = Number(source.BENCHFORGE_ATTEMPT_TIMEOUT_SECONDS);

  if (Number.isInteger(timeout) && timeout >= 60 && timeout <= 7200) {
    controls.BENCHFORGE_ATTEMPT_TIMEOUT_SECONDS = String(timeout);
  }
  if (source.BENCHFORGE_SKIP_BROWSER_SMOKE === "1") controls.BENCHFORGE_SKIP_BROWSER_SMOKE = "1";

  return controls;
}
