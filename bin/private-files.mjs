import { randomBytes } from "node:crypto";
import { link, lstat, readFile, rename, unlink, writeFile } from "node:fs/promises";
import { dirname, isAbsolute, join, relative, resolve, sep } from "node:path";

// Workers keep every file of a job inside one private directory. These guards refuse paths that leave it,
// pass through a symlink or have an unexpected type, and replace files only through a private temp file.

let temporaryCounter = 0;

export function withinRoot(root, path) {
  if (typeof path !== "string" || !isAbsolute(path) || resolve(path) !== path) {
    throw new Error("Invalid job path");
  }

  const suffix = relative(root, path);
  if (!suffix || suffix === ".." || suffix.startsWith(`..${sep}`) || isAbsolute(suffix)) {
    throw new Error("Job path is outside its private directory");
  }

  return suffix;
}

export async function checkPath(root, path, kind, allowMissing = false) {
  const suffix = withinRoot(root, path);
  const parts = suffix.split(sep);
  let cursor = root;

  for (let index = 0; index < parts.length; index += 1) {
    cursor = join(cursor, parts[index]);
    let entry;

    try {
      entry = await lstat(cursor);
    } catch (error) {
      if (error.code === "ENOENT" && allowMissing && index === parts.length - 1) {
        return false;
      }
      throw error;
    }

    if (entry.isSymbolicLink()) {
      throw new Error("Symlink in private job path");
    }

    if (index < parts.length - 1 && !entry.isDirectory()) {
      throw new Error("Non-directory in private job path");
    }

    if (index === parts.length - 1 && !(kind === "file" ? entry.isFile() : entry.isDirectory())) {
      throw new Error("Unexpected job path type");
    }
  }

  return true;
}

export async function readBounded(path, byteLimit) {
  const metadata = await lstat(path);
  if (!metadata.isFile() || metadata.isSymbolicLink() || metadata.size > byteLimit) {
    throw new Error("Invalid private input file");
  }

  return readFile(path, "utf8");
}

export async function atomicWrite(root, path, content, replaceExisting = true) {
  await checkPath(root, path, "file", true);
  const temporaryPath = join(dirname(path), `.benchforge-${process.pid}-${temporaryCounter += 1}-${randomBytes(6).toString("hex")}.tmp`);
  await checkPath(root, temporaryPath, "file", true);
  try {
    await writeFile(temporaryPath, content, { encoding: "utf8", mode: 0o600, flag: "wx" });
    await checkPath(root, path, "file", true);
    if (replaceExisting) {
      await rename(temporaryPath, path);
    } else {
      await link(temporaryPath, path);
      await unlink(temporaryPath);
    }
  } catch (error) {
    // The private directory is retained for diagnosis; leave no partial result.
    try { await unlink(temporaryPath); } catch { /* best effort */ }
    throw error;
  }
}
