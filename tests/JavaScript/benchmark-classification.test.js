import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { copyFile, mkdir, mkdtemp, readFile, realpath, rm, writeFile } from 'node:fs/promises';
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
        await writeCommand(commands, 'npm', 'process.exit(process.argv.includes("build") ? 1 : 0);');
        await writeCommand(commands, 'opencode', [
            'const args = process.argv.slice(2);',
            'if (args[0] === "--version") { console.log("0.0.0-fixture"); process.exit(0); }',
            'if (args[0] === "session") { console.log("[]"); process.exit(0); }',
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
        return result.trim().split('|')[2];
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
