<?php

use App\Models\BenchmarkRun;
use App\Models\ModelRun;
use App\Models\ModelRunAttempt;
use App\Services\OpenCodeUsageTracker;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

it('records root and subagent usage without including another recovery fork', function () {
    [$root, $runPath, $workspace, $database] = usageFixture();
    $run = BenchmarkRun::factory()->create(['slug' => 'usage-run', 'source_path' => $runPath]);
    $modelRun = ModelRun::factory()->for($run)->create(['slug' => 'model-a']);
    File::put($runPath.'/_sessions/model-a.session', 'ses_initial');
    insertUsageSession($database, 'ses_initial', null, $workspace, 'Original', 0.1, 100, 40, 10, 200, 0);
    insertUsageSession($database, 'ses_child', 'ses_initial', $workspace, 'Explore subagent', 0.02, 30, 12, 2, 50, 0);
    insertUsageSession($database, 'ses_fork', 'ses_initial', $workspace, 'benchforge:usage-run:model-a:attempt-1', 0.3, 999, 999, 99, 999, 0);
    insertUsageSession($database, 'ses_unrelated', null, $workspace, 'Unrelated', 0.5, 999, 999, 99, 999, 0);

    try {
        app(OpenCodeUsageTracker::class)->syncInitial($modelRun);

        expect($modelRun->fresh()->usage)->toMatchArray([
            'inputTokens' => 130,
            'outputTokens' => 52,
            'reasoningTokens' => 12,
            'cacheReadTokens' => 250,
            'cacheWriteTokens' => 0,
            'costUsd' => '0.1200000000',
            'source' => 'opencode_session',
            'sessionCount' => 2,
            'sessionId' => 'ses_initial',
        ]);
    } finally {
        $database->close();
        File::deleteDirectory($root);
    }
});

it('keeps continuation usage separate from its source session and later forks', function () {
    [$root, $runPath, $workspace, $database] = usageFixture();
    $run = BenchmarkRun::factory()->create(['slug' => 'usage-run', 'source_path' => $runPath]);
    $modelRun = ModelRun::factory()->for($run)->create(['slug' => 'model-a']);
    File::put($runPath.'/_sessions/model-a.session', 'ses_initial');
    $attempt = ModelRunAttempt::factory()->for($modelRun)->create(['session_id' => 'ses_attempt']);
    insertUsageSession($database, 'ses_initial', null, $workspace, 'Original', 0.1, 100, 40, 10, 200, 0);
    insertUsageSession($database, 'ses_attempt', 'ses_initial', $workspace, 'benchforge:usage-run:model-a:attempt-1', 0.04, 25, 12, 3, 80, 4);
    insertUsageSession($database, 'ses_subagent', 'ses_attempt', $workspace, 'Subagent', 0.005, 5, 2, 0, 20, 0);
    insertUsageSession($database, 'ses_later', 'ses_attempt', $workspace, 'benchforge:usage-run:model-a:attempt-2', 0.2, 200, 100, 0, 200, 0);

    try {
        $tracker = app(OpenCodeUsageTracker::class);
        $tracker->syncInitial($modelRun);
        $tracker->syncAttempt($attempt);

        expect($modelRun->fresh()->usage['costUsd'])->toBe('0.1000000000')
            ->and($attempt->fresh()->usage)->toMatchArray([
                'inputTokens' => 30,
                'outputTokens' => 14,
                'reasoningTokens' => 3,
                'cacheReadTokens' => 100,
                'cacheWriteTokens' => 4,
                'costUsd' => '0.0450000000',
                'source' => 'opencode_session',
                'sessionCount' => 2,
                'sessionId' => 'ses_attempt',
            ]);
    } finally {
        $database->close();
        File::deleteDirectory($root);
    }
});

