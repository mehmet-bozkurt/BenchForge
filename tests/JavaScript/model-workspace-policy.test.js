import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { mkdtemp, mkdir, readFile, realpath, rm, stat, symlink, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { promisify } from 'node:util';
import { fileURLToPath, pathToFileURL } from 'node:url';
import test from 'node:test';

const projectRoot = process.env.BENCHFORGE_PROJECT_ROOT
    ?? resolve(dirname(fileURLToPath(import.meta.url)), '../../../../frontend-model-benchmark');
const helperPath = join(projectRoot, 'tools/benchforge-model-policy.mjs');
const { buildModelPolicy, writeModelPolicy, POLICY_VERSION, POLICY_AGENT } = await import(pathToFileURL(helperPath));
const execute = promisify(execFile);
const workspace = '/fixture/benchforge/runs/fixture-run/fixture-model';

function ruleDecision(rules, input) {
    let decision = 'deny';

    for (const [pattern, action] of Object.entries(rules)) {
        const expression = pattern
            .replace(/[.+^${}()|[\]\\]/gu, '\\$&')
            .replaceAll('*', '.*')
            .replaceAll('?', '.');

        if (new RegExp(`^${expression}$`, 'su').test(input)) {
            decision = action;
        }
    }

    return decision;
}

test('applies identical versioned permissions and own basePath in initial and continuation calls', () => {
    const initial = buildModelPolicy({ workspace, modelSlug: 'fixture-model', mode: 'initial' });
    const continuation = buildModelPolicy({ workspace, modelSlug: 'fixture-model', mode: 'continue' });

    assert.deepEqual(initial.config.permission, continuation.config.permission);
    assert.equal(initial.metadata.version, 'workspace-boundary-v1');
    assert.equal(continuation.metadata.policyVersion, POLICY_VERSION);
    assert.equal(initial.metadata.mode, 'initial');
    assert.equal(continuation.metadata.mode, 'continue');
    assert.equal(initial.metadata.basePath, '/fixture-model');
    assert.equal(initial.metadata.operatingSystemSandbox, false);
    assert.match(initial.instructions, /never hunt for historical or other models' logs/u);
    assert.match(continuation.instructions, /lint\/build execute project code under the host account/u);
    assert.equal(initial.config.default_agent, POLICY_AGENT);
    assert.deepEqual(initial.config.agent[POLICY_AGENT].permission, initial.config.permission);
});

test('allows own source and dependency reads while denying other workspaces, secrets and shared history', () => {
    const { config } = buildModelPolicy({ workspace, modelSlug: 'fixture-model', mode: 'initial' });

    for (const tool of ['read', 'edit']) {
        const rules = config.permission[tool];

        for (const target of ['src/app/page.tsx', 'next.config.ts', 'package.json', 'node_modules/typescript/lib/lib.dom.d.ts', `${workspace}/src/app/page.tsx`]) {
            assert.equal(ruleDecision(rules, target), 'allow', `${tool}: ${target}`);
        }

        for (const target of [
            '../other-model/src/app/page.tsx',
            '../../_logs/other-model.build.log',
            'src/../../other-model/src/page.tsx',
            '/fixture/benchforge/runs/fixture-run/other-model/package.json',
            '.env',
            '.env.local',
            'nested/.env.production',
            '.npmrc',
            'private.pem',
            'nested/auth.json',
            '.opencode/agents/unrestricted.md',
            'opencode.json',
            'history/_attempts/old.log',
            `${workspace}/.env`,
        ]) {
            assert.equal(ruleDecision(rules, target), 'deny', `${tool}: ${target}`);
        }
    }

    assert.equal(config.permission.external_directory, 'deny');
    assert.equal(config.permission.grep, 'deny');
    assert.equal(config.permission.task, 'deny');
    assert.equal(config.permission.skill, 'deny');
    assert.equal(ruleDecision(config.permission.glob, 'src/**/*.tsx'), 'allow');
    assert.equal(ruleDecision(config.permission.glob, '../other-model/**/*'), 'deny');
});

test('uses a small bash allowlist instead of arbitrary shell or unrestricted package manager commands', () => {
    const { config } = buildModelPolicy({ workspace, modelSlug: 'fixture-model', mode: 'initial' });
    const rules = config.permission.bash;

    for (const command of [
        'npm run lint',
        'npm run build',
        'npx --no-install tsc --noEmit',
        'npm install --ignore-scripts --no-audit --no-fund lucide-react',
        'npm install --ignore-scripts --no-audit --no-fund @radix-ui/react-dialog',
    ]) {
        assert.equal(ruleDecision(rules, command), 'allow', command);
    }

    for (const command of [
        'cat ../other-model/package.json',
        'node -e "require(\'fs\').readFileSync(\'../other-model\')"',
        'bash -c ls',
        'npm run arbitrary-script',
        'npm install lucide-react',
        'npm install --ignore-scripts --no-audit --no-fund ../other-model',
        'npm install --ignore-scripts --no-audit --no-fund file:/fixture/other-model',
        'npm install --ignore-scripts --no-audit --no-fund /fixture/other-model',
        'npm install --ignore-scripts --no-audit --no-fund lucide-react --prefix ../other-model',
        'npm install --ignore-scripts --no-audit --no-fund lucide-react --global',
        'npm install --ignore-scripts --no-audit --no-fund lucide-react --ignore-scripts=false',
        'npm install --ignore-scripts --no-audit --no-fund lucide-react --workspace other-model',
        'npm run build && cat ../other-model/package.json',
        'npm run build > ../other-model/output.log',
    ]) {
        assert.equal(ruleDecision(rules, command), 'deny', command);
    }
});

test('writes trusted metadata outside the workspace and leaves provider configuration unchanged', async () => {
    const fixtureRoot = await mkdtemp(join(tmpdir(), 'benchforge-policy-'));
    const modelWorkspace = join(fixtureRoot, 'fixture-model');
    const policyDirectory = join(fixtureRoot, '_policies/fixture-model');
    const providerPath = join(fixtureRoot, 'provider.json');
    const providerContent = JSON.stringify({ provider: { openrouter: { models: { 'fixture/model': { name: 'Fixture model' } } } } });

    try {
        await mkdir(modelWorkspace);
        await writeFile(providerPath, providerContent);

        await execute(process.execPath, [helperPath, modelWorkspace, 'fixture-model', 'initial', policyDirectory], {
            env: { ...process.env, OPENCODE_CONFIG: providerPath },
        });
        const metadata = JSON.parse(await readFile(join(policyDirectory, 'metadata.json'), 'utf8'));
        const runtime = JSON.parse(await readFile(join(policyDirectory, 'opencode-runtime.json'), 'utf8'));

        assert.equal(metadata.workspace, await realpath(modelWorkspace));
        assert.equal(metadata.modelSlug, 'fixture-model');
        assert.equal(metadata.strikesBeforeStop, 3);
        assert.equal((await stat(policyDirectory)).mode & 0o777, 0o700);
        assert.equal((await stat(join(policyDirectory, 'opencode-runtime.json'))).mode & 0o777, 0o600);
        assert.equal(runtime.provider, undefined);
        assert.equal(runtime.model, undefined);
        assert.equal(await readFile(providerPath, 'utf8'), providerContent);
        assert.match(await readFile(join(policyDirectory, 'instructions.txt'), 'utf8'), /Required static-export basePath: \/fixture-model/u);
    } finally {
        await rm(fixtureRoot, { recursive: true, force: true });
    }
});

test('rejects policy output inside the model workspace or through a workspace symlink', async () => {
    const fixtureRoot = await mkdtemp(join(tmpdir(), 'benchforge-policy-placement-'));
    const modelWorkspace = join(fixtureRoot, 'fixture-model');

    try {
        await mkdir(modelWorkspace);
        await symlink(modelWorkspace, join(fixtureRoot, 'workspace-alias'));

        for (const [policyDirectory, expectedError] of [
            [modelWorkspace, /outside the model workspace/u],
            [join(modelWorkspace, 'policy'), /outside the model workspace/u],
            [join(fixtureRoot, 'workspace-alias/policy'), /redirected symlink below the run root/u],
        ]) {
            await assert.rejects(writeModelPolicy({ workspace: modelWorkspace, modelSlug: 'fixture-model', mode: 'continue', policyDirectory }), expectedError);
        }
    } finally {
        await rm(fixtureRoot, { recursive: true, force: true });
    }
});

test('refuses to overwrite existing policy artifacts or follow artifact symlinks', async () => {
    const fixtureRoot = await mkdtemp(join(tmpdir(), 'benchforge-policy-existing-'));
    const modelWorkspace = join(fixtureRoot, 'fixture-model');
    const policyDirectory = join(fixtureRoot, 'policy');
    const protectedTarget = join(fixtureRoot, 'existing-record.json');

    try {
        await mkdir(modelWorkspace);
        await mkdir(policyDirectory);
        await writeFile(protectedTarget, 'existing-record');
        await symlink(protectedTarget, join(policyDirectory, 'metadata.json'));

        await assert.rejects(writeModelPolicy({ workspace: modelWorkspace, modelSlug: 'fixture-model', mode: 'initial', policyDirectory }), { code: 'EEXIST' });
        assert.equal(await readFile(protectedTarget, 'utf8'), 'existing-record');
    } finally {
        await rm(fixtureRoot, { recursive: true, force: true });
    }
});

test('rejects redirected policy parents without changing another model or outside target', async () => {
    const fixtureRoot = await mkdtemp(join(tmpdir(), 'benchforge-policy-parent-'));
    const runRoot = join(fixtureRoot, 'run');
    const modelWorkspace = join(runRoot, 'fixture-model');
    const targets = [join(runRoot, 'other-model/policy'), join(fixtureRoot, 'outside-target/policy')];

    try {
        await mkdir(modelWorkspace, { recursive: true });

        for (const [index, target] of targets.entries()) {
            await mkdir(target, { recursive: true, mode: 0o755 });
            await writeFile(join(target, 'retained.txt'), 'retained-content');
            const originalMode = (await stat(target)).mode & 0o777;
            const alias = join(runRoot, `policy-alias-${index}`);
            await symlink(dirname(target), alias);

            await assert.rejects(writeModelPolicy({
                workspace: modelWorkspace,
                modelSlug: 'fixture-model',
                mode: 'continue',
                policyDirectory: join(alias, 'policy'),
            }), /redirected symlink below the run root/u);
            assert.equal(await readFile(join(target, 'retained.txt'), 'utf8'), 'retained-content');
            assert.equal((await stat(target)).mode & 0o777, originalMode);
            await assert.rejects(stat(join(target, 'metadata.json')), { code: 'ENOENT' });
            await assert.rejects(stat(join(target, 'opencode-runtime.json')), { code: 'ENOENT' });
        }
    } finally {
        await rm(fixtureRoot, { recursive: true, force: true });
    }
});

test('rejects a workspace symlink redirected outside its own run root', async () => {
    const fixtureRoot = await mkdtemp(join(tmpdir(), 'benchforge-policy-workspace-root-'));
    const runRoot = join(fixtureRoot, 'run');
    const targetWorkspace = join(fixtureRoot, 'external/fixture-model');

    try {
        await mkdir(runRoot);
        await mkdir(targetWorkspace, { recursive: true });
        await writeFile(join(targetWorkspace, 'retained.txt'), 'retained-content');
        const originalMode = (await stat(targetWorkspace)).mode & 0o777;
        await symlink(targetWorkspace, join(runRoot, 'fixture-model'));

        await assert.rejects(writeModelPolicy({
            workspace: join(runRoot, 'fixture-model'),
            modelSlug: 'fixture-model',
            mode: 'initial',
            policyDirectory: join(runRoot, '_policies/fixture-model'),
        }), /workspace must belong directly to its trusted run root/u);
        assert.equal(await readFile(join(targetWorkspace, 'retained.txt'), 'utf8'), 'retained-content');
        assert.equal((await stat(targetWorkspace)).mode & 0o777, originalMode);
        await assert.rejects(stat(join(runRoot, '_policies')), { code: 'ENOENT' });
    } finally {
        await rm(fixtureRoot, { recursive: true, force: true });
    }
});

test('accepts a root alias when workspace and policy keep the same canonical run mapping', async () => {
    const fixtureRoot = await mkdtemp(join(tmpdir(), 'benchforge-policy-root-alias-'));
    const runRoot = join(fixtureRoot, 'run');
    const aliasRoot = join(fixtureRoot, 'run-alias');

    try {
        await mkdir(join(runRoot, 'fixture-model'), { recursive: true });
        await symlink(runRoot, aliasRoot);

        await writeModelPolicy({
            workspace: join(aliasRoot, 'fixture-model'),
            modelSlug: 'fixture-model',
            mode: 'continue',
            policyDirectory: join(aliasRoot, '_attempts/fixture-model/attempt-1/model-policy'),
        });
        const metadata = JSON.parse(await readFile(join(runRoot, '_attempts/fixture-model/attempt-1/model-policy/metadata.json'), 'utf8'));

        assert.equal(metadata.workspace, join(await realpath(runRoot), 'fixture-model'));
        assert.equal(metadata.policyVersion, POLICY_VERSION);
    } finally {
        await rm(fixtureRoot, { recursive: true, force: true });
    }
});

test('rejects policy destinations outside the trusted run root', async () => {
    const fixtureRoot = await mkdtemp(join(tmpdir(), 'benchforge-policy-outside-root-'));
    const runRoot = join(fixtureRoot, 'run');

    try {
        await mkdir(join(runRoot, 'fixture-model'), { recursive: true });

        await assert.rejects(writeModelPolicy({
            workspace: join(runRoot, 'fixture-model'),
            modelSlug: 'fixture-model',
            mode: 'initial',
            policyDirectory: join(fixtureRoot, 'outside-policy'),
        }), /inside the trusted run root/u);
        await assert.rejects(stat(join(fixtureRoot, 'outside-policy')), { code: 'ENOENT' });
    } finally {
        await rm(fixtureRoot, { recursive: true, force: true });
    }
});

test('rejects ambiguous workspace paths, slug mismatches and unsupported modes', () => {
    for (const input of [
        { workspace: './fixture-model', modelSlug: 'fixture-model', mode: 'initial' },
        { workspace: '/fixture/*/fixture-model', modelSlug: 'fixture-model', mode: 'initial' },
        { workspace, modelSlug: 'other-model', mode: 'initial' },
        { workspace, modelSlug: '../fixture-model', mode: 'initial' },
        { workspace, modelSlug: 'fixture-model', mode: 'validate' },
    ]) {
        assert.throws(() => buildModelPolicy(input));
    }
});
