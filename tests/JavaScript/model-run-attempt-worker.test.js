import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { mkdtemp, mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const workerPath = join(dirname(fileURLToPath(import.meta.url)), '../../bin/model-run-attempt-worker.mjs');

function isProcessRunning(pid) {
    try {
        process.kill(pid, 0);

        return true;
    } catch (error) {
        if (error.code === 'ESRCH') {
            return false;
        }

        throw error;
    }
}

test('stops an overdue attempt and cleans up its child process', async () => {
    const fixtureRoot = await mkdtemp(join(tmpdir(), 'benchforge-attempt-timeout-'));
    const attemptPath = join(fixtureRoot, 'attempt');
    const jobPath = join(fixtureRoot, 'job.json');
    const statePath = join(fixtureRoot, 'state.json');
    const lockPath = join(fixtureRoot, 'attempt.lock');
    const logPath = join(fixtureRoot, 'runner.log');
    const scriptPath = join(fixtureRoot, 'long-running.zsh');
    let worker;

    try {
        await mkdir(attemptPath);
        await writeFile(scriptPath, 'while true; do sleep 1; done\n', { mode: 0o700 });
        await writeFile(lockPath, 'locked\n');
        await writeFile(jobPath, JSON.stringify({
            statePath,
            lockPath,
            logPath,
            scriptPath,
            runPath: join(fixtureRoot, 'fixture-run'),
            projectRoot: fixtureRoot,
            modelSlug: 'fixture-model',
            model: 'fixture/no-model',
            port: 0,
            routes: ['/'],
            openCodeConfigPath: join(fixtureRoot, 'unused-config.json'),
            attemptPath,
            mode: 'validate',
            sourceSessionId: '',
            promptPath: join(fixtureRoot, 'unused-prompt.txt'),
            attemptId: 1,
            startedAt: new Date(Date.now() - 61_000).toISOString(),
        }));

        worker = spawn(process.execPath, [workerPath, jobPath], {
            cwd: fixtureRoot,
            detached: true,
            env: {
                ...process.env,
                BENCHFORGE_RUNNER: '1',
                BENCHFORGE_ATTEMPT_TIMEOUT_SECONDS: '60',
            },
            stdio: 'ignore',
        });

        const timeout = setTimeout(() => {
            if (worker.pid) {
                process.kill(-worker.pid, 'SIGKILL');
            }
        }, 10_000);

        try {
            const [exitCode, signal] = await once(worker, 'exit');
            const state = JSON.parse(await readFile(statePath, 'utf8'));

            assert.equal(exitCode, 0);
            assert.equal(signal, null);
            assert.equal(state.status, 'stopped');
            assert.equal(state.timeoutSeconds, 60);
            assert.match(state.message, /Güvenlik süresi dolduğu/);
            assert.ok(Number.isInteger(state.processPid));
            assert.equal(isProcessRunning(state.processPid), false);
            await assert.rejects(readFile(lockPath, 'utf8'), { code: 'ENOENT' });
            await assert.rejects(readFile(jobPath, 'utf8'), { code: 'ENOENT' });
        } finally {
            clearTimeout(timeout);
        }
    } finally {
        if (worker?.pid && worker.exitCode === null && worker.signalCode === null) {
            try {
                process.kill(-worker.pid, 'SIGKILL');
            } catch (error) {
                if (error.code !== 'ESRCH') {
                    throw error;
                }
            }
        }

        await rm(fixtureRoot, { recursive: true, force: true });
    }
});

for (const mode of ['continue', 'validate']) {
    test(`keeps ${mode} validation completion intact and only reports its own boundary stop`, async () => {
        const fixtureRoot = await mkdtemp(join(tmpdir(), 'benchforge-attempt-boundary-'));
        const attemptPath = join(fixtureRoot, 'attempt');
        const jobPath = join(fixtureRoot, 'job.json');
        const statePath = join(fixtureRoot, 'state.json');
        const scriptPath = join(fixtureRoot, 'completed-attempt.zsh');
        let unrelated;

        try {
            await mkdir(attemptPath);
            unrelated = spawn(process.execPath, ['-e', 'setInterval(() => {}, 1000)'], { stdio: 'ignore' });
            await writeFile(join(attemptPath, 'boundary-report.json'), JSON.stringify({
                version: 1,
                stoppedForBoundary: true,
                blockedCount: 3,
                processPid: unrelated.pid,
                entries: [],
            }));
            await writeFile(scriptPath, 'print -r -- "$BENCHFORGE_BOUNDARY_MONITOR"\nexit 0\n');
            await writeFile(jobPath, JSON.stringify({
                statePath,
                lockPath: join(fixtureRoot, 'attempt.lock'),
                logPath: join(fixtureRoot, 'runner.log'),
                scriptPath,
                runPath: join(fixtureRoot, 'fixture-run'),
                projectRoot: fixtureRoot,
                modelSlug: 'fixture-model',
                model: 'fixture/no-model',
                port: 0,
                routes: ['/'],
                openCodeConfigPath: join(fixtureRoot, 'unused-config.json'),
                attemptPath,
                mode,
                sourceSessionId: '',
                promptPath: join(fixtureRoot, 'unused-prompt.txt'),
                attemptId: 1,
                startedAt: new Date().toISOString(),
            }));

            const worker = spawn(process.execPath, [workerPath, jobPath], {
                env: { ...process.env, BENCHFORGE_RUNNER: '1', BENCHFORGE_ATTEMPT_TIMEOUT_SECONDS: '' },
                stdio: 'ignore',
            });
            const [code, signal] = await once(worker, 'exit');
            const state = JSON.parse(await readFile(statePath, 'utf8'));

            assert.equal(code, 0);
            assert.equal(signal, null);
            assert.equal(state.status, 'completed');
            assert.equal(state.exitCode, 0);
            // The default safety net leaves room for the model time added back for provider stalls.
            assert.equal(state.timeoutSeconds, 7200 + 45 * 60);
            if (mode === 'continue') {
                assert.match(state.message, /Tekrarlanan çalışma alanı dışı erişim reddi/);
            } else {
                assert.equal(state.message, 'Deneme tamamlandı ve doğrulama sonuçları kaydedildi.');
            }
            assert.match(await readFile(join(fixtureRoot, 'runner.log'), 'utf8'), /bin\/model-boundary-monitor\.mjs/);
            assert.equal(isProcessRunning(unrelated.pid), true);
            await assert.rejects(readFile(jobPath, 'utf8'), { code: 'ENOENT' });
        } finally {
            if (unrelated?.exitCode === null && unrelated.signalCode === null) {
                const exited = once(unrelated, 'exit');
                unrelated.kill('SIGTERM');
                await exited;
            }
            await rm(fixtureRoot, { recursive: true, force: true });
        }
    });
}

for (const shell of [
    { name: 'configured shell', value: '/bin/sh', expected: 'configured-shell' },
    { name: 'legacy manifest without a shell', expected: 'legacy-zsh' },
    { name: 'missing configured shell', value: '/missing/benchforge-zsh', expected: null },
    { name: 'empty configured shell', value: '', expected: null },
    { name: 'null configured shell', value: null, expected: null },
]) {
    test(`uses ${shell.name} without silently replacing an explicit selection`, async () => {
        const fixtureRoot = await mkdtemp(join(tmpdir(), 'benchforge-attempt-shell-'));
        const attemptPath = join(fixtureRoot, 'attempt');
        const jobPath = join(fixtureRoot, 'job.json');
        const statePath = join(fixtureRoot, 'state.json');
        const lockPath = join(fixtureRoot, 'attempt.lock');
        const logPath = join(fixtureRoot, 'runner.log');
        const scriptPath = join(fixtureRoot, 'fixture.sh');
        let worker;

        try {
            await mkdir(attemptPath);
            await writeFile(scriptPath, 'if [ -n "${ZSH_VERSION:-}" ]; then printf legacy-zsh; else printf configured-shell; fi\n');
            await writeFile(lockPath, 'locked\n');
            const job = {
                statePath,
                lockPath,
                logPath,
                scriptPath,
                runPath: join(fixtureRoot, 'fixture-run'),
                projectRoot: fixtureRoot,
                modelSlug: 'fixture-model',
                model: 'fixture/no-model',
                port: 0,
                routes: ['/'],
                openCodeConfigPath: '-',
                attemptPath,
                mode: 'validate',
                sourceSessionId: '-',
                promptPath: '-',
                attemptId: 1,
                startedAt: new Date().toISOString(),
            };
            if (Object.hasOwn(shell, 'value')) job.shellBinary = shell.value;
            await writeFile(jobPath, JSON.stringify(job));

            worker = spawn(process.execPath, [workerPath, jobPath], {
                env: { ...process.env, BENCHFORGE_RUNNER: '1', BENCHFORGE_ATTEMPT_TIMEOUT_SECONDS: '' },
                stdio: 'ignore',
            });
            const [exitCode, signal] = await once(worker, 'exit');
            const state = JSON.parse(await readFile(statePath, 'utf8'));

            assert.equal(signal, null);
            assert.equal(exitCode, shell.expected === null ? 1 : 0);
            assert.equal(state.status, shell.expected === null ? 'failed' : 'completed');
            if (shell.name === 'missing configured shell') {
                assert.match(state.message, /^Kurtarma denemesi başlatılamadı: .*ENOENT/);
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