it('records stopped continuation usage from its own event log without assigning a session to the attempt', function () {
    [$root, $runPath, $workspace, $database] = usageFixture();
    $startedAt = now()->startOfSecond()->subMinute();
    $finishedAt = $startedAt->copy()->addMinute();
    $attemptPath = $runPath.'/_attempts/model-a/attempt-1';
    File::ensureDirectoryExists($attemptPath);
    File::put($attemptPath.'/opencode.events.jsonl', json_encode([
        'type' => 'step_start',
        'sessionID' => 'ses_stopped',
    ], JSON_THROW_ON_ERROR)."\n");
    $run = BenchmarkRun::factory()->create(['slug' => 'usage-run', 'source_path' => $runPath]);
    $modelRun = ModelRun::factory()->for($run)->create(['slug' => 'model-a']);
    $attempt = ModelRunAttempt::factory()->for($modelRun)->create([
        'status' => 'stopped',
        'session_id' => null,
        'result' => null,
        'attempt_path' => $attemptPath,
        'started_at' => $startedAt,
        'finished_at' => $finishedAt,
    ]);
    insertUsageSession($database, 'ses_stopped', null, $workspace, 'OpenCode fork without requested title', 0.04, 25, 12, 3, 80, 4, $startedAt->copy()->addSeconds(10)->getTimestampMs());
    insertUsageSession($database, 'ses_subagent', 'ses_stopped', $workspace, 'Explore subagent', 0.005, 5, 2, 0, 20, 0);

    try {
        app(OpenCodeUsageTracker::class)->syncAttempt($attempt);

        expect($attempt->fresh()->usage)->toMatchArray([
            'inputTokens' => 30,
            'outputTokens' => 14,
            'reasoningTokens' => 3,
            'cacheReadTokens' => 100,
            'cacheWriteTokens' => 4,
            'costUsd' => '0.0450000000',
            'source' => 'opencode_session',
            'sessionCount' => 2,
            'sessionId' => 'ses_stopped',
        ])->and($attempt->fresh()->session_id)->toBeNull();
    } finally {
        $database->close();
        File::deleteDirectory($root);
    }
});

it('records failed continuation usage from its own session id file', function () {
    [$root, $runPath, $workspace, $database] = usageFixture();
    $startedAt = now()->startOfSecond()->subMinute();
    $finishedAt = $startedAt->copy()->addMinute();
    $attemptPath = $runPath.'/_attempts/model-a/attempt-1';
    File::ensureDirectoryExists($attemptPath);
    File::put($attemptPath.'/session-id.txt', "ses_failed\n");
    $run = BenchmarkRun::factory()->create(['slug' => 'usage-run', 'source_path' => $runPath]);
    $modelRun = ModelRun::factory()->for($run)->create(['slug' => 'model-a']);
    $attempt = ModelRunAttempt::factory()->for($modelRun)->create([
        'status' => 'failed',
        'session_id' => null,
        'result' => null,
        'attempt_path' => $attemptPath,
        'started_at' => $startedAt,
        'finished_at' => $finishedAt,
    ]);
    insertUsageSession($database, 'ses_failed', null, $workspace, 'OpenCode fork without requested title', 0.03, 20, 8, 2, 30, 0, $startedAt->copy()->addSeconds(10)->getTimestampMs());

    try {
        app(OpenCodeUsageTracker::class)->syncAttempt($attempt);

        expect($attempt->fresh()->usage)->toMatchArray([
            'inputTokens' => 20,
            'outputTokens' => 8,
            'costUsd' => '0.0300000000',
            'sessionId' => 'ses_failed',
        ])->and($attempt->fresh()->session_id)->toBeNull();
    } finally {
        $database->close();
        File::deleteDirectory($root);
    }
});

it('rejects an attempt-local session from a different workspace', function () {
    [$root, $runPath, $workspace, $database] = usageFixture();
    $startedAt = now()->startOfSecond()->subMinute();
    $finishedAt = $startedAt->copy()->addMinute();
    $attemptPath = $runPath.'/_attempts/model-a/attempt-1';
    File::ensureDirectoryExists($attemptPath);
    File::put($attemptPath.'/opencode.events.jsonl', json_encode(['sessionID' => 'ses_other'], JSON_THROW_ON_ERROR)."\n");
    $run = BenchmarkRun::factory()->create(['slug' => 'usage-run', 'source_path' => $runPath]);
    $modelRun = ModelRun::factory()->for($run)->create(['slug' => 'model-a']);
    $attempt = ModelRunAttempt::factory()->for($modelRun)->create([
        'status' => 'stopped',
        'session_id' => null,
        'result' => null,
        'attempt_path' => $attemptPath,
        'started_at' => $startedAt,
        'finished_at' => $finishedAt,
    ]);
    insertUsageSession($database, 'ses_other', null, $root.'/elsewhere', 'Untitled fork', 0.5, 100, 50, 0, 0, 0, $startedAt->copy()->addSeconds(10)->getTimestampMs());

    try {
        app(OpenCodeUsageTracker::class)->syncAttempt($attempt);

        expect($attempt->fresh()->usage)->toBeNull();
    } finally {
        $database->close();
        File::deleteDirectory($root);
    }
});

