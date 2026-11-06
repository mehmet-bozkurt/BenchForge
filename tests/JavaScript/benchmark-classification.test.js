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
async function runWithModelDetails(modelScript, { npm = 'process.exit(process.argv.includes("build") ? 1 : 0);', inspect, env = {} } = {}) {
    const root = await realpath(await mkdtemp(join(tmpdir(), 'benchforge-classification-')));
    const commands = join(root, 'commands');

    try {
        await Promise.all([
            mkdir(join(root, 'tools', 'browser-smoke'), { recursive: true }),
            mkdir(join(root, 'sablon', 'src'), { recursive: true }),
            mkdir(commands),
            mkdir(join(root, 'home')),
        ]);
        for (const name of ['benchmark.zsh', 'benchforge-process-environment.zsh', 'benchforge-model-policy.mjs', 'select-opencode-session.mjs', 'classify-opencode-failure.mjs', 'run-with-timeout.mjs']) {
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
            `require("node:fs").appendFileSync(${JSON.stringify(join(root, 'opencode-calls.jsonl'))}, JSON.stringify({ args, configHome: process.env.XDG_CONFIG_HOME ?? null, projectConfigDisabled: process.env.OPENCODE_DISABLE_PROJECT_CONFIG ?? null }) + "\\n");`,
            'if (args[0] === "--version") { console.log("0.0.0-fixture"); process.exit(0); }',
            'if (args[0] === "session") { console.log(JSON.stringify([{ id: "ses_fixture1", directory: process.cwd(), title: "benchforge:fixture-run:fixture-model:initial", updated: 1 }])); process.exit(0); }',
            modelScript,
        ].join('\n'));

        const runner = spawn('/bin/zsh', ['-f', join(root, 'tools', 'benchmark.zsh'), 'fixture-run', 'prompt.txt', 'models.conf', '/', 'opencode.json'], {
            cwd: root,
            env: { PATH: `${commands}:${dirname(process.execPath)}:/usr/bin:/bin:/usr/sbin:/sbin`, HOME: join(root, 'home'), BENCHFORGE_BOUNDARY_MONITOR: monitorPath, ...env },
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

test('runs every OpenCode command standalone and lists sessions without the user\'s or the workspace\'s config', async () => {
    let calls;
    let root;
    await runWithModelDetails([
        'if (args.includes("--session")) process.exit(0);',
        `console.log(${JSON.stringify(lengthStop)});`,
        'process.exit(0);',
    ].join('\n'), {
        inspect: async (run) => {
            root = dirname(dirname(run));
            calls = (await readFile(join(root, 'opencode-calls.jsonl'), 'utf8')).trim().split('\n').map((line) => JSON.parse(line));
        },
    });

    const modelCalls = calls.filter((call) => call.args[0] === 'run');
    const sessionList = calls.find((call) => call.args[0] === 'session');
    const benchforgeConfig = join(root, 'home', '.cache', 'benchforge', 'opencode-config');

    assert.equal(modelCalls.length, 2);
    assert.ok(modelCalls.every((call) => call.args[1] === '--standalone'));
    assert.equal(modelCalls[1].args[modelCalls[1].args.indexOf('--session') + 1], 'ses_fixture1');
    assert.deepEqual(sessionList.args, ['session', 'list', '--format', 'json', '-n', '200', '--standalone']);
    assert.equal(sessionList.configHome, benchforgeConfig);
    assert.equal(sessionList.projectConfigDisabled, '1');
    assert.ok(calls.every((call) => !call.args.includes('--pure') && !call.args.includes('--dir')));
});

// What OpenCode 2.2.5 does when a response is cut off three times: the error comes before the third cut-off
// step, and the call ends with exit 1 (1.x ended the same situation with a finished step and exit 0).
const outputLimitError = JSON.stringify({ type: 'error', sessionID: 'ses_fixture1', error: { type: 'output-limit', message: 'Response still truncated after two output token limit continuations' } });
const cutOffThreeTimes = `console.log(${JSON.stringify(lengthStop)}); console.log(${JSON.stringify(outputLimitError)}); console.log(${JSON.stringify(lengthStop)}); process.exit(1);`;

test('scores a submission written before OpenCode 2.x gave up on a cut-off response, instead of failing the model step', async () => {
    const details = await runWithModelDetails(`${writeSubmission} ${cutOffThreeTimes}`);

    assert.equal(details.code, '0');
    assert.equal(details.autoContinue, null);
});

test('continues once in the same session when OpenCode 2.x gave up on a response before anything was written', async () => {
    const details = await runWithModelDetails([
        'if (args.includes("--session")) {',
        `  ${writeSubmission}`,
        '  process.exit(0);',
        '}',
        cutOffThreeTimes,
    ].join('\n'));

    assert.equal(details.code, '0');
    assert.match(details.autoContinue, /reason=output-limit/);
    assert.match(details.autoContinue, /session=ses_fixture1/);
});

test('records no submission when the response is cut off again after the automatic continuation', async () => {
    const details = await runWithModelDetails(cutOffThreeTimes);

    assert.equal(details.code, '86');
    assert.match(details.autoContinue, /reason=output-limit/);
});

test('keeps an exit 1 with any other error as the model\'s failure', async () => {
    const otherError = JSON.stringify({ type: 'error', sessionID: 'ses_fixture1', error: { type: 'compaction.failed', message: 'Compaction summary did not follow the template.' } });

    assert.equal(await runWithModel(`console.log(${JSON.stringify(otherError)}); process.exit(1);`), '1');
    assert.equal(await runWithModel(`${writeSubmission} console.log(${JSON.stringify(otherError)}); process.exit(1);`), '1');
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

test('stops a build that never finishes at the step time limit and still records the run', async () => {
    // A model can add a script to its own build that never exits (for example a smoke test that keeps a server open).
    const npm = [
        'if (process.argv.includes("build")) {',
        '  require("node:child_process").spawn(process.execPath, ["-e", "setInterval(() => {}, 1000)"], { stdio: "inherit" });',
        '  setInterval(() => {}, 1000);',
        '} else {',
        '  process.exit(0);',
        '}',
    ].join('\n');
    let build;

    await runWithModelDetails(writeSubmission + ' process.exit(0);', {
        npm,
        env: { BENCHFORGE_STEP_TIMEOUT_SECONDS: '2' },
        inspect: async (run) => {
            build = {
                code: (await readFile(join(run, '_results', 'fixture-model.result'), 'utf8')).trim().split('|')[4],
                log: await readFile(join(run, '_logs', 'fixture-model.build.log'), 'utf8'),
            };
        },
    });

    assert.equal(build.code, '124');
    assert.match(build.log, /BENCHFORGE: Adim 2 saniyelik sure sinirini asti/);
});

// What OpenCode 2.2.5 wrote for a refused key, a failed connection and the start race (captured against a local
// fake provider and a fresh shared database).
const keyRefused = JSON.stringify({ type: 'error', sessionID: 'ses_fixture1', error: { type: 'provider.auth', message: 'User not found.', status: 401 } });
const transportFailure = JSON.stringify({ type: 'error', sessionID: 'ses_fixture1', error: { type: 'unknown', message: 'Transport: ECONNRESET: The socket connection was closed unexpectedly.' } });
const startupFailure = JSON.stringify({ type: 'error', sessionID: '', error: { type: 'unknown', message: 'Standalone server exited before reporting readiness' } });
const stepStart = JSON.stringify({ type: 'step_start', sessionID: 'ses_fixture1', part: { type: 'step-start' } });

test('records the 2.x errors of the provider separately from a model failure', async () => {
    assert.equal(await runWithModel(`console.log(${JSON.stringify(keyRefused)}); process.exit(1);`), '75');
    assert.equal(await runWithModel(`console.log(${JSON.stringify(transportFailure)}); process.exit(1);`), '75');
    // A submission written before the provider failed is still scored, as with 1.x.
    assert.equal(await runWithModel(`${writeSubmission} console.log(${JSON.stringify(transportFailure)}); process.exit(1);`), '77');
});

/** A model script whose first `failures` runs end with `event` (exit 1) before it writes a submission. */
function failingStarts(failures, event, { started = false } = {}) {
    return [
        'const fs = require("node:fs");',
        'const counter = require("node:path").join(process.cwd(), "..", "start-count");',
        'let runs = 0;',
        'try { runs = Number(fs.readFileSync(counter, "utf8")); } catch {}',
        'fs.writeFileSync(counter, String(runs + 1));',
        `if (runs < ${failures}) {`,
        started ? `  console.log(${JSON.stringify(stepStart)});` : '',
        `  console.log(${JSON.stringify(event)});`,
        '  process.exit(1);',
        '}',
        writeSubmission,
        'process.exit(0);',
    ].join('\n');
}

async function startsAndLog(modelScript) {
    let starts = null;
    let eventLog = null;
    const details = await runWithModelDetails(modelScript, {
        env: { BENCHFORGE_BASLATMA_BEKLEME_SANIYE: '0' },
        inspect: async (runDirectory) => {
            starts = Number(await readFile(join(runDirectory, 'start-count'), 'utf8'));
            eventLog = await readFile(join(runDirectory, '_logs', 'fixture-model.opencode.events.jsonl'), 'utf8');
        },
    });

    return { ...details, starts, eventLog };
}

test('starts a model again when its OpenCode server did not start, and scores the later run', async () => {
    const details = await startsAndLog(failingStarts(2, startupFailure));

    assert.equal(details.code, '0');
    assert.equal(details.starts, 3);
    // The failed starts left nothing in the log of the run that counts.
    assert.doesNotMatch(details.eventLog, /Standalone server/);
});

test('gives up on a start that keeps failing and records a provider-side failure', async () => {
    const details = await startsAndLog(failingStarts(9, startupFailure));

    assert.equal(details.code, '75');
    assert.equal(details.starts, 4);
});

test('does not start a model again for any other failure, or once it has begun a step', async () => {
    assert.equal((await startsAndLog(failingStarts(9, keyRefused))).starts, 1);
    assert.equal((await startsAndLog(failingStarts(9, transportFailure))).starts, 1);
    assert.equal((await startsAndLog(failingStarts(9, startupFailure, { started: true }))).starts, 1);
});

test('starts the automatic continuation again when its OpenCode server did not start', async () => {
    let continuations = null;
    const details = await runWithModelDetails([
        'if (args.includes("--session")) {',
        '  const fs = require("node:fs");',
        '  const counter = require("node:path").join(process.cwd(), "..", "continue-count");',
        '  let runs = 0;',
        '  try { runs = Number(fs.readFileSync(counter, "utf8")); } catch {}',
        '  fs.writeFileSync(counter, String(runs + 1));',
        `  if (runs < 2) { console.log(${JSON.stringify(startupFailure)}); process.exit(1); }`,
        `  ${writeSubmission}`,
        '  process.exit(0);',
        '}',
        `console.log(${JSON.stringify(lengthStop)});`,
        'process.exit(0);',
    ].join('\n'), {
        env: { BENCHFORGE_BASLATMA_BEKLEME_SANIYE: '0' },
        inspect: async (runDirectory) => {
            continuations = Number(await readFile(join(runDirectory, 'continue-count'), 'utf8'));
        },
    });

    // The first run's logs stay; only the failed continuation starts were thrown away.
    assert.equal(details.code, '0');
    assert.equal(continuations, 3);
    assert.match(details.autoContinue, /reason=output-limit/);
});
