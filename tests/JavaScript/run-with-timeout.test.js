import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const helperPath = join(dirname(fileURLToPath(import.meta.url)), '../../engine/tools/run-with-timeout.mjs');

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

async function runHelper(args) {
    const child = spawn(process.execPath, [helperPath, ...args], { stdio: ['ignore', 'pipe', 'pipe'] });
    let stdout = '';
    let stderr = '';
    child.stdout.on('data', (chunk) => { stdout += chunk; });
    child.stderr.on('data', (chunk) => { stderr += chunk; });
    const started = Date.now();
    const [code] = await once(child, 'exit');

    return { code, stdout, stderr, seconds: (Date.now() - started) / 1000 };
}

/** Runs a shell command that leaves a background process and returns that process's pid. */
async function withBackgroundProcess(limit, script, inspect) {
    const root = await mkdtemp(join(tmpdir(), 'benchforge-step-limit-'));
    const pidFile = join(root, 'background.pid');

    try {
        const result = await runHelper([String(limit), '/bin/sh', '-c', `sleep 30 & echo $! > "${pidFile}"; ${script}`]);
        const backgroundPid = Number((await readFile(pidFile, 'utf8')).trim());
        await new Promise((resolve) => setTimeout(resolve, 200));
        await inspect(result, backgroundPid);
    } finally {
        await rm(root, { recursive: true, force: true });
    }
}

test('passes the output and exit code of a step that finishes in time', async () => {
    const result = await runHelper(['5', process.execPath, '-e', 'console.log("ok"); process.exit(3);']);

    assert.equal(result.code, 3);
    assert.equal(result.stdout.trim(), 'ok');
    assert.equal(result.stderr, '');
});

test('stops a step and everything it started when the time limit is reached', async () => {
    await withBackgroundProcess(1, 'sleep 30', (result, backgroundPid) => {
        assert.equal(result.code, 124);
        assert.ok(result.seconds < 8, `took ${result.seconds}s`);
        assert.match(result.stderr, /Adim 1 saniyelik sure sinirini asti/);
        assert.equal(isProcessRunning(backgroundPid), false);
    });
});

test('stops processes a finished step left running', async () => {
    await withBackgroundProcess(10, 'exit 0', (result, backgroundPid) => {
        assert.equal(result.code, 0);
        assert.equal(isProcessRunning(backgroundPid), false);
    });
});

test('rejects a missing command or an invalid limit', async () => {
    assert.equal((await runHelper(['0', 'true'])).code, 64);
    assert.equal((await runHelper(['5'])).code, 64);
    assert.equal((await runHelper(['5', '/no/such/command'])).code, 127);
});
