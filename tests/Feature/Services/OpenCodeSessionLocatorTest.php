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
