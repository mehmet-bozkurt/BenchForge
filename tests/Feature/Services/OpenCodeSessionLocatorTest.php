<?php

use App\Services\OpenCodeSessionLocator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

test('it selects only sessions from the exact workspace and honors a valid preference', function () {
    $temporaryRoot = storage_path('framework/testing/opencode-sessions-'.Str::uuid());
    $workspace = $temporaryRoot.'/workspace';
    $otherWorkspace = $temporaryRoot.'/workspace-copy';

    File::ensureDirectoryExists($workspace);
    File::ensureDirectoryExists($otherWorkspace);

    try {
        $sessions = [
            [
                'id' => 'ses_older',
                'directory' => $workspace,
                'title' => 'BenchForge initial',
                'updated' => 100,
            ],
            [
                'id' => 'ses_newest',
                'directory' => $workspace,
                'title' => 'BenchForge continuation',
                'updated' => 300,
            ],
            [
                'id' => 'ses_outside',
                'directory' => $otherWorkspace,
                'title' => 'BenchForge initial',
                'updated' => 900,
            ],
            [
                'id' => 'not-a-session-id',
                'directory' => $workspace,
                'updated' => 1000,
            ],
        ];
        $locator = app(OpenCodeSessionLocator::class);

        expect($locator->select($sessions, $workspace))->toBe('ses_newest')
            ->and($locator->select($sessions, $workspace, 'ses_older'))->toBe('ses_older')
            ->and($locator->select($sessions, $workspace, 'ses_outside'))->toBeNull()
            ->and($locator->select($sessions, $workspace, 'ses_outside', 'BenchForge initial'))->toBe('ses_older')
            ->and($locator->select($sessions, $workspace, 'ses_outside', 'Missing title'))->toBeNull()
            ->and($locator->select($sessions, $workspace, title: 'BenchForge initial'))->toBe('ses_older')
            ->and($locator->select($sessions, $workspace, title: 'Missing title'))->toBeNull()
            ->and($locator->select($sessions, $otherWorkspace))->toBe('ses_outside');
    } finally {
        File::deleteDirectory($temporaryRoot);
    }
});

test('it returns null when the workspace cannot be resolved', function () {
    $locator = app(OpenCodeSessionLocator::class);

    expect($locator->select([
        ['id' => 'ses_candidate', 'directory' => storage_path()],
    ], storage_path('framework/testing/missing-workspace-'.Str::uuid())))->toBeNull();
});

test('it lists each workspace with the standalone CLI and the BenchForge config', function () {
    $root = storage_path('framework/testing/opencode-session-list-'.Str::uuid());
    $binPath = $root.'/bin';
    $script = <<<'SH'
        #!/bin/sh
        directory=$(pwd -P)
        printf '%s|%s|%s|%s\n' "$*" "$XDG_CONFIG_HOME" "$OPENCODE_DISABLE_PROJECT_CONFIG" "$directory" >> '{calls}'
        [ "$*" = 'session list --format json -n 200 --standalone' ] || exit 1
        printf '[{"id":"ses_%s","directory":"%s","title":"t","updated":1}]' "${directory##*/}" "$directory"
        SH;

    File::ensureDirectoryExists($binPath);
    File::ensureDirectoryExists($root.'/alpha');
    File::ensureDirectoryExists($root.'/beta');
    File::put($binPath.'/opencode', str_replace('{calls}', $root.'/calls.log', $script)."\n");
    chmod($binPath.'/opencode', 0755);
    config()->set('benchforge.command_path', $binPath);

    try {
        $locator = app(OpenCodeSessionLocator::class);

        expect($locator->locate($root.'/alpha'))->toBe('ses_alpha')
            ->and($locator->locate($root.'/beta'))->toBe('ses_beta')
            ->and($locator->locate($root.'/alpha'))->toBe('ses_alpha');

        $calls = collect(File::lines($root.'/calls.log'))->filter()->values();
        [$arguments, $configHome, $projectConfigDisabled, $directory] = explode('|', $calls[0]);

        expect($calls)->toHaveCount(2)
            ->and($arguments)->toBe('session list --format json -n 200 --standalone')
            ->and($configHome)->toEndWith('/benchforge/opencode-config')
            ->and($projectConfigDisabled)->toBe('1')
            ->and($directory)->toBe(realpath($root.'/alpha'));
    } finally {
        File::deleteDirectory($root);
    }
});
