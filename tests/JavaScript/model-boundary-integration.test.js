import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { copyFile, mkdtemp, mkdir, readFile, realpath, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const testDirectory = dirname(fileURLToPath(import.meta.url));
const engineTools = join(testDirectory, '../../engine/tools');
const monitorPath = join(testDirectory, '../../bin/model-boundary-monitor.mjs');

async function writeCommand(directory, name, source) {
    await writeFile(join(directory, name), `#!/usr/bin/env node\n${source}\n`, { mode: 0o700 });
}

test('wires a resumed model to its scoped policy and monitor without live services or losing prior artifacts', async () => {
    const fixtureRoot = await mkdtemp(join(tmpdir(), 'benchforge-boundary-integration-'));
    const toolsPath = join(fixtureRoot, 'tools');
    const stubPath = join(fixtureRoot, 'commands');
    const runPath = join(fixtureRoot, 'runs', 'fixture-run');
    const modelSlug = 'fixture-model';
    const workspace = join(runPath, modelSlug);
    const attemptPath = join(runPath, '_attempts', modelSlug, 'attempt-1');
    const providerConfigPath = join(runPath, '_opencode.json');
    const promptPath = join(attemptPath, 'continuation-prompt.txt');
    const providerConfig = '{"provider":{"fixture":{"models":{"fake/model":{"options":{"fixture":true}}}}}}\n';
    let runner;

    try {
        await Promise.all([
            mkdir(join(toolsPath, 'browser-smoke'), { recursive: true }),
            mkdir(stubPath, { recursive: true }),
            mkdir(join(workspace, 'src'), { recursive: true }),
            mkdir(join(workspace, 'out', '_next'), { recursive: true }),
            mkdir(attemptPath, { recursive: true }),
            mkdir(join(runPath, '_gallery', modelSlug), { recursive: true }),
        ]);
        for (const name of ['resume-model.zsh', 'benchforge-process-environment.zsh', 'benchforge-model-policy.mjs', 'select-opencode-session.mjs', 'extract-opencode-event-session.mjs']) {
            await copyFile(join(engineTools, name), join(toolsPath, name));
        }
        await writeFile(join(toolsPath, 'browser-smoke', 'check.mjs'), 'throw new Error("Browser must not run in this fixture");\n');
        await writeFile(join(workspace, 'src', 'page.tsx'), 'fixture own source\n');
        await writeFile(join(workspace, 'out', 'index.html'), 'last-good-export\n');
        await writeFile(join(runPath, '_gallery', modelSlug, 'index.html'), 'last-good-preview\n');
        await writeFile(providerConfigPath, providerConfig);
        await writeFile(promptPath, 'OWN_DIAGNOSTIC: src/page.tsx:31 fixture lint message\nRequired routes: /, /incidents\n');

        await writeCommand(stubPath, 'opencode', [
            'const fs = require("node:fs");',
            'const args = process.argv.slice(2);',
            'if (args[0] !== "run") process.exit(93);',
            'fs.writeFileSync("opencode-observed.json", JSON.stringify({ args, cwd: process.cwd(), configPath: process.env.OPENCODE_CONFIG, runtime: JSON.parse(process.env.OPENCODE_CONFIG_CONTENT), basePath: process.env.NEXT_PUBLIC_BASE_PATH, projectConfigDisabled: process.env.OPENCODE_DISABLE_PROJECT_CONFIG, pure: process.env.OPENCODE_PURE, externalSkillsDisabled: process.env.OPENCODE_DISABLE_EXTERNAL_SKILLS, environmentKeys: Object.keys(process.env) }));',
            'for (const id of ["denial-one", "denial-two", "denial-three"]) console.log(JSON.stringify({ type: "tool_use", sessionID: "ses_fixtureContinued", part: { callID: id, tool: "bash", state: { status: "error", input: { command: "cat ../_logs/other-model.build.log" }, error: "The user has specified a rule which prevents you from using this specific tool call. Here are some of the relevant rules [{\\\"permission\\\":\\\"external_directory\\\",\\\"pattern\\\":\\\"*\\\",\\\"action\\\":\\\"deny\\\"}]" } } }));',
            'setInterval(() => {}, 1000);',
        ].join('\n'));
        await writeCommand(stubPath, 'npm', [
            'const fs = require("node:fs");',
            'const args = process.argv.slice(2);',
            'fs.appendFileSync("npm-observed.jsonl", JSON.stringify({ args, runtime: process.env.OPENCODE_CONFIG_CONTENT, pure: process.env.OPENCODE_PURE, projectConfigDisabled: process.env.OPENCODE_DISABLE_PROJECT_CONFIG, basePath: process.env.NEXT_PUBLIC_BASE_PATH, environmentKeys: Object.keys(process.env) }) + "\\n");',
            'if (args[0] === "ci") { fs.mkdirSync("node_modules", { recursive: true }); process.exit(0); }',
            'if (args.join(" ") === "run lint") { console.log("fixture lint passed"); process.exit(0); }',
            'if (args.join(" ") === "run build") { console.log("fixture build failed without export"); process.exit(1); }',
            'process.exit(94);',
        ].join('\n'));
        await writeCommand(stubPath, 'rsync', [
            'const fs = require("node:fs");',
            'const path = require("node:path");',
            'const args = process.argv.slice(2);',
            'const source = path.resolve(args.at(-2));',
            'const destination = path.resolve(args.at(-1));',
            `const root = fs.realpathSync(${JSON.stringify(fixtureRoot)});`,
            'if (!source.startsWith(root + path.sep) || !destination.startsWith(root + path.sep)) process.exit(95);',
            'const excluded = new Set([".git", ".next", "node_modules", "out"]);',
            'fs.cpSync(source, destination, { recursive: true, filter: (file) => !path.relative(source, file).split(path.sep).some((part) => excluded.has(part)) });',
        ].join('\n'));
        for (const name of ['curl', 'python3']) {
            await writeCommand(stubPath, name, `require("node:fs").writeFileSync(${JSON.stringify(join(fixtureRoot, 'unexpected-live-service'))}, "called"); process.exit(96);`);
        }

        runner = spawn('/bin/zsh', ['-f', join(toolsPath, 'resume-model.zsh'), runPath, modelSlug, 'fixture/fake-model', '35550', '/,/incidents', providerConfigPath, attemptPath, 'continue', 'ses_fixtureSource', promptPath], {
            cwd: fixtureRoot,
            env: {
                ...process.env,
                PATH: `${stubPath}:${dirname(process.execPath)}:${process.env.PATH}`,
                BENCHFORGE_BOUNDARY_MONITOR: monitorPath,
                APP_KEY: 'fixture-app-secret',
                OPENROUTER_API_KEY: 'fixture-provider-secret',
                DB_PASSWORD: 'fixture-database-secret',
                NODE_OPTIONS: '--no-warnings',
                OPENCODE_CONFIG: '',
                OPENCODE_CONFIG_CONTENT: '',
                OPENCODE_DISABLE_PROJECT_CONFIG: '',
                OPENCODE_PURE: '',
                OPENCODE_DISABLE_EXTERNAL_SKILLS: '',
                NEXT_PUBLIC_BASE_PATH: '',
            },
            stdio: 'ignore',
        });
        const watchdog = setTimeout(() => runner.kill('SIGTERM'), 10_000);

        try {
            const [code, signal] = await once(runner, 'exit');
            const canonicalWorkspace = await realpath(workspace);
            const observed = JSON.parse(await readFile(join(workspace, 'opencode-observed.json'), 'utf8'));
            const npmCalls = (await readFile(join(workspace, 'npm-observed.jsonl'), 'utf8')).trim().split('\n').map((line) => JSON.parse(line));
            const report = JSON.parse(await readFile(join(attemptPath, 'boundary-report.json'), 'utf8'));
            const metadata = JSON.parse(await readFile(join(attemptPath, 'model-policy', 'metadata.json'), 'utf8'));
            const result = (await readFile(join(attemptPath, 'result.txt'), 'utf8')).trim().split('|');

            assert.equal(code, 0);
            assert.equal(signal, null);
            assert.equal(observed.cwd, canonicalWorkspace);
            assert.equal(observed.args[observed.args.indexOf('--dir') + 1], canonicalWorkspace);
            assert.equal(observed.args[observed.args.indexOf('--agent') + 1], 'benchforge-workspace');
            assert.equal(observed.args[observed.args.indexOf('--format') + 1], 'json');
            assert.equal(observed.args[observed.args.indexOf('--session') + 1], 'ses_fixtureSource');
            assert.ok(observed.args.includes('--fork'));
            assert.match(observed.args.at(-1), /OWN_DIAGNOSTIC: src\/page\.tsx:31/);
            assert.match(observed.args.at(-1), /Work only inside the assigned workspace/);
            assert.equal(observed.runtime.permission.external_directory, 'deny');
            assert.equal(observed.runtime.agent['benchforge-workspace'].permission.external_directory, 'deny');
            assert.equal(observed.basePath, '/fixture-model');
            assert.equal(observed.projectConfigDisabled, '1');
            assert.equal(observed.pure, '1');
            assert.equal(observed.externalSkillsDisabled, '1');
            assert.equal(observed.configPath, await realpath(providerConfigPath));
            assert.equal(metadata.policyVersion, 'workspace-boundary-v1');
            assert.equal(metadata.workspace, canonicalWorkspace);
            assert.equal(report.blockedCount, 3);
            assert.equal(report.stoppedForBoundary, true);
            assert.equal(report.observedCrossModelReadCount, 0);
            assert.deepEqual(result.slice(0, 9), ['continue', 'ses_fixtureSource', 'ses_fixtureContinued', '78', '0', '1', '1', '1', '1']);
            assert.equal(result[10], 'fixture/fake-model');
            // The workspace was slimmed after the first run, so dependencies are reinstalled before the model runs.
            assert.deepEqual(npmCalls.map((call) => call.args), [['ci', '--prefer-offline', '--no-audit', '--no-fund'], ['run', 'lint'], ['run', 'build']]);
            assert.ok(npmCalls.every((call) => call.runtime === undefined && call.pure === undefined && call.projectConfigDisabled === undefined));
            assert.equal(npmCalls[0].basePath, undefined);
            assert.equal(npmCalls[1].basePath, undefined);
            assert.equal(npmCalls[2].basePath, '/fixture-model');
            for (const keys of [observed.environmentKeys, ...npmCalls.map((call) => call.environmentKeys)]) {
                for (const key of ['APP_KEY', 'OPENROUTER_API_KEY', 'DB_PASSWORD', 'NODE_OPTIONS']) assert.ok(!keys.includes(key));
            }
            assert.match(await readFile(join(attemptPath, 'lint.log'), 'utf8'), /fixture lint passed/);
            assert.match(await readFile(join(attemptPath, 'build.log'), 'utf8'), /fixture build failed/);
            assert.equal(await readFile(providerConfigPath, 'utf8'), providerConfig);
            assert.equal(await readFile(join(workspace, 'out', 'index.html'), 'utf8'), 'last-good-export\n');
            assert.equal(await readFile(join(runPath, '_gallery', modelSlug, 'index.html'), 'utf8'), 'last-good-preview\n');
            assert.equal(await readFile(join(attemptPath, 'workspace-before', 'src', 'page.tsx'), 'utf8'), 'fixture own source\n');
            await assert.rejects(readFile(join(attemptPath, 'workspace-before', 'out', 'index.html'), 'utf8'), { code: 'ENOENT' });
            await assert.rejects(readFile(join(fixtureRoot, 'unexpected-live-service'), 'utf8'), { code: 'ENOENT' });
        } finally {
            clearTimeout(watchdog);
        }
    } finally {
        if (runner?.exitCode === null && runner.signalCode === null) runner.kill('SIGTERM');
        await rm(fixtureRoot, { recursive: true, force: true });
    }
});
