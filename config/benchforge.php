<?php

// engine/ holds the runner scripts and the starting template shipped with this repository.
// The data root (BENCHFORGE_ROOT) holds runs/, prompts/ and config/; it defaults to engine/ itself.
$enginePath = base_path('engine');
$runsDirectory = env('BENCHFORGE_RUNS_DIR');
$rootPath = env('BENCHFORGE_ROOT', $runsDirectory !== null ? dirname($runsDirectory) : $enginePath);
$homePath = env('BENCHFORGE_HOME', base_path());

return [
    'engine_path' => $enginePath,
    'root_path' => $rootPath,
    'runs_path' => env('BENCHFORGE_RUNS_DIR', $rootPath.'/runs'),
    'benchmark_script' => $enginePath.'/tools/benchmark.zsh',
    'resume_script' => $enginePath.'/tools/resume-model.zsh',
    'runner_path' => storage_path('app/private/benchforge-runner'),
    'preparation_path' => storage_path('app/private/benchforge-preparations'),
    'settings_path' => storage_path(env('APP_ENV') === 'testing' ? 'framework/testing/benchforge-system/settings.json' : 'app/private/benchforge-system/settings.json'),
    'preparation_timeout_seconds' => 900,
    'node_binary' => env('BENCHFORGE_NODE_BINARY', 'node'),
    'shell_binary' => env('BENCHFORGE_SHELL_BINARY', 'zsh'),
    'command_path' => env('BENCHFORGE_COMMAND_PATH', env('PATH', '/usr/local/bin:/usr/bin:/bin')),
    'home_path' => $homePath,
    'opencode_db_path' => env('BENCHFORGE_OPENCODE_DB_PATH', rtrim(env('XDG_DATA_HOME', rtrim($homePath, '/').'/.local/share'), '/').'/opencode/opencode.db'),
    'opencode_db_path_derived' => env('BENCHFORGE_OPENCODE_DB_PATH') === null && env('XDG_DATA_HOME') === null,
];
