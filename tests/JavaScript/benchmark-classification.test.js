import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { access, copyFile, mkdir, mkdtemp, readFile, realpath, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const testDirectory = dirname(fileURLToPath(import.meta.url));
const engineTools = join(testDirectory, '../../engine/tools');
const monitorPath = join(testDirectory, '../../bin/model-boundary-monitor.mjs');

async function writeCommand(directory, name, body) {
    await writeFile(join(directory, name), `#!/usr/bin/env node\n${body}\n`, { mode: 0o700 });
}

/** Runs the real initial benchmark script with a scripted model and returns the model step's exit code. */
async function runWithModel(modelScript) {
    return (await runWithModelDetails(modelScript)).code;
}

/** Same, but also reports whether the engine continued the session automatically. */
async function runWithModelDetails(modelScript, { npm = 'process.exit(process.argv.includes("build") ? 1 : 0);', inspect } = {}) {
    const root = await realpath(await mkdtemp(join(tmpdir(), 'benchforge-classification-')));
    const commands = join(root, 'commands');

    try {
        await Promise.all([
            mkdir(join(root, 'tools', 'browser-smoke'), { recursive: true }),
            mkdir(join(root, 'sablon', 'src'), { recursive: true }),
            mkdir(commands),
            mkdir(join(root, 'home')),
        ]);
        for (const name of ['benchmark.zsh', 'benchforge-process-environment.zsh', 'benchforge-model-policy.mjs', 'select-opencode-session.mjs', 'classify-opencode-failure.mjs']) {
            await copyFile(join(engineTools, name), join(root, 'tools', name));
        }
        await writeFile(join(root, 'tools', 'browser-smoke', 'check.mjs'), 'process.exit(1);\n');
        await writeFile(join(root, 'sablon', 'src', 'page.tsx'), 'export default function Page() { return null; }\n');
        await writeFile(join(root, 'prompt.txt'), 'fixture prompt');
        await writeFile(join(root, 'models.conf'), 'fixture-model|fixture/fake|35560\n');
        await writeFile(join(root, 'opencode.json'), '{}');
        await writeCommand(commands, 'npm', npm);
        await writeCommand(commands, 'opencode', [
            'const args = process.argv.slice(2);',
            'if (args[0] === "--version") { console.log("0.0.0-fixture"); process.exit(0); }',
            'if (args[0] === "session") { console.log(JSON.stringify([{ id: "ses_fixture1", directory: process.cwd(), title: "benchforge:fixture-run:fixture-model:initial", updated: 1 }])); process.exit(0); }',
            modelScript,
        ].join('\n'));

        const runner = spawn('/bin/zsh', ['-f', join(root, 'tools', 'benchmark.zsh'), 'fixture-run', 'prompt.txt', 'models.conf', '/', 'opencode.json'], {
            cwd: root,
            env: { PATH: `${commands}:${dirname(process.execPath)}:/usr/bin:/bin:/usr/sbin:/sbin`, HOME: join(root, 'home'), BENCHFORGE_BOUNDARY_MONITOR: monitorPath },
            stdio: 'ignore',
        });
        const watchdog = setTimeout(() => runner.kill('SIGKILL'), 60_000);
        await once(runner, 'exit');
        clearTimeout(watchdog);

        const result = await readFile(join(root, 'runs', 'fixture-run', '_results', 'fixture-model.result'), 'utf8');
        const autoContinue = await readFile(join(root, 'runs', 'fixture-run', '_logs', 'fixture-model.auto-continue.log'), 'utf8').catch(() => null);
        if (inspect) await inspect(join(root, 'runs', 'fixture-run'));
        return { code: result.trim().split('|')[2], autoContinue };
    } finally {
        await rm(root, { recursive: true, force: true });
    }
}

test('records a model that changed nothing as having no submission', async () => {
    assert.equal(await runWithModel('process.exit(0);'), '86');
});

test('records a provider-side failure separately from a model failure', async () => {
    const providerError = JSON.stringify({ type: 'error', error: { name: 'UnknownError', data: { message: 'Unexpected server error. Check server logs for details.' } } });
    assert.equal(await runWithModel(`console.log(${JSON.stringify(providerError)}); process.exit(1);`), '75');
});

test('keeps an ordinary model failure and a real submission unchanged', async () => {
    assert.equal(await runWithModel('process.exit(3);'), '3');
    assert.equal(await runWithModel('require("node:fs").writeFileSync("src/page.tsx", "export default function Page() { return 1; }\\n"); process.exit(0);'), '0');
});

const lengthStop = JSON.stringify({ type: 'step_finish', part: { reason: 'length', tokens: { output: 32000 } } });
const writeSubmission = 'require("node:fs").writeFileSync("src/page.tsx", "export default function Page() { return 1; }\\n");';

test('continues a model once in the same session when it hit the output limit without writing anything', async () => {
    const details = await runWithModelDetails([
        'if (args.includes("--session")) {',
        `  if (!args.includes("ses_fixture1")) process.exit(9);`,
        `  ${writeSubmission}`,
        '  process.exit(0);',
        '}',
        `console.log(${JSON.stringify(lengthStop)});`,
        'process.exit(0);',
    ].join('\n'));

    assert.equal(details.code, '0');
    assert.match(details.autoContinue, /reason=output-limit/);
    assert.match(details.autoContinue, /session=ses_fixture1/);
});

test('does not continue a model that stopped normally or already wrote files', async () => {
    const stopped = await runWithModelDetails('process.exit(0);');
    assert.equal(stopped.code, '86');
    assert.equal(stopped.autoContinue, null);

    const wrote = await runWithModelDetails(`${writeSubmission} console.log(${JSON.stringify(lengthStop)}); process.exit(0);`);
    assert.equal(wrote.code, '0');
    assert.equal(wrote.autoContinue, null);
});

test('scores a submission whose provider connection dropped at the end', async () => {
    const providerError = JSON.stringify({ type: 'error', error: { name: 'APIError', data: { statusCode: 500, message: 'Upstream request failed: Endpoint is unavailable.' } } });
    assert.equal(await runWithModel(`${writeSubmission} console.log(${JSON.stringify(providerError)}); process.exit(1);`), '77');
});

test('slims every workspace after validation and archives the export of a model that did not pass', async () => {
    const npm = [
        'const fs = require("node:fs");',
        'if (process.argv.includes("ci")) { fs.mkdirSync("node_modules/pkg", { recursive: true }); fs.writeFileSync("node_modules/pkg/index.js", "1"); }',
        'if (process.argv.includes("build")) {',
        '  fs.mkdirSync(".next/cache", { recursive: true });',
        '  fs.mkdirSync("out/_next", { recursive: true });',
        '  fs.writeFileSync("out/index.html", `<script src="${process.env.NEXT_PUBLIC_BASE_PATH}/_next/app.js"></script>`);',
        '}',
        'process.exit(0);',
    ].join('\n');
    const exists = (path) => access(path).then(() => true, () => false);
    let observed;

    await runWithModelDetails(writeSubmission + ' process.exit(0);', {
        npm,
        inspect: async (run) => {
            observed = {
                nodeModules: await exists(join(run, 'fixture-model', 'node_modules')),
                nextCache: await exists(join(run, 'fixture-model', '.next')),
                source: await exists(join(run, 'fixture-model', 'src', 'page.tsx')),
                export: await exists(join(run, 'fixture-model', 'out', 'index.html')),
                gallery: await readFile(join(run, '_gallery', 'fixture-model', 'index.html'), 'utf8').catch(() => null),
            };
        },
    });

    // The browser check fails in this fixture, so the model did not pass every stage; its export is archived anyway.
    assert.deepEqual({ ...observed, gallery: observed.gallery?.includes('/fixture-model/_next/') }, {
        nodeModules: false,
        nextCache: false,
        source: true,
        export: true,
        gallery: true,
    });
});
