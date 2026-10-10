import { spawn } from "node:child_process";
import { runnerControls, subprocessEnvironment } from "./process-environment.mjs";

const [workerPath, jobPath] = process.argv.slice(2);

if (!workerPath || !jobPath) {
  process.exit(64);
}

const child = spawn(process.execPath, [workerPath, jobPath], {
  cwd: process.cwd(),
  detached: true,
  stdio: "ignore",
  env: subprocessEnvironment({ ...runnerControls(), BENCHFORGE_RUNNER: "1" }),
});

if (!child.pid) {
  process.exit(1);
}

child.unref();
process.stdout.write(String(child.pid));
