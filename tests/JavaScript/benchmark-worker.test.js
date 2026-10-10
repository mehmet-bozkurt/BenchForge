import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const workerPath = join(dirname(fileURLToPath(import.meta.url)), '../../bin/benchmark-worker.mjs');

for (const shell of [
    { name: 'configured shell', value: '/bin/sh', expected: 'configured-shell' },
    { name: 'legacy manifest without a shell', expected: 'legacy-zsh' },
    { name: 'missing configured shell', value: '/missing/benchforge-zsh', expected: null },
    { name: 'empty configured shell', value: '', expected: null },
    { name: 'null configured shell', value: null, expected: null },
]) {
    test(`uses ${shell.name} without silently replacing an explicit selection`, async () => {
        const fixtureRoot = await mkdtemp(join(tmpdir(), 'benchforge-benchmark-shell-'));
        const jobPath = join(fixtureRoot, 'job.json');
        const statePath = join(fixtureRoot, 'state.json');
        const lockPath = join(fixtureRoot, 'active.lock');
        const logPath = join(fixtureRoot, 'runner.log');
        const scriptPath = join(fixtureRoot, 'fixture.sh');
        let worker;

        try {
            await writeFile(scriptPath, 'if [ -n "${ZSH_VERSION:-}" ]; then printf legacy-zsh; else printf configured-shell; fi\n');
            await writeFile(lockPath, 'locked\n');
            const job = {
                statePath,
                lockPath,
                logPath,
                projectRoot: fixtureRoot,
                scriptPath,
                runId: 'fixture-run',
                promptPath: '-',
                promptRelative: 'fixture',
                configPath: '-',
                configRelative: 'fixture',
                openCodeConfigPath: '-',
                routes: ['/'],
                modelCount: 1,
                startedAt: new Date().toISOString(),
            };
            if (Object.hasOwn(shell, 'value')) job.shellBinary = shell.value;
            await writeFile(jobPath, JSON.stringify(job));

            worker = spawn(process.execPath, [workerPath, jobPath], {
                env: { ...process.env, BENCHFORGE_RUNNER: '1' },
                stdio: 'ignore',
            });
            const [exitCode, signal] = await once(worker, 'exit');
            const state = JSON.parse(await readFile(statePath, 'utf8'));

            assert.equal(signal, null);
            assert.equal(exitCode, shell.expected === null ? 1 : 0);
            assert.equal(state.status, shell.expected === null ? 'failed' : 'completed');
            if (shell.name === 'missing configured shell') {
                assert.match(state.message, /^Benchmark başlatılamadı: .*ENOENT/);
            }
            assert.equal(await readFile(logPath, 'utf8'), shell.expected ?? '');
            await assert.rejects(readFile(jobPath, 'utf8'), { code: 'ENOENT' });
            await assert.rejects(readFile(lockPath, 'utf8'), { code: 'ENOENT' });
        } finally {
            if (worker?.exitCode === null && worker.signalCode === null) {
                const exited = once(worker, 'exit');
                worker.kill('SIGTERM');
                await exited;
            }
            await rm(fixtureRoot, { recursive: true, force: true });
        }
    });
}
