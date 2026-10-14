<?php

// engine/ holds the runner scripts and the starting template shipped with this repository.
// The data root (BENCHFORGE_ROOT) holds runs/, prompts/ and config/; it defaults to engine/ itself.
$enginePath = base_path('engine');

// Tests never see the real data root, home directory (and its OpenCode database) or runner state from .env:
// every path below points into a throwaway sandbox that tests/TestCase.php recreates before each test.
// The process id keeps parallel workers and concurrent test runs apart.
$testSandbox = env('APP_ENV') === 'testing'
    ? storage_path('framework/testing/benchforge-sandbox-'.getmypid())
    : null;

$runsDirectory = $testSandbox === null ? env('BENCHFORGE_RUNS_DIR') : null;
$rootPath = $testSandbox !== null
    ? $testSandbox.'/data'
    : env('BENCHFORGE_ROOT', $runsDirectory !== null ? dirname($runsDirectory) : $enginePath);
$homePath = $testSandbox !== null ? $testSandbox.'/home' : env('BENCHFORGE_HOME', base_path());

return [
    'test_sandbox_path' => $testSandbox,
    'engine_path' => $enginePath,
    'root_path' => $rootPath,
    'runs_path' => $runsDirectory ?? $rootPath.'/runs',
    'benchmark_script' => $enginePath.'/tools/benchmark.zsh',
    'resume_script' => $enginePath.'/tools/resume-model.zsh',
    'runner_path' => $testSandbox !== null ? $testSandbox.'/runner' : storage_path('app/private/benchforge-runner'),
    'preparation_path' => $testSandbox !== null ? $testSandbox.'/preparations' : storage_path('app/private/benchforge-preparations'),
    'settings_path' => $testSandbox !== null ? $testSandbox.'/system/settings.json' : storage_path('app/private/benchforge-system/settings.json'),
    'preparation_timeout_seconds' => 900,
    'node_binary' => env('BENCHFORGE_NODE_BINARY', 'node'),
    'shell_binary' => env('BENCHFORGE_SHELL_BINARY', 'zsh'),
    'command_path' => env('BENCHFORGE_COMMAND_PATH', env('PATH', '/usr/local/bin:/usr/bin:/bin')),
    'home_path' => $homePath,
    'opencode_db_path' => $testSandbox !== null
        ? $homePath.'/.local/share/opencode/opencode.db'
        : env('BENCHFORGE_OPENCODE_DB_PATH', rtrim(env('XDG_DATA_HOME', rtrim($homePath, '/').'/.local/share'), '/').'/opencode/opencode.db'),
    'opencode_db_path_derived' => $testSandbox !== null || (env('BENCHFORGE_OPENCODE_DB_PATH') === null && env('XDG_DATA_HOME') === null),
];
