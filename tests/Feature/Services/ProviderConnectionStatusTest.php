<?php

use App\Services\ProviderConnectionStatus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

test('it reports the CLI and configured providers without exposing credential output', function () {
    $root = storage_path('framework/testing/provider-status-'.Str::uuid());
    $binPath = $root.'/bin';

    File::ensureDirectoryExists($binPath);
    File::put(
        $binPath.'/opencode',
        "#!/bin/sh\nif [ \"\$1 \$2\" = \"auth list\" ]; then printf 'OpenRouter\\nOpenCode Go\\n'; else echo 1.18.31; fi\n",
    );
    chmod($binPath.'/opencode', 0755);
    config()->set('benchforge.command_path', $binPath);
    config()->set('benchforge.home_path', $root);

    try {
        $status = app(ProviderConnectionStatus::class)->inspect();

        expect($status['cli'])->toBe([
            'status' => 'pass',
            'label' => 'OpenCode CLI',
            'message' => '1.18.31',
        ])->and($status['providers']['openrouter'])->toBe([
            'status' => 'pass',
            'label' => 'OpenRouter',
            'message' => 'OpenCode kimlik deposunda bağlantı hazır.',
        ])->and($status['providers']['opencode-go'])->toBe([
            'status' => 'pass',
            'label' => 'OpenCode Go',
            'message' => 'OpenCode kimlik deposunda bağlantı hazır.',
        ])->and(json_encode($status))->not->toContain('auth list');
    } finally {
        File::deleteDirectory($root);
    }
});

test('it reports unknown provider states when the OpenCode CLI is unavailable', function () {
    $root = storage_path('framework/testing/provider-status-missing-'.Str::uuid());

    File::ensureDirectoryExists($root);
    config()->set('benchforge.command_path', $root);
    config()->set('benchforge.home_path', $root);

    try {
        $status = app(ProviderConnectionStatus::class)->inspect();

        expect($status['cli']['status'])->toBe('fail')
            ->and($status['providers']['openrouter']['status'])->toBe('unknown')
            ->and($status['providers']['opencode-go']['status'])->toBe('unknown');
    } finally {
        File::deleteDirectory($root);
    }
});
