#!/usr/bin/env node
// Exercism JavaScript "practice" alistirmalarini BenchForge algoritma gorevlerine cevirir.
//
//   node engine/tools/import-exercism.mjs [--root <veri-koku>] [--source <exercism/javascript klasoru>]
//                                         [--ref <dal-veya-etiket>] [--only a,b] [--limit N]
//
// --source verilmezse https://github.com/exercism/javascript depodan (yalnizca bu adimda ag kullanilir) sig bir
// kopya alinir. Gorevler <veri-koku>/algorithm-tasks/<slug>/ altina yazilir; her gorev kendi kendini sinar:
// baslangic dosyasi testleri gecmemeli, Exercism'in ornek cozumu hepsini gecmeli.
import { execFile } from 'node:child_process';
import { cp, mkdir, mkdtemp, readdir, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { basename, dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';
import { SUPPORTED_MATCHERS } from './algorithm-jest-shim.mjs';

const execFileAsync = promisify(execFile);
const toolsDirectory = dirname(fileURLToPath(import.meta.url));
const testRunnerPath = join(toolsDirectory, 'algorithm-test-runner.mjs');
export const EXERCISM_REPOSITORY = 'https://github.com/exercism/javascript';
export const TASKS_DIRECTORY_NAME = 'algorithm-tasks';
const JEST_MATCHER_PATTERN = /^(toMatch\w*|toThrowError\w+|toHaveBeen\w+|toHaveReturn\w*|toHaveLast\w+|toHaveNth\w+|toBeCalled\w*|toReturn\w*|toSatisfy\w*|toBeOneOf|toEqualTypeOf|toBeTypeOf|toHaveClass|toBeEmpty\w*|toContainKey\w*|toBeArray\w*|toBeString|toBeNumber|toBeBoolean|toBeFunction|toBeObject|toBeInteger|toBeNegative|toBePositive|toBeFinite|toBeWithin|toIncludeAny\w*|toStartWith|toEndWith)$/u;
const SHIM_IMPORTS = 'describe, test, it, xtest, xit, xdescribe, expect, beforeEach, afterEach, beforeAll, afterAll';

/**
 * Converts a Jest spec to the shim's flavour. Returns the converted source and the reasons the exercise
 * cannot be used (empty when it can).
 */
export function convertSpec(source, solutionFile) {
    const unsupported = [];
    const solutionModule = `./${solutionFile.replace(/\.js$/u, '')}`;

    // Jest'in yapilari kabuktan gelir; Exercism testleri bunlari cogunlukla '@jest/globals' uzerinden iceri aktarir.
    let converted = source.replace(/^\s*import\s*\{[^}]*\}\s*from\s*(['"])@jest\/globals\1;?\s*$/gmu, '');

    converted = converted.replace(/from\s+(['"])(\.\/[^'"]+)\1/gu, (match, quote, specifier) => {
        const withoutExtension = specifier.replace(/\.(m?js|json)$/u, '');

        if (withoutExtension !== solutionModule) {
            unsupported.push(`baska bir dosya iceri aktariliyor: ${specifier}`);
        }

        return `from ${quote}${/\.(m?js|json)$/u.test(specifier) ? specifier : `${specifier}.js`}${quote}`;
    });

    for (const match of converted.matchAll(/from\s+(['"])([^./'"][^'"]*)\1/gu)) {
        unsupported.push(`paket iceri aktariliyor: ${match[2]}`);
    }

    if (/\brequire\(/u.test(converted)) {
        unsupported.push('require kullaniliyor');
    }

    if (/\bjest\./u.test(converted)) {
        unsupported.push('jest.* yardimcilari kullaniliyor');
    }

    if (/\.(each|resolves|rejects)\b/u.test(converted)) {
        unsupported.push('each/resolves/rejects kullaniliyor');
    }

    // Yalniz bilinen Jest eslestiricileri sayilir; testlerin kendi nesnelerinin metotlari (ornegin list.toArray()) sayilmaz.
    const matchers = new Set([...converted.matchAll(/\.(to[A-Z]\w*)\(/gu)].map((match) => match[1]));

    for (const matcher of matchers) {
        if (!SUPPORTED_MATCHERS.includes(matcher) && JEST_MATCHER_PATTERN.test(matcher)) {
            unsupported.push(`desteklenmeyen eslestirici: ${matcher}`);
        }
    }

    converted = converted.replace(/\bx(test|it|describe)\(/gu, '$1(');

    return {
        source: `import { ${SHIM_IMPORTS} } from './jest-shim.mjs';\n${converted}`,
        unsupported: [...new Set(unsupported)],
    };
}

function humanize(slug) {
    return slug.replace(/-/gu, ' ').replace(/^./u, (first) => first.toUpperCase());
}

async function readOptional(path) {
    try {
        return (await readFile(path, 'utf8')).trim();
    } catch {
        return '';
    }
}

export function buildPrompt({ slug, solutionFile, testFile, description }) {
    return [
        `Bu bir programlama alistirmasidir (Exercism: ${humanize(slug)}).`,
        '',
        `Calisma alanindaki \`${solutionFile}\` dosyasini duzenleyerek alistirmayi coz. Testler \`${testFile}\` dosyasindadir; testleri DEGISTIRME (degistirsen de dogrulamada orijinalleri kullanilir). Testleri \`node --test ${testFile}\` komutuyla calistirabilirsin. Baska dosya veya bagimlilik ekleme; cozumun yalnizca \`${solutionFile}\` icinde olsun.`,
        '',
        'Alistirma aciklamasi (Ingilizce):',
        '',
        description,
        '',
    ].join('\n');
}

/**
 * Grades a workspace in a child process (node:test cannot be started from inside another test run) and returns
 * the runner's result file.
 */
async function gradeInChild(taskDirectory, workspace) {
    const resultPath = `${workspace}.result.json`;

    try {
        // A parent test run (NODE_TEST_CONTEXT) would make the child skip its own run.
        const { NODE_TEST_CONTEXT: parentTestRun, ...environment } = process.env;
        void parentTestRun;
        await execFileAsync(process.execPath, [testRunnerPath, taskDirectory, workspace, resultPath], { env: environment });
    } catch (error) {
        // Exit code 1 only means that not every test passed; the result file is still written.
        if (error.code !== 1) {
            throw new Error(`testler calistirilamadi: ${error.stderr || error.message}`);
        }
    }

    return JSON.parse(await readFile(resultPath, 'utf8'));
}

/**
 * Imports one Exercism exercise directory. Returns { imported: true, ... } or { imported: false, reason }.
 */
export async function importExercise({ exerciseDirectory, outputRoot, commit = null, verify = true }) {
    const slug = basename(exerciseDirectory);
    const config = JSON.parse(await readFile(join(exerciseDirectory, '.meta', 'config.json'), 'utf8'));
    const { solution = [], test = [], example = [] } = config.files ?? {};

    if (solution.length !== 1 || test.length !== 1 || example.length !== 1) {
        return { imported: false, slug, reason: 'birden fazla cozum/test/ornek dosyasi var' };
    }

    const [solutionFile] = solution;
    const [testFile] = test;
    const [exampleFile] = example;

    const converted = convertSpec(await readFile(join(exerciseDirectory, testFile), 'utf8'), solutionFile);

    if (converted.unsupported.length > 0) {
        return { imported: false, slug, reason: converted.unsupported.join('; ') };
    }

    const description = [
        await readOptional(join(exerciseDirectory, '.docs', 'introduction.md')),
        await readOptional(join(exerciseDirectory, '.docs', 'instructions.md')),
        await readOptional(join(exerciseDirectory, '.docs', 'instructions.append.md')),
    ].filter((part) => part !== '').join('\n\n');

    if (description === '') {
        return { imported: false, slug, reason: 'aciklama bulunamadi' };
    }

    const taskDirectory = join(outputRoot, TASKS_DIRECTORY_NAME, slug);
    const workspace = join(taskDirectory, 'workspace');
    await rm(taskDirectory, { recursive: true, force: true });
    await mkdir(workspace, { recursive: true });
    await cp(join(exerciseDirectory, solutionFile), join(workspace, solutionFile));
    await writeFile(join(workspace, testFile), converted.source, 'utf8');
    await cp(join(toolsDirectory, 'algorithm-jest-shim.mjs'), join(workspace, 'jest-shim.mjs'));
    await writeFile(join(workspace, 'package.json'), `${JSON.stringify({ name: slug, private: true, type: 'module' }, null, 2)}\n`, 'utf8');
    await writeFile(join(taskDirectory, 'prompt.txt'), buildPrompt({ slug, solutionFile, testFile, description }), 'utf8');

    const task = {
        slug,
        title: humanize(slug),
        solutionFile,
        testFile,
        testCount: 0,
        source: 'Exercism JavaScript track',
        sourceUrl: `${EXERCISM_REPOSITORY}/tree/main/exercises/practice/${slug}`,
        exerciseSource: config.source ?? null,
        exerciseSourceUrl: config.source_url ?? null,
        license: 'MIT (Exercism JavaScript track); alistirma kaynagi icin exerciseSource alanina bakin',
        commit,
    };
    await writeFile(join(taskDirectory, 'task.json'), `${JSON.stringify(task, null, 2)}\n`, 'utf8');

    if (!verify) {
        return { imported: true, slug, testCount: 0 };
    }

    const scratchParent = await mkdtemp(join(tmpdir(), `benchforge-exercism-${slug}-`));
    const scratch = join(scratchParent, 'workspace');

    try {
        // Ornek cozum tum testleri gecmeli; bu ayni zamanda test sayisini verir.
        await cp(workspace, scratch, { recursive: true });
        await cp(join(exerciseDirectory, exampleFile), join(scratch, solutionFile));
        const withExample = await gradeInChild(taskDirectory, scratch);

        if (withExample.total === 0 || withExample.passed !== withExample.total) {
            throw new Error(`ornek cozum testleri gecmedi (${withExample.passed}/${withExample.total})`);
        }

        // Baslangic dosyasi gorevi cozmus olmamali.
        await cp(join(workspace, solutionFile), join(scratch, solutionFile));
        const withStub = await gradeInChild(taskDirectory, scratch);

        if (withStub.passed === withStub.total) {
            throw new Error('baslangic dosyasi zaten tum testleri geciyor');
        }

        task.testCount = withExample.total;
        await writeFile(join(taskDirectory, 'task.json'), `${JSON.stringify(task, null, 2)}\n`, 'utf8');

        return { imported: true, slug, testCount: task.testCount };
    } catch (error) {
        await rm(taskDirectory, { recursive: true, force: true });

        return { imported: false, slug, reason: `kendi kendini sinama basarisiz: ${error.message}` };
    } finally {
        await rm(scratchParent, { recursive: true, force: true });
    }
}

async function resolveSource({ source, ref }) {
    if (source) {
        return { directory: resolve(source), cleanup: async () => {} };
    }

    const directory = await mkdtemp(join(tmpdir(), 'benchforge-exercism-source-'));
    const args = ['clone', '--depth', '1', ...(ref ? ['--branch', ref] : []), EXERCISM_REPOSITORY, directory];
    await execFileAsync('git', args);

    return { directory, cleanup: () => rm(directory, { recursive: true, force: true }) };
}

async function currentCommit(directory) {
    try {
        return (await execFileAsync('git', ['-C', directory, 'rev-parse', 'HEAD'])).stdout.trim();
    } catch {
        return null;
    }
}

export function parseArguments(argv) {
    const options = { root: process.env.BENCHFORGE_DATA_ROOT ?? resolve(toolsDirectory, '..'), source: null, ref: null, only: null, limit: null };

    for (let index = 0; index < argv.length; index += 2) {
        const [name, value] = [argv[index], argv[index + 1]];

        if (value === undefined || value.startsWith('--')) {
            throw new Error(`${name} icin deger gerekli.`);
        }

        switch (name) {
            case '--root': options.root = resolve(value); break;
            case '--source': options.source = value; break;
            case '--ref': options.ref = value; break;
            case '--only': options.only = value.split(',').filter(Boolean); break;
            case '--limit': options.limit = Number.parseInt(value, 10); break;
            default: throw new Error(`Bilinmeyen secenek: ${name}`);
        }
    }

    if (options.limit !== null && !(options.limit > 0)) {
        throw new Error('--limit pozitif bir sayi olmali.');
    }

    return options;
}

export async function importExercism(options) {
    const { directory, cleanup } = await resolveSource(options);

    try {
        const practice = join(directory, 'exercises', 'practice');
        const commit = await currentCommit(directory);
        let slugs = (await readdir(practice, { withFileTypes: true })).filter((entry) => entry.isDirectory()).map((entry) => entry.name).sort();

        if (options.only) {
            slugs = slugs.filter((slug) => options.only.includes(slug));
        }

        const results = [];

        for (const slug of slugs) {
            if (options.limit !== null && results.filter((result) => result.imported).length >= options.limit) {
                break;
            }

            results.push(await importExercise({ exerciseDirectory: join(practice, slug), outputRoot: options.root, commit }));
        }

        return results;
    } finally {
        await cleanup();
    }
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    try {
        const options = parseArguments(process.argv.slice(2));
        const results = await importExercism(options);
        const imported = results.filter((result) => result.imported);
        const skipped = results.filter((result) => !result.imported);

        console.log(`${imported.length} alistirma ice aktarildi -> ${join(options.root, TASKS_DIRECTORY_NAME)}`);

        for (const result of skipped) {
            console.log(`  atlandi: ${result.slug} (${result.reason})`);
        }

        process.exitCode = imported.length > 0 ? 0 : 1;
    } catch (error) {
        console.error(`HATA: ${error.message}`);
        process.exitCode = 1;
    }
}
