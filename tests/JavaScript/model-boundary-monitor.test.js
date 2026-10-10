import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { link, mkdtemp, mkdir, readFile, realpath, rm, symlink, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { ModelBoundaryMonitor } from '../../bin/model-boundary-monitor.mjs';

const monitorPath = join(dirname(fileURLToPath(import.meta.url)), '../../bin/model-boundary-monitor.mjs');
const benchmarkWorkerPath = join(dirname(fileURLToPath(import.meta.url)), '../../bin/benchmark-worker.mjs');
const fixtureWorkspace = '/tmp/benchforge-fixture/run/model-one';

function toolEvent(id, tool, status, input, output = '', error = '', metadata = {}) {
    return `${JSON.stringify({
        type: 'tool_use',
        timestamp: 1_800_000_000_000,
        part: { callID: id, tool, state: { status, input, output, error, metadata } },
    })}\n`;
}

function rejectedEvent(id, path = '../_logs/model-two.build.log') {
    return toolEvent(id, 'bash', 'error', { command: `cat ${path}` }, '', 'The user rejected permission to use this specific tool call.');
}

function isProcessRunning(pid) {
    try {
        process.kill(pid, 0);

        return true;
    } catch (error) {
        if (error.code === 'ESRCH') return false;
        throw error;
    }
}

test('records denied outside access without copying commands, errors or secret values', () => {
    const monitor = new ModelBoundaryMonitor({ workspace: fixtureWorkspace, now: () => 1_800_000_000_000 });

    monitor.consumeEvents(rejectedEvent('blocked-one', '../_logs/model-two.build.log?api_key=sk-fakekey123456789'));

    const report = monitor.snapshot();
    assert.equal(report.blockedCount, 1);
    assert.equal(report.observedCrossModelReadCount, 0);
    assert.equal(report.stoppedForBoundary, false);
    assert.deepEqual(report.entries, [{
        timestamp: '2027-01-15T08:00:00.000Z',
        path: '../_logs/model-two.build.log?api_key=[redacted]',
        tool: 'bash',
        reason: 'Çalışma alanı dışı erişim izin politikası tarafından reddedildi.',
        kind: 'blocked',
    }]);
    assert.doesNotMatch(JSON.stringify(report), /cat |user rejected|sk-fakekey/);
});

test('ignores ordinary tool errors and own-workspace permission denials', () => {
    const monitor = new ModelBoundaryMonitor({ workspace: fixtureWorkspace });

    monitor.consumeEvents(toolEvent('aborted', 'bash', 'error', { command: 'cat ../_logs/other.log' }, '', 'Tool execution aborted'));
    monitor.consumeEvents(toolEvent('edit-failed', 'edit', 'error', { filePath: '../model-two/src/page.tsx' }, '', 'oldString not found'));
    monitor.consumeEvents(toolEvent('own-denial', 'read', 'error', { filePath: './src/page.tsx' }, '', 'Permission denied'));
    monitor.consumeEvents(toolEvent('command-denial', 'bash', 'error', { command: 'git push' }, '', 'The user rejected permission to use this specific tool call.'));

    assert.equal(monitor.snapshot().blockedCount, 0);
    assert.deepEqual(monitor.snapshot().entries, []);
});

test('records installed OpenCode rule denials only when the requested path is outside', () => {
    const monitor = new ModelBoundaryMonitor({ workspace: fixtureWorkspace });
    const ruleError = 'The user has specified a rule which prevents you from using this specific tool call. Here are some of the relevant rules [{"permission":"external_directory","pattern":"*","action":"deny"}]';

    monitor.consumeEvents(toolEvent('outside-rule', 'read', 'error', { filePath: '../model-two/src/page.tsx' }, '', ruleError));
    monitor.consumeEvents(toolEvent('own-rule', 'read', 'error', { filePath: 'src/page.tsx' }, '', ruleError));

    assert.equal(monitor.snapshot().blockedCount, 1);
    assert.equal(monitor.snapshot().entries[0].path, '../model-two/src/page.tsx');
    assert.doesNotMatch(JSON.stringify(monitor.snapshot()), /Here are some/);
});

test('pairs stderr rejections with tool events in either order without double counting', () => {
    for (const stderrFirst of [true, false]) {
        const monitor = new ModelBoundaryMonitor({ workspace: fixtureWorkspace, now: () => 1000 });
        const stderr = '\u001b[33m! permission requested: external_directory (/tmp/benchforge-fixture/run/_logs/*); auto-rejecting\u001b[0m\n';
        const event = rejectedEvent('blocked-one');

        if (stderrFirst) {
            monitor.consumeStderr(stderr);
            monitor.consumeEvents(event);
        } else {
            monitor.consumeEvents(event);
            monitor.consumeStderr(stderr);
        }
        monitor.consumeEvents(event);

        assert.equal(monitor.snapshot().blockedCount, 1);
        assert.equal(monitor.snapshot().entries.length, 1);
    }
});

test('does not pair an own-workspace denied read with a later outside-directory rejection', () => {
    const monitor = new ModelBoundaryMonitor({ workspace: fixtureWorkspace });

    monitor.consumeEvents(toolEvent('own-first', 'read', 'error', { filePath: 'src/page.tsx' }, '', 'Permission denied'));
    monitor.consumeEvents(rejectedEvent('outside-second'));
    monitor.consumeStderr('! permission requested: external_directory (/tmp/benchforge-fixture/run/_logs/*); auto-rejecting\n');

    assert.equal(monitor.snapshot().blockedCount, 1);
    assert.equal(monitor.snapshot().entries[0].tool, 'bash');
});

test('stops once after three distinct rejections including stderr-only incidents', () => {
    let stops = 0;
    const monitor = new ModelBoundaryMonitor({ workspace: fixtureWorkspace, onStop: () => stops++ });

    monitor.consumeEvents(rejectedEvent('blocked-one'));
    monitor.consumeEvents(rejectedEvent('blocked-one'));
    monitor.consumeEvents(rejectedEvent('blocked-two'));
    monitor.consumeStderr('! permission requested: external_directory (/tmp/benchforge-fixture/run/_logs/*); auto-rejecting\n');
    monitor.consumeStderr('! permission requested: external_directory (/tmp/benchforge-fixture/run/_logs/*); auto-rejecting\n');
    monitor.consumeStderr('! permission requested: external_directory (/tmp/benchforge-fixture/run/_logs/*); auto-rejecting\n');

    assert.equal(monitor.snapshot().blockedCount, 3);
    assert.equal(monitor.snapshot().stoppedForBoundary, true);
    assert.equal(stops, 1);
});

test('parses split and final JSON lines and recovers after malformed oversized lines', () => {
    const monitor = new ModelBoundaryMonitor({ workspace: fixtureWorkspace });
    const event = rejectedEvent('blocked-one');

    monitor.consumeEvents('{broken json}\n');
    monitor.consumeEvents('x'.repeat(1024 * 1024 + 1));
    monitor.consumeEvents('\n');
    monitor.consumeEvents(event.slice(0, 13));
    monitor.consumeEvents(event.slice(13).trimEnd());
    monitor.finishInput();

    assert.equal(monitor.snapshot().blockedCount, 1);
});

test('separates observed cross-model content from requested paths and lint output', () => {
    const monitor = new ModelBoundaryMonitor({ workspace: fixtureWorkspace });

    monitor.consumeEvents(toolEvent('no-output', 'read', 'completed', { filePath: '../model-two/src/page.tsx' }));
    monitor.consumeEvents(toolEvent('lint-output', 'bash', 'completed', { command: 'npm run lint' }, '/tmp/benchforge-fixture/run/model-two/src/page.tsx'));
    monitor.consumeEvents(toolEvent('no-evidence', 'bash', 'completed', { command: 'cat ../_logs/model-two.build.log' }, 'Build failed'));
    monitor.consumeEvents(toolEvent('other-output', 'bash', 'completed', { command: 'grep directory ../_attempts/model-two/events.jsonl' }, 'directory: /tmp/benchforge-fixture/run/model-two'));

    const report = monitor.snapshot();
    assert.equal(report.blockedCount, 0);
    assert.equal(report.observedCrossModelReadCount, 1);
    assert.equal(report.entries[0].kind, 'observed_cross_model_read');
    assert.equal(report.entries[0].path, '../_attempts/model-two/events.jsonl');
    assert.equal(report.stoppedForBoundary, false);
});

test('records evidence of another model in a completed shared-log directory read', () => {
    const monitor = new ModelBoundaryMonitor({ workspace: fixtureWorkspace });

    monitor.consumeEvents(toolEvent('shared-search', 'bash', 'completed', { command: 'grep directory ../_logs ../_attempts' }, 'directory: "/tmp/benchforge-fixture/run/model-two"'));

    assert.equal(monitor.snapshot().observedCrossModelReadCount, 1);
    assert.equal(monitor.snapshot().entries[0].path, '../_logs');
    assert.equal(monitor.snapshot().blockedCount, 0);
});

test('records completed shared-log and attempt markers but not own markers or nonzero shell failures', () => {
    const monitor = new ModelBoundaryMonitor({ workspace: fixtureWorkspace });

    monitor.consumeEvents(toolEvent('own-marker', 'bash', 'completed', { command: 'grep directory ../_attempts' }, '/tmp/benchforge-fixture/run/_attempts/model-one/attempt-1/events.jsonl'));
    monitor.consumeEvents(toolEvent('failed-marker', 'bash', 'completed', { command: 'grep directory ../_attempts' }, '/tmp/benchforge-fixture/run/_attempts/model-two/attempt-1/events.jsonl', '', { exit: 1 }));
    monitor.consumeEvents(toolEvent('attempt-marker', 'bash', 'completed', { command: 'grep directory ../_attempts' }, '/tmp/benchforge-fixture/run/_attempts/model-one/attempt-1/events.jsonl\n/tmp/benchforge-fixture/run/_attempts/model-two/attempt-1/events.jsonl', '', { exit: 0 }));
    monitor.consumeEvents(toolEvent('log-marker', 'bash', 'completed', { command: 'grep failure ../_logs' }, '/tmp/benchforge-fixture/run/_logs/model-two.build.log', '', { exit: 0 }));

    assert.equal(monitor.snapshot().observedCrossModelReadCount, 2);
    assert.deepEqual(monitor.snapshot().entries.map((entry) => entry.path), ['../_attempts', '../_logs']);
    assert.equal(monitor.snapshot().blockedCount, 0);
});

test('keeps bounded incident entries and never stores tool output', () => {
    const monitor = new ModelBoundaryMonitor({ workspace: fixtureWorkspace });

    for (let index = 0; index < 60; index++) {
        monitor.consumeEvents(toolEvent(`read-${index}`, 'read', 'completed', { filePath: '../model-two/src/page.tsx' }, 'private model source content'));
    }

    const report = monitor.snapshot();
    assert.equal(report.observedCrossModelReadCount, 60);
    assert.equal(report.entries.length, 50);
    assert.ok(JSON.stringify(report).length < 65536);
    assert.doesNotMatch(JSON.stringify(report), /private model source content/);
});

test('terminates only its own model process tree after repeated boundary denials', async () => {
    const fixtureRoot = await realpath(await mkdtemp(join(tmpdir(), 'benchforge-boundary-process-')));
    const workspace = join(fixtureRoot, 'model-one');
    const reportPath = join(fixtureRoot, '_boundary', 'model-one.json');
    const grandchildPath = join(workspace, 'grandchild-pid.txt');
    const dummyPath = join(workspace, 'dummy-model.mjs');
    let wrapper;
    let unrelated;

    try {
        await mkdir(workspace);
        await writeFile(dummyPath, [
            'import { spawn } from "node:child_process";',
            'import { writeFileSync } from "node:fs";',
            'const child = spawn(process.execPath, ["-e", "setInterval(() => {}, 1000)"], { stdio: "ignore" });',
            'writeFileSync("grandchild-pid.txt", String(child.pid));',
            `for (const id of ['one', 'two', 'three']) process.stdout.write(${JSON.stringify(rejectedEvent('PLACEHOLDER'))}.replace('PLACEHOLDER', id));`,
            'setInterval(() => {}, 1000);',
        ].join('\n'));
        unrelated = spawn(process.execPath, ['-e', 'setInterval(() => {}, 1000)'], { stdio: 'ignore' });
        wrapper = spawn(process.execPath, [monitorPath,
            '--workspace', workspace,
            '--event-log', join(fixtureRoot, '_logs', 'model-one.opencode.events.jsonl'),
            '--stderr-log', join(fixtureRoot, '_logs', 'model-one.opencode.stderr.log'),
            '--report', reportPath,
            '--', process.execPath, dummyPath,
        ], { stdio: 'ignore' });
        const watchdog = setTimeout(() => wrapper.kill('SIGKILL'), 10_000);

        try {
            const [code, signal] = await once(wrapper, 'exit');
            const report = JSON.parse(await readFile(reportPath, 'utf8'));
            const grandchildPid = Number(await readFile(grandchildPath, 'utf8'));

            assert.equal(code, 78);
            assert.equal(signal, null);
            assert.equal(report.blockedCount, 3);
            assert.equal(report.stoppedForBoundary, true);
            assert.equal(report.policyVersion, 'workspace-boundary-v1');
            assert.equal(isProcessRunning(grandchildPid), false);
            assert.equal(isProcessRunning(unrelated.pid), true);
        } finally {
            clearTimeout(watchdog);
        }
    } finally {
        if (wrapper?.exitCode === null && wrapper.signalCode === null) wrapper.kill('SIGTERM');
        if (unrelated?.exitCode === null && unrelated.signalCode === null) {
            const exited = once(unrelated, 'exit');
            unrelated.kill('SIGTERM');
            await exited;
        }
        await rm(fixtureRoot, { recursive: true, force: true });
    }
});

test('keeps manual cancellation distinct from a boundary interruption and cleans up model children', async () => {
    const fixtureRoot = await realpath(await mkdtemp(join(tmpdir(), 'benchforge-boundary-cancel-')));
    const workspace = join(fixtureRoot, 'model-one');
    const reportPath = join(fixtureRoot, '_boundary', 'model-one.json');
    const dummyPath = join(workspace, 'dummy-model.mjs');
    let wrapper;

    try {
        await mkdir(workspace);
        await writeFile(dummyPath, [
            'import { spawn } from "node:child_process";',
            'import { writeFileSync } from "node:fs";',
            'const child = spawn(process.execPath, ["-e", "setInterval(() => {}, 1000)"], { stdio: "ignore" });',
            'writeFileSync("grandchild-pid.txt", String(child.pid));',
            'console.log(JSON.stringify({ type: "step_start" }));',
            'setInterval(() => {}, 1000);',
        ].join('\n'));
        wrapper = spawn(process.execPath, [monitorPath,
            '--workspace', workspace,
            '--event-log', join(fixtureRoot, '_logs', 'model-one.opencode.events.jsonl'),
            '--stderr-log', join(fixtureRoot, '_logs', 'model-one.opencode.stderr.log'),
            '--report', reportPath,
            '--', process.execPath, dummyPath,
        ], { stdio: ['ignore', 'pipe', 'ignore'] });
        const watchdog = setTimeout(() => wrapper.kill('SIGKILL'), 10_000);

        try {
            await once(wrapper.stdout, 'data');
            const finished = once(wrapper, 'exit');
            wrapper.kill('SIGTERM');
            const [code, signal] = await finished;
            const report = JSON.parse(await readFile(reportPath, 'utf8'));
            const grandchildPid = Number(await readFile(join(workspace, 'grandchild-pid.txt'), 'utf8'));

            assert.equal(code, 143);
            assert.equal(signal, null);
            assert.equal(report.stoppedForBoundary, false);
            assert.equal(report.blockedCount, 0);
            assert.equal(isProcessRunning(grandchildPid), false);
        } finally {
            clearTimeout(watchdog);
        }
    } finally {
        if (wrapper?.exitCode === null && wrapper.signalCode === null) wrapper.kill('SIGTERM');
        await rm(fixtureRoot, { recursive: true, force: true });
    }
});

test('stops a model that exceeds its time limit together with its children', async () => {
    const fixtureRoot = await realpath(await mkdtemp(join(tmpdir(), 'benchforge-boundary-timeout-')));
    const workspace = join(fixtureRoot, 'model-one');
    const reportPath = join(fixtureRoot, '_boundary', 'model-one.json');
    const dummyPath = join(workspace, 'dummy-model.mjs');
    let wrapper;

    try {
        await mkdir(workspace);
        await writeFile(dummyPath, [
            'import { spawn } from "node:child_process";',
            'import { writeFileSync } from "node:fs";',
            'const child = spawn(process.execPath, ["-e", "setInterval(() => {}, 1000)"], { stdio: "ignore" });',
            'writeFileSync("grandchild-pid.txt", String(child.pid));',
            'setInterval(() => {}, 1000);',
        ].join('\n'));
        wrapper = spawn(process.execPath, [monitorPath,
            '--workspace', workspace,
            '--event-log', join(fixtureRoot, '_logs', 'model-one.opencode.events.jsonl'),
            '--stderr-log', join(fixtureRoot, '_logs', 'model-one.opencode.stderr.log'),
            '--report', reportPath,
            '--timeout-seconds', '1',
            '--', process.execPath, dummyPath,
        ], { stdio: ['ignore', 'pipe', 'ignore'] });
        let output = '';
        wrapper.stdout.on('data', (chunk) => { output += chunk; });
        const watchdog = setTimeout(() => wrapper.kill('SIGKILL'), 10_000);

        try {
            const [code, signal] = await once(wrapper, 'exit');
            const grandchildPid = Number(await readFile(join(workspace, 'grandchild-pid.txt'), 'utf8'));

            assert.equal(code, 124);
            assert.equal(signal, null);
            assert.match(output, /süre sınırını aştığı için durduruldu/);
            assert.equal(JSON.parse(await readFile(reportPath, 'utf8')).stoppedForBoundary, false);
            assert.equal(isProcessRunning(grandchildPid), false);
        } finally {
            clearTimeout(watchdog);
        }
    } finally {
        if (wrapper?.exitCode === null && wrapper.signalCode === null) wrapper.kill('SIGTERM');
        await rm(fixtureRoot, { recursive: true, force: true });
    }
});

test('rejects an invalid time limit without starting a model', async () => {
    const fixtureRoot = await realpath(await mkdtemp(join(tmpdir(), 'benchforge-boundary-bad-timeout-')));
    const workspace = join(fixtureRoot, 'model-one');

    try {
        await mkdir(workspace);
        const wrapper = spawn(process.execPath, [monitorPath,
            '--workspace', workspace,
            '--event-log', join(fixtureRoot, '_logs', 'model-one.opencode.events.jsonl'),
            '--stderr-log', join(fixtureRoot, '_logs', 'model-one.opencode.stderr.log'),
            '--report', join(fixtureRoot, '_boundary', 'model-one.json'),
            '--timeout-seconds', '0',
            '--', process.execPath, '-e', 'require("node:fs").writeFileSync("started.txt", "1")',
        ], { stdio: 'ignore' });
        const [code] = await once(wrapper, 'exit');

        assert.equal(code, 64);
        await assert.rejects(readFile(join(workspace, 'started.txt')));
    } finally {
        await rm(fixtureRoot, { recursive: true, force: true });
    }
});

test('preserves a model command exit code and writes a zero-incident report', async () => {
    const fixtureRoot = await realpath(await mkdtemp(join(tmpdir(), 'benchforge-boundary-complete-')));
    const workspace = join(fixtureRoot, 'model-one');
    const reportPath = join(fixtureRoot, '_boundary', 'model-one.json');
    const eventPath = join(fixtureRoot, '_logs', 'model-one.opencode.events.jsonl');

    try {
        await mkdir(workspace);
        const wrapper = spawn(process.execPath, [monitorPath,
            '--workspace', workspace,
            '--event-log', eventPath,
            '--stderr-log', join(fixtureRoot, '_logs', 'model-one.opencode.stderr.log'),
            '--report', reportPath,
            '--', process.execPath, '-e', 'console.log(JSON.stringify({ type: "step_finish" })); process.exitCode = 7;',
        ], { stdio: 'ignore' });
        const [code, signal] = await once(wrapper, 'exit');

        assert.equal(code, 7);
        assert.equal(signal, null);
        assert.equal(JSON.parse(await readFile(reportPath, 'utf8')).blockedCount, 0);
        assert.equal(JSON.parse(await readFile(eventPath, 'utf8')).type, 'step_finish');
    } finally {
        await rm(fixtureRoot, { recursive: true, force: true });
    }
});

for (const unsafeTarget of ['other-model-log', 'event-symlink', 'stderr-symlink', 'report-symlink', 'log-parent-symlink', 'attempt-parent-symlink', 'existing-hardlinked-event', 'existing-report']) {
    test(`rejects ${unsafeTarget} without starting a model or overwriting another file`, async () => {
        const fixtureRoot = await realpath(await mkdtemp(join(tmpdir(), 'benchforge-boundary-path-')));
        const workspace = join(fixtureRoot, 'model-one');
        const victimDirectory = join(fixtureRoot, 'fixture-victim');
        let eventPath = join(fixtureRoot, '_logs', 'model-one.opencode.events.jsonl');
        let stderrPath = join(fixtureRoot, '_logs', 'model-one.opencode.stderr.log');
        let reportPath = join(fixtureRoot, '_boundary', 'model-one.json');
        let victimPath = join(victimDirectory, 'must-not-change.txt');

        try {
            await mkdir(workspace);
            await mkdir(victimDirectory);
            await writeFile(victimPath, 'fixture original data\n');

            if (unsafeTarget === 'other-model-log') {
                eventPath = join(fixtureRoot, '_logs', 'model-two.opencode.events.jsonl');
            } else if (unsafeTarget === 'log-parent-symlink') {
                victimPath = join(victimDirectory, 'model-one.opencode.events.jsonl');
                await writeFile(victimPath, 'fixture original data\n');
                await symlink(victimDirectory, join(fixtureRoot, '_logs'));
            } else if (unsafeTarget === 'attempt-parent-symlink') {
                const attemptPath = join(fixtureRoot, '_attempts', 'model-one', 'attempt-1');
                await mkdir(dirname(attemptPath), { recursive: true });
                await symlink(victimDirectory, attemptPath);
                eventPath = join(attemptPath, 'opencode.events.jsonl');
                stderrPath = join(attemptPath, 'opencode.stderr.log');
                reportPath = join(attemptPath, 'boundary-report.json');
                victimPath = join(victimDirectory, 'opencode.events.jsonl');
                await writeFile(victimPath, 'fixture original data\n');
            } else if (unsafeTarget === 'existing-report') {
                await mkdir(dirname(reportPath), { recursive: true });
                await writeFile(reportPath, 'existing report must remain unchanged\n');
            } else {
                const target = unsafeTarget === 'report-symlink' ? reportPath : unsafeTarget === 'stderr-symlink' ? stderrPath : eventPath;
                await mkdir(dirname(target), { recursive: true });
                if (unsafeTarget === 'existing-hardlinked-event') {
                    await link(victimPath, target);
                } else {
                    await symlink(victimPath, target);
                }
            }

            const wrapper = spawn(process.execPath, [monitorPath,
                '--workspace', workspace,
                '--event-log', eventPath,
                '--stderr-log', stderrPath,
                '--report', reportPath,
                '--', process.execPath, '-e', 'require("node:fs").writeFileSync("command-started.txt", "started");',
            ], { stdio: 'ignore' });
            const [code, signal] = await once(wrapper, 'exit');

            assert.equal(code, 74);
            assert.equal(signal, null);
            assert.equal(await readFile(victimPath, 'utf8'), 'fixture original data\n');
            await assert.rejects(readFile(join(workspace, 'command-started.txt'), 'utf8'), { code: 'ENOENT' });
            if (unsafeTarget === 'existing-report') {
                assert.equal(await readFile(reportPath, 'utf8'), 'existing report must remain unchanged\n');
            }
        } finally {
            await rm(fixtureRoot, { recursive: true, force: true });
        }
    });
}

test('rejects a planted report temporary-file symlink without changing its target', async () => {
    const fixtureRoot = await realpath(await mkdtemp(join(tmpdir(), 'benchforge-boundary-temp-link-')));
    const workspace = join(fixtureRoot, 'model-one');
    const reportPath = join(fixtureRoot, '_boundary', 'model-one.json');
    const victimPath = join(fixtureRoot, 'fixture-victim.txt');
    let wrapper;

    try {
        await mkdir(workspace);
        await writeFile(victimPath, 'fixture original data\n');
        const dummy = [
            `require("node:fs").symlinkSync(${JSON.stringify(victimPath)}, ${JSON.stringify(reportPath)} + ".monitor-" + process.ppid + ".tmp");`,
            `process.stdout.write(${JSON.stringify(rejectedEvent('blocked-one'))});`,
            'setInterval(() => {}, 1000);',
        ].join('\n');
        wrapper = spawn(process.execPath, [monitorPath,
            '--workspace', workspace,
            '--event-log', join(fixtureRoot, '_logs', 'model-one.opencode.events.jsonl'),
            '--stderr-log', join(fixtureRoot, '_logs', 'model-one.opencode.stderr.log'),
            '--report', reportPath,
            '--', process.execPath, '-e', dummy,
        ], { stdio: 'ignore' });
        const watchdog = setTimeout(() => wrapper.kill('SIGTERM'), 10_000);

        try {
            const [code, signal] = await once(wrapper, 'exit');

            assert.equal(code, 74);
            assert.equal(signal, null);
            assert.equal(await readFile(victimPath, 'utf8'), 'fixture original data\n');
        } finally {
            clearTimeout(watchdog);
        }
    } finally {
        if (wrapper?.exitCode === null && wrapper.signalCode === null) wrapper.kill('SIGTERM');
        await rm(fixtureRoot, { recursive: true, force: true });
    }
});

test('supplies the boundary helper to initial benchmark scripts without changing run completion', async () => {
    const fixtureRoot = await mkdtemp(join(tmpdir(), 'benchforge-boundary-initial-'));
    const jobPath = join(fixtureRoot, 'job.json');
    const statePath = join(fixtureRoot, 'state.json');
    const scriptPath = join(fixtureRoot, 'dummy-benchmark.zsh');
    const helperPath = join(fixtureRoot, 'helper.txt');

    try {
        await writeFile(scriptPath, 'print -r -- "$BENCHFORGE_BOUNDARY_MONITOR" > helper.txt\nexit 0\n');
        await writeFile(jobPath, JSON.stringify({
            statePath,
            lockPath: join(fixtureRoot, 'run.lock'),
            logPath: join(fixtureRoot, 'runner.log'),
            scriptPath,
            runId: 'fixture-run',
            promptPath: join(fixtureRoot, 'unused-prompt.txt'),
            configPath: join(fixtureRoot, 'unused-models.conf'),
            routes: ['/'],
            openCodeConfigPath: join(fixtureRoot, 'unused-config.json'),
            projectRoot: fixtureRoot,
            modelCount: 1,
            startedAt: '2026-09-28T00:00:00+00:00',
        }));

        const worker = spawn(process.execPath, [benchmarkWorkerPath, jobPath], {
            env: { ...process.env, BENCHFORGE_RUNNER: '1' },
            stdio: 'ignore',
        });
        const [code] = await once(worker, 'exit');

        assert.equal(code, 0);
        assert.equal((await readFile(helperPath, 'utf8')).trim(), monitorPath);
        assert.equal(JSON.parse(await readFile(statePath, 'utf8')).status, 'completed');
    } finally {
        await rm(fixtureRoot, { recursive: true, force: true });
    }
});
