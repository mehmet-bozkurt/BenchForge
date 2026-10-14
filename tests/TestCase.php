<?php

namespace Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * Config keys that point at files the application reads, writes or deletes.
     * During tests they must stay under storage/framework/testing, never the user's real data.
     */
    private const GUARDED_PATHS = [
        'benchforge.root_path',
        'benchforge.runs_path',
        'benchforge.runner_path',
        'benchforge.preparation_path',
        'benchforge.settings_path',
        'benchforge.home_path',
        'benchforge.opencode_db_path',
    ];

    private static bool $sandboxCleanupRegistered = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertBenchForgePathsAreSandboxed('before the test');
        $this->resetBenchForgeSandbox();

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->app !== null) {
                $this->assertBenchForgePathsAreSandboxed('after the test');
            }
        } finally {
            parent::tearDown();
        }
    }

    /**
     * Recreates an empty data root (prompts, config, runs), home, runner and preparation directory for every test.
     */
    private function resetBenchForgeSandbox(): void
    {
        $sandbox = (string) config('benchforge.test_sandbox_path');

        File::deleteDirectory($sandbox);

        if (! self::$sandboxCleanupRegistered) {
            self::$sandboxCleanupRegistered = true;
            register_shutdown_function(static fn (): bool => (new Filesystem)->deleteDirectory($sandbox));
        }

        foreach ([
            config('benchforge.root_path').'/prompts',
            config('benchforge.root_path').'/config',
            config('benchforge.runs_path'),
            config('benchforge.home_path'),
            config('benchforge.runner_path'),
            config('benchforge.preparation_path'),
        ] as $directory) {
            File::ensureDirectoryExists((string) $directory);
        }
    }

    private function assertBenchForgePathsAreSandboxed(string $moment): void
    {
        $testingRoot = storage_path('framework/testing').DIRECTORY_SEPARATOR;

        foreach (self::GUARDED_PATHS as $key) {
            $path = (string) config($key);

            if (! str_starts_with($path, $testingRoot)) {
                $this->fail("{$key} must stay under storage/framework/testing {$moment}, got: {$path}");
            }
        }
    }
}
