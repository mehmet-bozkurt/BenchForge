import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { copyFile, mkdir, mkdtemp, readFile, realpath, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { importExercise } from '../../engine/tools/import-exercism.mjs';
import { buildModelPolicy } from '../../engine/tools/benchforge-model-policy.mjs';

const testDirectory = dirname(fileURLToPath(import.meta.url));
const engineTools = join(testDirectory, '../../engine/tools');
const monitorPath = join(testDirectory, '../../bin/model-boundary-monitor.mjs');

async function writeExercise(directory) {
    await mkdir(join(directory, '.meta'), { recursive: true });
    await mkdir(join(directory, '.docs'), { recursive: true });
    await writeFile(join(directory, '.meta', 'config.json'), JSON.stringify({ files: { solution: ['two-fer.js'], test: ['two-fer.spec.js'], example: ['.meta/proof.ci.js'] } }));
    await writeFile(join(directory, '.docs', 'instructions.md'), "Return 'One for X, one for me.'");
    await writeFile(join(directory, 'two-fer.js'), "export const twoFer = () => { throw new Error('Remove this statement and implement this function'); };\n");
    await writeFile(join(directory, '.meta', 'proof.ci.js'), 'export const twoFer = (name = "you") => `One for ${name}, one for me.`;\n');
    await writeFile(join(directory, 'two-fer.spec.js'), [
        "import { twoFer } from './two-fer';",
        "describe('TwoFer', () => {",
        "  test('no name', () => { expect(twoFer()).toEqual('One for you, one for me.'); });",
        "  xtest('a name', () => { expect(twoFer('Alice')).toEqual('One for Alice, one for me.'); });",
        '});',
        '',
    ].join('\n'));
}

/** Runs the real benchmark script on an algorithm task with a scripted model and returns the result line and run folder files. */
async function runAlgorithmTask(modelScript) {
    const root = await realpath(await mkdtemp(join(tmpdir(), 'benchforge-algorithm-')));
    const commands = join(root, 'commands');

    try {
        await Promise.all([mkdir(join(root, 'tools'), { recursive: true }), mkdir(commands), mkdir(join(root, 'home')), mkdir(join(root, 'source'))]);
        await writeExercise(join(root, 'source', 'two-fer'));
        const imported = await importExercise({ exerciseDirectory: join(root, 'source', 'two-fer'), outputRoot: root });
        assert.equal(imported.imported, true);
        assert.equal(imported.testCount, 2);

        for (const name of ['benchmark.zsh', 'benchforge-process-environment.zsh', 'benchforge-model-policy.mjs', 'select-opencode-session.mjs', 'classify-opencode-failure.mjs', 'run-with-timeout.mjs', 'algorithm-test-runner.mjs']) {
            await copyFile(join(engineTools, name), join(root, 'tools', name));
        }
        await writeFile(join(root, 'prompt.txt'), await readFile(join(root, 'algorithm-tasks', 'two-fer', 'prompt.txt')));
        await writeFile(join(root, 'models.conf'), 'fixture-model|fixture/fake|35560\n');
        await writeFile(join(root, 'opencode.json'), '{}');
        await writeFile(join(commands, 'opencode'), [
            '#!/usr/bin/env node',
            'const args = process.argv.slice(2);',
            'if (args[0] === "--version") { console.log("0.0.0-fixture"); process.exit(0); }',
            'if (args[0] === "session") { console.log(JSON.stringify([{ id: "ses_fixture1", directory: process.cwd(), title: "benchforge:fixture-run:fixture-model:initial", updated: 1 }])); process.exit(0); }',
            modelScript,
        ].join('\n'), { mode: 0o700 });

        const runner = spawn('/bin/zsh', ['-f', join(root, 'tools', 'benchmark.zsh'), 'fixture-run', 'prompt.txt', 'models.conf', '', 'opencode.json'], {
            cwd: root,
            env: {
                PATH: `${commands}:${dirname(process.execPath)}:/usr/bin:/bin:/usr/sbin:/sbin`,
                HOME: join(root, 'home'),
                BENCHFORGE_BOUNDARY_MONITOR: monitorPath,
                BENCHFORGE_TASK_TYPE: 'algorithm',
                BENCHFORGE_ALGORITHM_TASK_DIR: join(root, 'algorithm-tasks', 'two-fer'),
            },
            stdio: 'ignore',
        });
        const watchdog = setTimeout(() => runner.kill('SIGKILL'), 60_000);
        await once(runner, 'exit');
        clearTimeout(watchdog);

        const run = join(root, 'runs', 'fixture-run');
        const result = (await readFile(join(run, '_results', 'fixture-model.result'), 'utf8')).trim().split('|');
        const summary = (await readFile(join(run, '_summary.tsv'), 'utf8')).trim().split('\n');
        const tests = await readFile(join(run, '_results', 'fixture-model.tests.json'), 'utf8').then(JSON.parse).catch(() => null);
        const meta = await readFile(join(run, '_meta.txt'), 'utf8');

        return { result, summary, tests, meta };
    } finally {
        await rm(root, { recursive: true, force: true });
    }
}

const solve = 'require("node:fs").writeFileSync("two-fer.js", "export const twoFer = (name = \\"you\\") => `One for ${name}, one for me.`;\\n");';

test('scores a solved algorithm task by its unit tests', async () => {
    const { result, summary, tests, meta } = await runAlgorithmTask(`${solve}`);

    assert.deepEqual(result.slice(2, 4), ['0', '0']);
    assert.equal(summary[0], 'klasor|model|opencode|tests|sure_sn');
    assert.equal(tests.passed, 2);
    assert.equal(tests.total, 2);
    assert.match(meta, /task_type=algorithm/u);
    assert.match(meta, /algorithm_task=two-fer/u);
});

test('records a wrong solution as failed tests with a finished model step', async () => {
    const { result, tests } = await runAlgorithmTask('require("node:fs").writeFileSync("two-fer.js", "export const twoFer = () => \\"nope\\";\\n");');

    assert.deepEqual(result.slice(2, 4), ['0', '1']);
    assert.equal(tests.passed, 0);
    assert.equal(tests.total, 2);
});

test('treats an untouched solution file as no submission, even when the tests were edited', async () => {
    const { result, tests } = await runAlgorithmTask('require("node:fs").appendFileSync("two-fer.spec.js", "// edited\\n");');

    assert.equal(result[2], '86');
    assert.equal(tests.passed, 0);
    assert.deepEqual(tests.tampered, ['two-fer.spec.js']);
});

test('grades with the original tests even when the model rewrote them', async () => {
    const rewrite = 'require("node:fs").writeFileSync("two-fer.spec.js", "import { test } from \\"./jest-shim.mjs\\"; test(\\"x\\", () => {});\\n");';
    const { result, tests } = await runAlgorithmTask(`${rewrite}\nrequire("node:fs").writeFileSync("two-fer.js", "export const twoFer = () => 1;\\n");`);

    assert.deepEqual(result.slice(2, 4), ['0', '1']);
    assert.equal(tests.total, 2);
    assert.equal(tests.passed, 0);
    assert.deepEqual(tests.tampered, ['two-fer.spec.js']);
});

test('an algorithm policy allows only the task test command and no site checks', () => {
    const policy = buildModelPolicy({ workspace: '/tmp/run/model-a', modelSlug: 'model-a', mode: 'initial', taskType: 'algorithm', testFile: 'two-fer.spec.js' });
    const bash = policy.config.permission.bash;

    assert.equal(bash['node --test two-fer.spec.js'], 'allow');
    assert.equal(bash['npm run build'], undefined);
    assert.equal(bash['npm install --ignore-scripts --no-audit --no-fund *'], undefined);
    assert.equal(bash['*'], 'deny');
    assert.equal(policy.metadata.taskType, 'algorithm');
    assert.doesNotMatch(policy.instructions, /static-export basePath/u);
    assert.match(policy.instructions, /node --test two-fer\.spec\.js/u);
    assert.throws(() => buildModelPolicy({ workspace: '/tmp/run/model-a', modelSlug: 'model-a', mode: 'initial', taskType: 'algorithm' }), /test file/u);
});

test('the site policy is unchanged by the algorithm type', () => {
    const policy = buildModelPolicy({ workspace: '/tmp/run/model-a', modelSlug: 'model-a', mode: 'initial' });

    assert.equal(policy.config.permission.bash['npm run build'], 'allow');
    assert.equal(policy.config.permission.bash['node --test two-fer.spec.js'], undefined);
    assert.equal(policy.metadata.taskType, 'site');
});
