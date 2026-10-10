import { resolve } from "node:path";

const [targetDirectory, expectedTitle = ""] = process.argv.slice(2);

if (!targetDirectory) {
  process.exit(64);
}

let input = "";

for await (const chunk of process.stdin) {
  input += chunk;
}

try {
  const normalizedInput = input.replace(
    /\x1B(?:[@-Z\\-_]|\[[0-?]*[ -/]*[@-~])/g,
    "",
  );
  const sessions = JSON.parse(normalizedInput);
  const directory = resolve(targetDirectory);
  const matches = Array.isArray(sessions)
    ? sessions.filter((session) => {
        if (!session || typeof session !== "object") return false;
        if (typeof session.id !== "string" || !/^ses_[A-Za-z0-9]+$/.test(session.id)) return false;
        if (typeof session.directory !== "string" || resolve(session.directory) !== directory) return false;

        return expectedTitle === "" || session.title === expectedTitle;
      })
    : [];

  matches.sort((left, right) => {
    const leftTime = Number(left.updated ?? left.created ?? 0);
    const rightTime = Number(right.updated ?? right.created ?? 0);

    return rightTime - leftTime;
  });

  if (matches[0]) {
    process.stdout.write(matches[0].id);
  }
} catch {
  process.exit(1);
}