it('records an attempt-local session even when OpenCode ignores its requested title', function () {
    [$root, $runPath, $workspace, $database] = usageFixture();
    $startedAt = now()->startOfSecond()->subMinute();
    $finishedAt = $startedAt->copy()->addMinute();
    $attemptPath = $runPath.'/_attempts/model-a/attempt-1';
    File::ensureDirectoryExists($attemptPath);
    File::put($attemptPath.'/opencode.events.jsonl', json_encode(['sessionID' => 'ses_other_title'], JSON_THROW_ON_ERROR)."\n");
    $run = BenchmarkRun::factory()->create(['slug' => 'usage-run', 'source_path' => $runPath]);
    $modelRun = ModelRun::factory()->for($run)->create(['slug' => 'model-a']);
    $attempt = ModelRunAttempt::factory()->for($modelRun)->create([
        'status' => 'stopped',
        'session_id' => null,
        'result' => null,
        'attempt_path' => $attemptPath,
        'started_at' => $startedAt,
        'finished_at' => $finishedAt,
    ]);
    insertUsageSession($database, 'ses_other_title', null, $workspace, 'Untitled fork', 0.5, 100, 50, 0, 0, 0, $startedAt->copy()->addSeconds(10)->getTimestampMs());

    try {
        app(OpenCodeUsageTracker::class)->syncAttempt($attempt);

        expect($attempt->fresh()->usage)->toMatchArray([
            'inputTokens' => 100,
            'outputTokens' => 50,
            'costUsd' => '0.5000000000',
            'sessionId' => 'ses_other_title',
        ]);
    } finally {
        $database->close();
        File::deleteDirectory($root);
    }
});

it('rejects an attempt-local session created outside the attempt window', function () {
    [$root, $runPath, $workspace, $database] = usageFixture();
    $startedAt = now()->startOfSecond()->subMinute();
    $finishedAt = $startedAt->copy()->addMinute();
    $attemptPath = $runPath.'/_attempts/model-a/attempt-1';
    File::ensureDirectoryExists($attemptPath);
    File::put($attemptPath.'/opencode.events.jsonl', json_encode(['sessionID' => 'ses_older'], JSON_THROW_ON_ERROR)."\n");
    $run = BenchmarkRun::factory()->create(['slug' => 'usage-run', 'source_path' => $runPath]);
    $modelRun = ModelRun::factory()->for($run)->create(['slug' => 'model-a']);
    $attempt = ModelRunAttempt::factory()->for($modelRun)->create([
        'status' => 'stopped',
        'session_id' => null,
        'result' => null,
        'attempt_path' => $attemptPath,
        'started_at' => $startedAt,
        'finished_at' => $finishedAt,
    ]);
    insertUsageSession($database, 'ses_older', null, $workspace, 'Untitled fork', 0.5, 100, 50, 0, 0, 0, $startedAt->copy()->subSeconds(5)->getTimestampMs());

    try {
        app(OpenCodeUsageTracker::class)->syncAttempt($attempt);

        expect($attempt->fresh()->usage)->toBeNull();
    } finally {
        $database->close();
        File::deleteDirectory($root);
    }
});

it('rejects an attempt-local session file outside the exact attempt path', function () {
    [$root, $runPath, $workspace, $database] = usageFixture();
    $startedAt = now()->startOfSecond()->subMinute();
    $finishedAt = $startedAt->copy()->addMinute();
    $otherPath = $runPath.'/_attempts/model-a/attempt-2';
    File::ensureDirectoryExists($otherPath);
    File::put($otherPath.'/session-id.txt', 'ses_other_attempt');
    $run = BenchmarkRun::factory()->create(['slug' => 'usage-run', 'source_path' => $runPath]);
    $modelRun = ModelRun::factory()->for($run)->create(['slug' => 'model-a']);
    $attempt = ModelRunAttempt::factory()->for($modelRun)->create([
        'status' => 'stopped',
        'session_id' => null,
        'result' => null,
        'attempt_path' => $otherPath,
        'started_at' => $startedAt,
        'finished_at' => $finishedAt,
    ]);
    insertUsageSession($database, 'ses_other_attempt', null, $workspace, 'Untitled fork', 0.5, 100, 50, 0, 0, 0, $startedAt->copy()->addSeconds(10)->getTimestampMs());

    try {
        app(OpenCodeUsageTracker::class)->syncAttempt($attempt);

        expect($attempt->fresh()->usage)->toBeNull();
    } finally {
        $database->close();
        File::deleteDirectory($root);
    }
});

