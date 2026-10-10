import { readFile } from "node:fs/promises";

const eventLog = process.argv[2];

if (!eventLog) {
  process.exit(64);
}

try {
  const lines = (await readFile(eventLog, "utf8")).split(/\r?\n/);

  for (const line of lines) {
    if (line.trim() === "") continue;

    try {
      const event = JSON.parse(line);

      if (typeof event.sessionID === "string" && /^ses_[A-Za-z0-9]+$/.test(event.sessionID)) {
        process.stdout.write(event.sessionID);
        process.exit(0);
      }
    } catch {
      // Ignore non-JSON diagnostics and continue scanning the event stream.
    }
  }
} catch {
  process.exit(1);
}
