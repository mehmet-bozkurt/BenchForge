import { resolve } from "node:path";
import { runWorkflowWorker } from "./workflow/engine.mjs";

if (process.env.BENCHFORGE_RUNNER !== "1" || process.argv.length !== 3) {
  process.exit(64);
}

try {
  process.exitCode = await runWorkflowWorker(resolve(process.argv[2]));
} catch {
  // Job content, templates and provider errors must not reach process logs.
  process.stderr.write("Workflow worker could not complete.\n");
  process.exitCode = 1;
}
