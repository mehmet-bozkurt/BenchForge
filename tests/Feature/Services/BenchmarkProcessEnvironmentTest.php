<?php

use App\Services\BenchmarkProcessEnvironment;
use Illuminate\Support\Facades\Process as LaravelProcess;
use Symfony\Component\Process\Process;

/** @param array<string, string> $variables */
function withBenchmarkEnvironmentFixture(array $variables, callable $callback): mixed
{
    $originalEnvironment = $_ENV;
    $originalServer = $_SERVER;
    $originalValues = [];

    foreach ($variables as $name => $value) {
        $originalValues[$name] = getenv($name);
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    try {
        return $callback();
    } finally {
        $_ENV = $originalEnvironment;
        $_SERVER = $originalServer;

        foreach ($originalValues as $name => $value) {
            putenv($value === false ? $name : $name.'='.$value);
        }
    }
}

test('it blocks secrets and execution injection variables in a real Symfony child process', function () {
    $blocked = [
        'APP_KEY', 'DB_PASSWORD', 'OPENROUTER_API_KEY', 'OPENCODE_API_KEY',
        'AWS_SECRET_ACCESS_KEY', 'BENCHFORGE_TEST_UNKNOWN_SECRET', 'NODE_OPTIONS',
        'BASH_ENV', 'ENV', 'ZDOTDIR', 'LD_PRELOAD', 'DYLD_INSERT_LIBRARIES',
        'GIT_CONFIG_COUNT', 'OPENCODE_CONFIG', 'OPENCODE_CONFIG_CONTENT', 'HTTP_PROXY',
    ];
    config()->set('benchforge.home_path', '/private/tmp');
    config()->set('benchforge.command_path', '/usr/bin:/bin');

    withBenchmarkEnvironmentFixture(array_fill_keys($blocked, 'benchforge-test-only-secret'), function () use ($blocked): void {
        $environment = app(BenchmarkProcessEnvironment::class)->make();
        $script = '$names = json_decode($argv[1], true); echo json_encode(array_map(fn ($name) => getenv($name) === false, $names));';
        $process = new Process([PHP_BINARY, '-r', $script, json_encode($blocked, JSON_THROW_ON_ERROR)], null, $environment);
        $process->mustRun();

        expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe(array_fill(0, count($blocked), true));
        expect($environment)->not->toContain('benchforge-test-only-secret');
    });
});

test('it blocks inherited application secrets through the Laravel process facade', function () {
    config()->set('benchforge.home_path', '/private/tmp');
    config()->set('benchforge.command_path', '/usr/bin:/bin');

    withBenchmarkEnvironmentFixture(['BENCHFORGE_TEST_FACADE_SECRET' => 'benchforge-facade-test-only-secret'], function (): void {
        $result = LaravelProcess::env(app(BenchmarkProcessEnvironment::class)->make())->timeout(3)->run([
            PHP_BINARY, '-r', 'echo getenv("BENCHFORGE_TEST_FACADE_SECRET") === false ? "blocked" : "leaked";',
        ]);

        expect($result->successful())->toBeTrue();
        expect($result->output())->toBe('blocked');
    });
});

test('it preserves explicitly safe paths locale and terminal preferences and uses configured home and path', function () {
    config()->set('benchforge.home_path', '/private/tmp/benchforge-configured-home');
    config()->set('benchforge.command_path', '/usr/bin:/bin');
    $safe = [
        'HOME' => '/private/tmp/untrusted-home', 'PATH' => '/private/tmp/untrusted-bin',
        'XDG_DATA_HOME' => '/private/tmp/benchforge-test-data', 'XDG_CONFIG_HOME' => '/private/tmp/benchforge-test-config',
        'XDG_CACHE_HOME' => '/private/tmp/benchforge-test-cache', 'XDG_STATE_HOME' => '/private/tmp/benchforge-test-state',
        'TMPDIR' => '/private/tmp/', 'TMP' => '/private/tmp/', 'TEMP' => '/private/tmp/',
        'LANG' => 'tr_TR.UTF-8', 'LANGUAGE' => 'tr', 'LC_CTYPE' => 'UTF-8', 'LC_ALL' => 'C',
        'LC_MESSAGES' => 'C', 'TZ' => 'Europe/Istanbul', 'TERM' => 'xterm-256color',
        'COLORTERM' => 'truecolor', 'NO_COLOR' => '1',
    ];

    withBenchmarkEnvironmentFixture($safe, function () use ($safe): void {
        $script = '$names = json_decode($argv[1], true); echo json_encode(array_combine($names, array_map("getenv", $names)));';
        $process = new Process([PHP_BINARY, '-r', $script, json_encode(array_keys($safe), JSON_THROW_ON_ERROR)], null, app(BenchmarkProcessEnvironment::class)->make());
        $process->mustRun();

        expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe(array_replace($safe, [
            'HOME' => '/private/tmp/benchforge-configured-home', 'PATH' => '/usr/bin:/bin',
        ]));
    });
});

test('it rejects unknown keys from every inheritance source and invalid safe values without changing the parent', function () {
    $originalEnvironment = $_ENV;
    $originalServer = $_SERVER;
    $_ENV['BENCHFORGE_TEST_ENV_SECRET'] = 'env-test-only-secret';
    $_SERVER['BENCHFORGE_TEST_SERVER_SECRET'] = 'server-test-only-secret';
    $_ENV['LC_NUMERIC'] = ['not-a-string'];
    $_ENV['LC_TIME'] = "C\0unsafe";

    try {
        $environment = app(BenchmarkProcessEnvironment::class)->make('/private/tmp', '/usr/bin:/bin');

        expect($environment)->toMatchArray([
            'BENCHFORGE_TEST_ENV_SECRET' => false, 'BENCHFORGE_TEST_SERVER_SECRET' => false,
            'LC_NUMERIC' => false, 'LC_TIME' => false, 'HOME' => '/private/tmp', 'PATH' => '/usr/bin:/bin',
        ])->not->toContain('env-test-only-secret')->not->toContain('server-test-only-secret');
        expect($_ENV['BENCHFORGE_TEST_ENV_SECRET'])->toBe('env-test-only-secret');
        expect($_SERVER['BENCHFORGE_TEST_SERVER_SECRET'])->toBe('server-test-only-secret');
    } finally {
        $_ENV = $originalEnvironment;
        $_SERVER = $originalServer;
    }
});
