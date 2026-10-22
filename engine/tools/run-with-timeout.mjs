import { spawn } from "node:child_process";
import { constants } from "node:os";
import { resolve } from "node:path";
import { fileURLToPath } from "node:url";

/** Exit code of a step that reached its time limit; the same code a model timeout uses. */
export const TIMEOUT_EXIT_CODE = 124;
const KILL_GRACE_MS = 5000;

/**
 * Runs one validation step (dependency install, lint or build) with a time limit.
 * The command gets its own process group, so a server or test script it starts cannot keep the
 * step open: when the limit is reached the whole group is stopped and the step exits with 124.
 * Whatever the command leaves running after it exits is stopped too.
 */
export function runWithTimeout(limitSeconds, command, args) {
  return new Promise((resolveExit) => {
    const child = spawn(command, args, { detached: true, stdio: "inherit" });
    let timedOut = false;
    let killTimer;

    const signalGroup = (signal) => {
      try {
        process.kill(-child.pid, signal);
      } catch {
        // The group is already gone.
      }
    };
    const stop = (signal) => {
      signalGroup(signal);
      killTimer ??= setTimeout(() => signalGroup("SIGKILL"), KILL_GRACE_MS);
    };
    const timer = setTimeout(() => {
      timedOut = true;
      process.stderr.write(`\nBENCHFORGE: Adim ${limitSeconds} saniyelik sure sinirini asti; komut ve baslattigi surecler durduruldu.\n`);
      stop("SIGTERM");
    }, limitSeconds * 1000);
    const forward = (signal) => () => stop(signal);
    const forwarders = Object.fromEntries(["SIGTERM", "SIGINT", "SIGHUP"].map((signal) => [signal, forward(signal)]));
    for (const [signal, handler] of Object.entries(forwarders)) process.on(signal, handler);

    const finish = (code) => {
      clearTimeout(timer);
      clearTimeout(killTimer);
      // Nothing the step started may outlive it.
      signalGroup("SIGKILL");
      for (const [signal, handler] of Object.entries(forwarders)) process.off(signal, handler);
      resolveExit(code);
    };

    child.once("error", (error) => {
      process.stderr.write(`BENCHFORGE: ${command} baslatilamadi: ${error.message}\n`);
      finish(127);
    });
    child.once("exit", (code, signal) => {
      if (timedOut) return finish(TIMEOUT_EXIT_CODE);
      finish(code ?? 128 + (constants.signals[signal] ?? 0));
    });
  });
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const [limitArgument, command, ...args] = process.argv.slice(2);
  const limitSeconds = Number(limitArgument);

  if (!Number.isInteger(limitSeconds) || limitSeconds < 1 || !command) {
    process.stderr.write("Kullanim: node run-with-timeout.mjs <saniye> <komut> [arguman...]\n");
    process.exit(64);
  }

  process.exit(await runWithTimeout(limitSeconds, command, args));
}