it('ignores a saved session from a different workspace', function () {
    [$root, $runPath, $workspace, $database] = usageFixture();
    $run = BenchmarkRun::factory()->create(['slug' => 'usage-run', 'source_path' => $runPath]);
    $modelRun = ModelRun::factory()->for($run)->create(['slug' => 'model-a']);
    File::put($runPath.'/_sessions/model-a.session', 'ses_other');
    insertUsageSession($database, 'ses_other', null, $root.'/elsewhere', 'Other project', 0.5, 100, 50, 0, 0, 0);

    try {
        app(OpenCodeUsageTracker::class)->syncInitial($modelRun);

        expect($modelRun->fresh()->usage)->toBeNull();
    } finally {
        $database->close();
        File::deleteDirectory($root);
    }
});

it('records zero model usage for validation only attempts', function () {
    $modelRun = ModelRun::factory()->create();
    $attempt = ModelRunAttempt::factory()->for($modelRun)->create(['mode' => 'validate', 'session_id' => null]);

    app(OpenCodeUsageTracker::class)->syncAttempt($attempt);

    expect($attempt->fresh()->usage)->toMatchArray([
        'inputTokens' => 0,
        'outputTokens' => 0,
        'costUsd' => '0.0000000000',
        'source' => 'validation_only',
    ]);
});

it('backfills recorded usage when archive synchronization imports a run', function () {
    [$root, $runPath, $workspace, $database] = usageFixture();
    File::put($runPath.'/_sessions/model-a.session', 'ses_initial');
    File::put($runPath.'/_meta.txt', "test=usage-run\ndate=2026-09-22 01:00:00\nroutes=/\n");
    File::put($runPath.'/_summary.tsv', "klasor|model|opencode|lint|build|export|routes|runtime|sure_sn\nmodel-a|openrouter/example/model-a|0|0|0|0|0|0|10\n");
    insertUsageSession($database, 'ses_initial', null, $workspace, 'Original', 0.07, 90, 22, 4, 150, 0);

    try {
        $this->artisan('benchforge:sync')->assertSuccessful();

        expect(ModelRun::query()->where('slug', 'model-a')->sole()->usage)->toMatchArray([
            'inputTokens' => 90,
            'outputTokens' => 22,
            'costUsd' => '0.0700000000',
            'source' => 'opencode_session',
        ]);
    } finally {
        $database->close();
        File::deleteDirectory($root);
    }
});

/** @return array{string, string, string, SQLite3} */
function usageFixture(): array
{
    $root = storage_path('framework/testing/usage-'.Str::uuid());
    $runPath = $root.'/runs/usage-run';
    $workspace = $runPath.'/model-a';
    File::ensureDirectoryExists($workspace);
    File::ensureDirectoryExists($runPath.'/_sessions');
    config()->set('benchforge.runs_path', $root.'/runs');
    config()->set('benchforge.opencode_db_path', $root.'/opencode.db');
    $database = new SQLite3($root.'/opencode.db');
    $database->exec('CREATE TABLE session (id TEXT PRIMARY KEY, parent_id TEXT, directory TEXT, title TEXT, cost REAL, tokens_input INTEGER, tokens_output INTEGER, tokens_reasoning INTEGER, tokens_cache_read INTEGER, tokens_cache_write INTEGER, time_created INTEGER)');

    return [$root, $runPath, $workspace, $database];
}

function insertUsageSession(SQLite3 $database, string $id, ?string $parentId, string $directory, string $title, float $cost, int $input, int $output, int $reasoning, int $cacheRead, int $cacheWrite, ?int $timeCreated = null): void
{
    $query = $database->prepare('INSERT INTO session VALUES (:id, :parent_id, :directory, :title, :cost, :input, :output, :reasoning, :cache_read, :cache_write, :time_created)');
    $query->bindValue(':id', $id, SQLITE3_TEXT);
    $query->bindValue(':parent_id', $parentId, SQLITE3_TEXT);
    $query->bindValue(':directory', $directory, SQLITE3_TEXT);
    $query->bindValue(':title', $title, SQLITE3_TEXT);
    $query->bindValue(':cost', $cost, SQLITE3_FLOAT);
    $query->bindValue(':input', $input, SQLITE3_INTEGER);
    $query->bindValue(':output', $output, SQLITE3_INTEGER);
    $query->bindValue(':reasoning', $reasoning, SQLITE3_INTEGER);
    $query->bindValue(':cache_read', $cacheRead, SQLITE3_INTEGER);
    $query->bindValue(':cache_write', $cacheWrite, SQLITE3_INTEGER);
    $query->bindValue(':time_created', $timeCreated ?? 0, SQLITE3_INTEGER);
    $query->execute();
}
