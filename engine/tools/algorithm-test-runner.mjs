import { copyFile, readFile, writeFile } from 'node:fs/promises';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { run } from 'node:test';

/** Per-test limit; the whole step is additionally limited by run-with-timeout.mjs in the benchmark script. */
export const TEST_TIMEOUT_MS = 20_000;
/** Files restored from the task before every verification, so the model cannot change what is being graded. */
export const PROTECTED_FILES = ['jest-shim.mjs', 'package.json'];

async function sameContents(left, right) {
    try {
        const [a, b] = await Promise.all([readFile(left), readFile(right)]);
        return a.equals(b);
    } catch {
        return false;
    }
}

/**
 * Puts the task's original test file, Jest shim and package.json back into the workspace and reports whether
 * the model had changed any of them.
 */
export async function restoreProtectedFiles(taskWorkspace, workspace, testFile) {
    const tampered = [];

    for (const name of [testFile, ...PROTECTED_FILES]) {
        const original = join(taskWorkspace, name);
        const current = join(workspace, name);

        if (!(await sameContents(original, current))) {
            tampered.push(name);
        }

        await copyFile(original, current);
    }

    return tampered;
}

/**
 * Runs the task's test file with node:test and counts its leaf tests. Skipped tests do not count as passed.
 */
export async function runTaskTests({ workspace, testFile }) {
    const events = run({ files: [join(workspace, testFile)], cwd: workspace, timeout: TEST_TIMEOUT_MS });
    const failures = [];
    let passed = 0;
    let failed = 0;
    let skipped = 0;

    events.on('test:pass', (data) => {
        if (data.details?.type === 'suite') {
            return;
        }

        if (data.skip !== undefined || data.todo !== undefined) {
            skipped += 1;
        } else {
            passed += 1;
        }
    });
    events.on('test:fail', (data) => {
        if (data.details?.type === 'suite') {
            return;
        }

        failed += 1;
        const reason = data.details?.error?.cause?.message ?? data.details?.error?.message ?? 'Test basarisiz.';
        failures.push({ name: data.name, message: String(reason).split('\n').slice(0, 6).join('\n') });
    });
    events.on('test:stderr', (data) => process.stderr.write(data.message));
    events.on('test:stdout', (data) => process.stdout.write(data.message));

    for await (const event of events) {
        void event;
    }

    return { passed, failed, skipped, failures: failures.slice(0, 20) };
}

/**
 * Verifies one workspace: restores the protected files, runs the tests and returns the result. `expectedTotal`
 * is the number of tests the task has; a solution that breaks the whole file (syntax error) fails all of them.
 */
export async function verifyWorkspace({ taskDirectory, workspace }) {
    const task = JSON.parse(await readFile(join(taskDirectory, 'task.json'), 'utf8'));
    const tampered = await restoreProtectedFiles(join(taskDirectory, 'workspace'), workspace, task.testFile);
    const started = Date.now();
    const counts = await runTaskTests({ workspace, testFile: task.testFile });
    const total = Math.max(task.testCount ?? 0, counts.passed + counts.failed + counts.skipped);

    return {
        task: task.slug,
        passed: counts.passed,
        failed: total - counts.passed,
        skipped: counts.skipped,
        total,
        tampered,
        durationMs: Date.now() - started,
        failures: counts.failures,
    };
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    const [taskDirectory, workspace, resultPath, ...extra] = process.argv.slice(2);

    if (!taskDirectory || !workspace || !resultPath || extra.length > 0) {
        console.error('Usage: algorithm-test-runner.mjs <task-directory> <workspace> <result-json>');
        process.exitCode = 64;
    } else {
        try {
            const result = await verifyWorkspace({ taskDirectory: resolve(taskDirectory), workspace: resolve(workspace) });
            await writeFile(resultPath, `${JSON.stringify(result, null, 2)}\n`, 'utf8');

            console.log(`BENCHFORGE_TESTS passed=${result.passed} total=${result.total}`);

            if (result.tampered.length > 0) {
                console.log(`Model su dosyalari degistirdi; dogrulamada orijinalleri kullanildi: ${result.tampered.join(', ')}`);
            }

            for (const failure of result.failures) {
                console.log(`BASARISIZ: ${failure.name}\n${failure.message}`);
            }

            process.exitCode = result.total > 0 && result.passed === result.total ? 0 : 1;
        } catch (error) {
            console.error(`HATA: Testler calistirilamadi: ${error.message}`);
            process.exitCode = 2;
        }
    }
}
