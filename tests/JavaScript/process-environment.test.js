import assert from 'node:assert/strict';
import { execFile, spawn } from 'node:child_process';
import { once } from 'node:events';
import { copyFile, mkdtemp, mkdir, readFile, realpath, rm, stat, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { promisify } from 'node:util';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { inheritedEnvironment, isolatedModelEnvironment, modelEnvironment, runnerControls } from '../../bin/process-environment.mjs';

const execute = promisify(execFile);
const testDirectory = dirname(fileURLToPath(import.meta.url));
const binDirectory = join(testDirectory, '../../bin');
const engineTools = join(testDirectory, '../../engine/tools');
const fakeSecrets = {
    APP_KEY: 'fixture-app-secret',
    DB_PASSWORD: 'fixture-database-secret',
    MAIL_PASSWORD: 'fixture-mail-secret',
    AWS_SECRET_ACCESS_KEY: 'fixture-aws-secret',
    OPENROUTER_API_KEY: 'fixture-provider-secret',
    UNKNOWN_FUTURE_SECRET: 'fixture-unknown-secret',
    OPENCODE_CONFIG_CONTENT: '{"permission":{"*":"allow"}}',
    OPENCODE_PERMISSION: '{"*":"allow"}',
    NODE_OPTIONS: '--no-warnings',
    BASH_ENV: '/missing/fixture-hook',
    ZDOTDIR: '/missing/fixture-hook',
    LD_PRELOAD: '',
    DYLD_INSERT_LIBRARIES: '',
    GIT_CONFIG_COUNT: '1',
    GIT_CONFIG_KEY_0: 'core.sshCommand',
    GIT_CONFIG_VALUE_0: 'fixture-hook',
    BENCHFORGE_UNKNOWN: 'fixture-unknown',
};

function assertNoInheritedSecrets(keys) {
    for (const key of Object.keys(fakeSecrets)) assert.ok(!keys.includes(key), `${key} must not reach the child`);
}

test('inherits only exact operational keys and drops future secrets and execution hooks', () => {
    const source = {
        ...fakeSecrets,
        PATH: '/fixture/bin',
        HOME: '/fixture/home',
        XDG_DATA_HOME: '/fixture/auth-data',
        LANG: 'tr_TR.UTF-8',
        LC_NUMERIC: 'C',
        TMPDIR: '/fixture/tmp',
        TZ: 'Europe/Istanbul',
    };

    assert.deepEqual(inheritedEnvironment(source), {
        PATH: '/fixture/bin', HOME: '/fixture/home', TMPDIR: '/fixture/tmp',
        LANG: 'tr_TR.UTF-8', LC_NUMERIC: 'C', TZ: 'Europe/Istanbul', XDG_DATA_HOME: '/fixture/auth-data',
    });
    const model = modelEnvironment({ OPENCODE_CONFIG_CONTENT: '{"permission":{"*":"deny"}}' }, source);
    assert.equal(model.OPENCODE_CONFIG_CONTENT, '{"permission":{"*":"deny"}}');
    assert.equal(model.OPENCODE_DISABLE_PROJECT_CONFIG, '1');
    assert.equal(model.OPENCODE_PURE, '1');
    assert.equal(model.OPENCODE_PERMISSION, undefined);
    assert.equal(model.APP_KEY, undefined);
    assert.equal(modelEnvironment({}, source).OPENCODE_CONFIG_CONTENT, undefined);
});

test('isolates the model from host OpenCode configuration while keeping shared auth data and caches', () => {
    const source = { ...fakeSecrets, PATH: '/fixture/bin', HOME: '/fixture/home', XDG_CONFIG_HOME: '/fixture/home/custom-config' };
    const model = isolatedModelEnvironment({ OPENCODE_CONFIG: '/fixture/run/config.json' }, '/fixture/run/_opencode-home/model-one', source);

    assert.equal(model.HOME, '/fixture/run/_opencode-home/model-one');
    assert.equal(model.XDG_CONFIG_HOME, '/fixture/home/.cache/benchforge/opencode-config');
    assert.equal(model.NEXT_TELEMETRY_DISABLED, '1');
    assert.equal(model.XDG_STATE_HOME, '/fixture/run/_opencode-home/model-one/.local/state');
    assert.equal(model.XDG_DATA_HOME, '/fixture/home/.local/share');
    assert.equal(model.XDG_CACHE_HOME, '/fixture/home/.cache');
    assert.equal(model.npm_config_cache, '/fixture/home/.npm');
    assert.equal(model.OPENCODE_CONFIG, '/fixture/run/config.json');
    for (const flag of ['OPENCODE_DISABLE_PROJECT_CONFIG', 'OPENCODE_PURE', 'OPENCODE_DISABLE_AUTOUPDATE', 'OPENCODE_DISABLE_CLAUDE_CODE', 'OPENCODE_DISABLE_CLAUDE_CODE_PROMPT', 'OPENCODE_DISABLE_SHARE']) {
        assert.equal(model[flag], '1', flag);
    }
    assert.equal(model.OPENCODE_DISABLE_MODELS_FETCH, undefined);
    assertNoInheritedSecrets(Object.keys(model));

    const explicitData = isolatedModelEnvironment({}, '/fixture/private', { HOME: '/fixture/home', XDG_DATA_HOME: '/fixture/auth-data', XDG_CACHE_HOME: '/fixture/cache' });
    assert.equal(explicitData.XDG_DATA_HOME, '/fixture/auth-data');
    assert.equal(explicitData.XDG_CACHE_HOME, '/fixture/cache');
    assert.throws(() => isolatedModelEnvironment({}, 'relative/home', source), /absolute private home/u);
});

test('retains only validated continuation controls rather than arbitrary BenchForge variables', () => {
    assert.deepEqual(runnerControls({ ...fakeSecrets, BENCHFORGE_ATTEMPT_TIMEOUT_SECONDS: '120', BENCHFORGE_SKIP_BROWSER_SMOKE: '1' }), {
        BENCHFORGE_ATTEMPT_TIMEOUT_SECONDS: '120', BENCHFORGE_SKIP_BROWSER_SMOKE: '1',
    });
    for (const value of ['0', '59', '7201', '60.5', 'bad', '']) {
        assert.deepEqual(runnerControls({ BENCHFORGE_ATTEMPT_TIMEOUT_SECONDS: value, BENCHFORGE_SKIP_BROWSER_SMOKE: 'bad' }), {});
    }
});

test('sanitizes terminal shell children without losing paths or file-based authentication location', async () => {
    const { stdout } = await execute('/bin/zsh', [
        '-f', '-c', 'source "$1"; exec "$2" -e \'console.log(JSON.stringify({ keys: Object.keys(process.env), home: process.env.HOME, dataHome: process.env.XDG_DATA_HOME }))\'',
        'fixture', join(engineTools, 'benchforge-process-environment.zsh'), process.execPath,
    ], { env: { ...process.env, ...fakeSecrets, HOME: '/fixture/home', XDG_DATA_HOME: '/fixture/auth-data' } });
    const observed = JSON.parse(stdout);

    assertNoInheritedSecrets(observed.keys);
    assert.equal(observed.home, '/fixture/home');
    assert.equal(observed.dataHome, '/fixture/auth-data');
});

for (const scriptName of ['export-results.zsh', 'serve-all.zsh', 'serve-results.zsh']) {
    test(`${scriptName} sanitizes the environment before its usage exit without starting services`, async () => {
        const child = spawn('/bin/zsh', [
            '-f', '-c', 'fixture_entry="$1"; fixture_node="$2"; TRAPEXIT() { "$fixture_node" -e \'console.log(JSON.stringify({ keys: Object.keys(process.env) }))\'; }; set --; source "$fixture_entry"',
            'fixture', join(engineTools, scriptName), process.execPath,
        ], { env: { ...process.env, ...fakeSecrets, HOME: '/fixture/home' }, stdio: ['ignore', 'pipe', 'pipe'] });
        let output = '';
        child.stdout.setEncoding('utf8');
        child.stdout.on('data', (chunk) => { output += chunk; });
        const [code, signal] = await once(child, 'exit');
        const observation = JSON.parse(output.trim().split('\n').at(-1));

        assert.equal(code, 1);
        assert.equal(signal, null);
        assert.match(output, /Kullanim:/);
        assertNoInheritedSecrets(observation.keys);
    });
}

test('detached launcher filters secrets while retaining validated continuation timeout', async () => {
    const root = await mkdtemp(join(tmpdir(), 'benchforge-launcher-environment-'));
    const childPath = join(root, 'fake-worker.mjs');
    const observedPath = join(root, 'observed.json');

    try {
        await writeFile(childPath, 'import { writeFileSync } from "node:fs"; writeFileSync(process.argv[2], JSON.stringify({ keys: Object.keys(process.env), runner: process.env.BENCHFORGE_RUNNER, timeout: process.env.BENCHFORGE_ATTEMPT_TIMEOUT_SECONDS }));\n');

        const result = await execute(process.execPath, [join(binDirectory, 'benchmark-launcher.mjs'), childPath, observedPath], {
            env: { ...process.env, ...fakeSecrets, BENCHFORGE_ATTEMPT_TIMEOUT_SECONDS: '120' },
        });
        assert.match(result.stdout, /^[1-9][0-9]*$/);
        let observed;
        for (let index = 0; index < 100; index += 1) {
            try {
                observed = JSON.parse(await readFile(observedPath, 'utf8'));
                break;
            } catch (error) {
                if (error.code !== 'ENOENT') throw error;
                await new Promise((resolve) => setTimeout(resolve, 10));
            }
        }

        assert.ok(observed, 'fake worker must produce its observation');
        assertNoInheritedSecrets(observed.keys);
        assert.equal(observed.runner, '1');
        assert.equal(observed.timeout, '120');
    } finally {
        await rm(root, { recursive: true, force: true });
    }
});

for (const workerName of ['benchmark-worker.mjs', 'model-run-attempt-worker.mjs']) {
    test(`${workerName} gives its shell only operational variables and skips startup hooks`, async () => {
        const root = await mkdtemp(join(tmpdir(), 'benchforge-worker-environment-'));
        const scriptPath = join(root, 'fixture.zsh');
        const jobPath = join(root, 'job.json');
        const logPath = join(root, 'runner.log');

        try {
            await writeFile(join(root, '.zshenv'), 'export APP_KEY=fixture-startup-hook\n');
            await writeFile(scriptPath, `exec ${JSON.stringify(process.execPath)} -e 'console.log(JSON.stringify({ keys: Object.keys(process.env), monitor: process.env.BENCHFORGE_BOUNDARY_MONITOR, home: process.env.HOME }))'\n`);
            await writeFile(jobPath, JSON.stringify({
                statePath: join(root, 'state.json'), lockPath: join(root, 'active.lock'), logPath,
                projectRoot: root, scriptPath, shellBinary: '/bin/zsh', runId: 'fixture-run', runPath: join(root, 'fixture-run'),
                promptPath: '-', promptRelative: 'fixture', configPath: '-', configRelative: 'fixture', openCodeConfigPath: '-',
                routes: ['/'], modelCount: 1, modelSlug: 'fixture-model', model: 'fixture/fake', port: 35550,
                attemptPath: root, mode: 'validate', sourceSessionId: '-', attemptId: 1, startedAt: new Date().toISOString(),
            }));

            await execute(process.execPath, [join(binDirectory, workerName), jobPath], {
                env: { ...process.env, ...fakeSecrets, HOME: root, BENCHFORGE_RUNNER: '1', BENCHFORGE_BOUNDARY_MONITOR: '/untrusted/monitor' },
            });
            const observed = JSON.parse(await readFile(logPath, 'utf8'));

            assertNoInheritedSecrets(observed.keys);
            assert.equal(observed.home, root);
            assert.equal(observed.monitor, join(binDirectory, 'model-boundary-monitor.mjs'));
        } finally {
            await rm(root, { recursive: true, force: true });
        }
    });
}

test('monitor ignores inherited OpenCode overrides and uses only explicit model configuration', async () => {
    const root = await realpath(await mkdtemp(join(tmpdir(), 'benchforge-monitor-environment-')));
    const workspace = join(root, 'fixture-model');
    const runtimePath = join(root, 'runtime.json');
    const configPath = join(root, 'config.json');

    try {
        await mkdir(workspace);
        await writeFile(runtimePath, '{"permission":{"*":"deny"}}');
        await writeFile(configPath, '{}');

        const { stdout } = await execute(process.execPath, [
            join(binDirectory, 'model-boundary-monitor.mjs'),
            '--workspace', workspace,
            '--event-log', join(root, '_logs', 'fixture-model.opencode.events.jsonl'),
            '--stderr-log', join(root, '_logs', 'fixture-model.opencode.stderr.log'),
            '--report', join(root, '_boundary', 'fixture-model.json'),
            '--opencode-config', configPath, '--runtime-config', runtimePath, '--base-path', '/fixture-model',
            '--', process.execPath, '-e', 'console.log(JSON.stringify({ keys: Object.keys(process.env), config: process.env.OPENCODE_CONFIG_CONTENT, configPath: process.env.OPENCODE_CONFIG, basePath: process.env.NEXT_PUBLIC_BASE_PATH, pure: process.env.OPENCODE_PURE }))',
        ], { env: { ...process.env, ...fakeSecrets } });
        const observed = JSON.parse(stdout);

        assertNoInheritedSecrets(observed.keys.filter((key) => key !== 'OPENCODE_CONFIG_CONTENT'));
        assert.equal(observed.config, '{"permission":{"*":"deny"}}');
        assert.equal(observed.configPath, configPath);
        assert.equal(observed.basePath, '/fixture-model');
        assert.equal(observed.pure, '1');
    } finally {
        await rm(root, { recursive: true, force: true });
    }
});

test('monitor gives an isolated model a private home outside its workspace and keeps legacy runs unchanged', async () => {
    const root = await realpath(await mkdtemp(join(tmpdir(), 'benchforge-monitor-profile-')));
    const workspace = join(root, 'fixture-model');
    const probe = 'console.log(JSON.stringify({ home: process.env.HOME, config: process.env.XDG_CONFIG_HOME, data: process.env.XDG_DATA_HOME, autoupdate: process.env.OPENCODE_DISABLE_AUTOUPDATE }))';
    const monitorArguments = (name, extra) => [
        join(binDirectory, 'model-boundary-monitor.mjs'),
        '--workspace', workspace,
        '--event-log', join(root, '_logs', `fixture-model.opencode.events${name}.jsonl`),
        '--stderr-log', join(root, '_logs', 'fixture-model.opencode.stderr.log'),
        '--report', join(root, '_boundary', 'fixture-model.json'),
        ...extra,
        '--', process.execPath, '-e', probe,
    ];

    try {
        await mkdir(workspace);
        const environment = { ...process.env, ...fakeSecrets, HOME: '/fixture/host-home' };
        delete environment.XDG_DATA_HOME;
        delete environment.XDG_CACHE_HOME;
        delete environment.XDG_CONFIG_HOME;
        const isolated = JSON.parse((await execute(process.execPath, monitorArguments('', ['--environment-profile', 'isolated-v1']), { env: environment })).stdout);
        const privateHome = join(root, '_opencode-home', 'fixture-model');

        assert.equal(isolated.home, privateHome);
        assert.equal(isolated.config, '/fixture/host-home/.cache/benchforge/opencode-config');
        assert.equal(isolated.data, '/fixture/host-home/.local/share');
        assert.equal(isolated.autoupdate, '1');
        assert.equal((await stat(privateHome)).mode & 0o777, 0o700);

        await rm(join(root, '_logs'), { recursive: true, force: true });
        await rm(join(root, '_boundary'), { recursive: true, force: true });
        const legacy = JSON.parse((await execute(process.execPath, monitorArguments('', []), { env: environment })).stdout);
        assert.equal(legacy.home, '/fixture/host-home');
        assert.equal(legacy.autoupdate, undefined);

        await rm(join(root, '_logs'), { recursive: true, force: true });
        await rm(join(root, '_boundary'), { recursive: true, force: true });
        await assert.rejects(
            execute(process.execPath, monitorArguments('', ['--environment-profile', 'host']), { env: environment }),
            (error) => error.code === 64,
        );
    } finally {
        await rm(root, { recursive: true, force: true });
    }
});

test('initial terminal benchmark sanitizes npm install before any real model or browser can run', async () => {
    const root = await mkdtemp(join(tmpdir(), 'benchforge-initial-environment-'));
    const toolsPath = join(root, 'tools');
    const commandPath = join(root, 'commands');
    const homePath = join(root, 'home');
    let runner;

    try {
        await Promise.all([
            mkdir(join(toolsPath, 'browser-smoke'), { recursive: true }), mkdir(commandPath), mkdir(homePath), mkdir(join(root, 'sablon')),
        ]);
        for (const name of ['benchmark.zsh', 'benchforge-process-environment.zsh', 'benchforge-model-policy.mjs', 'select-opencode-session.mjs', 'classify-opencode-failure.mjs', 'run-with-timeout.mjs']) {
            await copyFile(join(engineTools, name), join(toolsPath, name));
        }
        await writeFile(join(toolsPath, 'browser-smoke', 'check.mjs'), 'throw new Error("Browser must never run");\n');
        await writeFile(join(root, 'prompt.txt'), 'fixture prompt');
        await writeFile(join(root, 'models.conf'), 'fixture-model|fixture/fake|35550\n');
        await writeFile(join(root, 'opencode.json'), '{}');
        await writeFile(join(homePath, '.zshenv'), 'export APP_KEY=fixture-shell-hook\n');
        for (const name of ['npm', 'opencode', 'rsync', 'curl', 'python3', 'shasum']) {
            const source = name === 'npm'
                ? 'if (process.argv.includes("ci")) { console.log(JSON.stringify({ keys: Object.keys(process.env) })); process.exit(1); } console.log("fixture-version");'
                : 'if (process.argv[2] === "run") process.exit(90); console.log("fixture-version");';
            await writeFile(join(commandPath, name), `#!/usr/bin/env node\n${source}\n`, { mode: 0o700 });
        }

        runner = spawn('/bin/zsh', ['-f', join(toolsPath, 'benchmark.zsh'), 'fixture-run', 'prompt.txt', 'models.conf', '/', 'opencode.json'], {
            cwd: root,
            env: { ...process.env, ...fakeSecrets, HOME: homePath, PATH: `${commandPath}:${dirname(process.execPath)}:${process.env.PATH}`, BENCHFORGE_BOUNDARY_MONITOR: join(binDirectory, 'model-boundary-monitor.mjs') },
            stdio: 'ignore',
        });
        const [code, signal] = await once(runner, 'exit');
        const observed = JSON.parse(await readFile(join(root, 'runs', 'fixture-run', '_logs', 'fixture-model.npm-ci.log'), 'utf8'));

        assert.equal(code, 1);
        assert.equal(signal, null);
        assertNoInheritedSecrets(observed.keys);
    } finally {
        if (runner?.exitCode === null && runner.signalCode === null) runner.kill('SIGTERM');
        await rm(root, { recursive: true, force: true });
    }
});
