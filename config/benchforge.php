<?php

$rootPath = env('BENCHFORGE_ROOT', dirname(env('BENCHFORGE_RUNS_DIR', base_path('../frontend-model-benchmark/runs'))));
$homePath = env('BENCHFORGE_HOME', base_path());

return [
    'root_path' => $rootPath,
    'runs_path' => env('BENCHFORGE_RUNS_DIR', $rootPath.'/runs'),
    'benchmark_script' => $rootPath.'/tools/benchmark.zsh',
    'resume_script' => $rootPath.'/tools/resume-model.zsh',
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
