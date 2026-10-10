<?php

namespace App\Services;

use App\Models\BenchmarkRun;
use App\Models\ModelRun;
use App\Models\ModelRunAttempt;
use Illuminate\Support\Facades\File;
use SQLite3;
use Throwable;

class OpenCodeUsageTracker
{
    private const SESSION_PATTERN = '/^ses_[A-Za-z0-9_-]+$/';

    private const REQUIRED_COLUMNS = [
        'id', 'parent_id', 'directory', 'title', 'cost',
        'tokens_input', 'tokens_output', 'tokens_reasoning',
        'tokens_cache_read', 'tokens_cache_write',
    ];

    private ?SQLite3 $database = null;

    private bool $databaseChecked = false;

    public function syncForRun(BenchmarkRun $benchmarkRun): void
    {
        $benchmarkRun->loadMissing('modelRuns.modelRunAttempts');

        foreach ($benchmarkRun->modelRuns as $modelRun) {
            $this->syncInitial($modelRun);

            foreach ($modelRun->modelRunAttempts as $attempt) {
                $this->syncAttempt($attempt);
            }
        }
    }

    public function syncInitial(ModelRun $modelRun): void
    {
        $workspace = $this->workspace($modelRun);

        if ($workspace === null) {
            return;
        }

        $sessionId = $this->initialSessionId($modelRun, $workspace);

        if ($sessionId === null) {
            return;
        }

        $otherAttempts = $modelRun->modelRunAttempts()
            ->whereNotNull('session_id')
            ->pluck('session_id')
            ->all();
        $usage = $this->sessionUsage($sessionId, $workspace, $modelRun, $otherAttempts);

        if ($usage !== null) {
            $this->saveUsage($modelRun, $usage);
        }
    }

    public function syncAttempt(ModelRunAttempt $attempt): void
    {
        if ($attempt->mode === 'validate') {
            $this->saveUsage($attempt, [
                'inputTokens' => 0,
                'outputTokens' => 0,
                'reasoningTokens' => 0,
                'cacheReadTokens' => 0,
                'cacheWriteTokens' => 0,
                'costUsd' => '0.0000000000',
                'source' => 'validation_only',
                'sessionCount' => 0,
                'sessionId' => null,
            ]);

            return;
        }

        $modelRun = $attempt->modelRun;
        $workspace = $this->workspace($modelRun);

        if ($workspace === null) {
            return;
        }

        $sessionId = $attempt->session_id;
        $isAttemptLocalSession = ! is_string($sessionId) || preg_match(self::SESSION_PATTERN, $sessionId) !== 1;

        if ($isAttemptLocalSession) {
            $sessionId = $this->attemptLocalSessionId($attempt, $workspace);
        }

        if ($sessionId === null) {
            return;
        }

        if ($isAttemptLocalSession && ($sessionId === $attempt->source_session_id
            || ! $this->sessionCreatedDuringAttempt($sessionId, $workspace, $attempt))) {
            return;
        }

        $otherAttempts = $modelRun->modelRunAttempts()
            ->whereNotNull('session_id')
            ->whereKeyNot($attempt->id)
            ->pluck('session_id')
            ->all();
        $usage = $this->sessionUsage($sessionId, $workspace, $modelRun, $otherAttempts);

        if ($usage !== null) {
            $this->saveUsage($attempt, $usage);
        }
    }

    /**
     * @param  list<string>  $excludedSessionIds
     * @return array<string, int|string|null>|null
     */
    private function sessionUsage(string $sessionId, string $workspace, ModelRun $modelRun, array $excludedSessionIds): ?array
    {
        $database = $this->database();

        if ($database === null) {
            return null;
        }

        try {
            $query = $database->prepare('SELECT id, parent_id, directory, title, cost, tokens_input, tokens_output, tokens_reasoning, tokens_cache_read, tokens_cache_write FROM session WHERE directory = :directory');

            if ($query === false) {
                return null;
            }

            $query->bindValue(':directory', $workspace, SQLITE3_TEXT);
            $result = $query->execute();

            if ($result === false) {
                return null;
            }

            $sessions = [];
            $children = [];

            while ($session = $result->fetchArray(SQLITE3_ASSOC)) {
                $id = $session['id'];
                $sessions[$id] = $session;

                if (is_string($session['parent_id']) && $session['parent_id'] !== '') {
                    $children[$session['parent_id']][] = $id;
                }
            }

            $result->finalize();

            if (! isset($sessions[$sessionId])) {
                return null;
            }

            $excluded = array_fill_keys($excludedSessionIds, true);
            $attemptTitlePrefix = "benchforge:{$modelRun->benchmarkRun->slug}:{$modelRun->slug}:attempt-";
            $pending = [$sessionId];
            $seen = [];
            $totals = [
                'inputTokens' => 0,
                'outputTokens' => 0,
                'reasoningTokens' => 0,
                'cacheReadTokens' => 0,
                'cacheWriteTokens' => 0,
                'costUsd' => 0.0,
                'sessionCount' => 0,
            ];

            while ($pending !== []) {
                $id = array_pop($pending);

                if (isset($seen[$id]) || ! isset($sessions[$id])) {
                    continue;
                }

                $seen[$id] = true;
                $session = $sessions[$id];

                if ($id !== $sessionId
                    && (isset($excluded[$id]) || str_starts_with((string) $session['title'], $attemptTitlePrefix))) {
                    continue;
                }

                foreach ([
                    'tokens_input' => 'inputTokens',
                    'tokens_output' => 'outputTokens',
                    'tokens_reasoning' => 'reasoningTokens',
                    'tokens_cache_read' => 'cacheReadTokens',
                    'tokens_cache_write' => 'cacheWriteTokens',
                ] as $column => $field) {
                    if (! is_numeric($session[$column]) || (int) $session[$column] < 0) {
                        return null;
                    }

                    $totals[$field] += (int) $session[$column];
                }

                if (! is_numeric($session['cost']) || ! is_finite((float) $session['cost']) || (float) $session['cost'] < 0) {
                    return null;
                }

                $totals['costUsd'] += (float) $session['cost'];
                $totals['sessionCount']++;
                array_push($pending, ...($children[$id] ?? []));
            }

            return [
                'inputTokens' => $totals['inputTokens'],
                'outputTokens' => $totals['outputTokens'],
                'reasoningTokens' => $totals['reasoningTokens'],
                'cacheReadTokens' => $totals['cacheReadTokens'],
                'cacheWriteTokens' => $totals['cacheWriteTokens'],
                'costUsd' => number_format($totals['costUsd'], 10, '.', ''),
                'source' => 'opencode_session',
                'sessionCount' => $totals['sessionCount'],
                'sessionId' => $sessionId,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private function workspace(ModelRun $modelRun): ?string
    {
        $runsPath = realpath((string) config('benchforge.runs_path'));
        $runPath = realpath($modelRun->benchmarkRun->source_path);

        if ($runsPath === false || $runPath === false || dirname($runPath) !== $runsPath
            || basename($runPath) !== $modelRun->benchmarkRun->slug) {
            return null;
        }

        $workspace = realpath($runPath.'/'.$modelRun->slug);

        return $workspace !== false && dirname($workspace) === $runPath && basename($workspace) === $modelRun->slug
            ? $workspace
            : null;
    }

    private function initialSessionId(ModelRun $modelRun, string $workspace): ?string
    {
        $sessionDirectory = realpath(dirname($workspace).'/_sessions');
        $sessionFile = $sessionDirectory === false ? false : realpath($sessionDirectory.'/'.$modelRun->slug.'.session');

        if ($sessionFile === false || dirname($sessionFile) !== $sessionDirectory || ! File::isFile($sessionFile)
            || File::size($sessionFile) > 100) {
            return null;
        }

        $sessionId = trim(File::get($sessionFile));

        return preg_match(self::SESSION_PATTERN, $sessionId) === 1 ? $sessionId : null;
    }

    private function attemptLocalSessionId(ModelRunAttempt $attempt, string $workspace): ?string
    {
        $expectedPath = dirname($workspace).'/_attempts/'.$attempt->modelRun->slug.'/attempt-'.$attempt->attempt_number;

        if ($attempt->attempt_number < 1 || $attempt->attempt_path !== $expectedPath
            || realpath($attempt->attempt_path) !== $expectedPath) {
            return null;
        }

        $sessionFile = $expectedPath.'/session-id.txt';

        if (realpath($sessionFile) === $sessionFile && File::isFile($sessionFile) && File::size($sessionFile) <= 100) {
            $sessionId = trim(File::get($sessionFile));

            if (preg_match(self::SESSION_PATTERN, $sessionId) === 1) {
                return $sessionId;
            }
        }

        $eventFile = $expectedPath.'/opencode.events.jsonl';

        if (realpath($eventFile) !== $eventFile || ! File::isFile($eventFile)) {
            return null;
        }

        $prefix = @file_get_contents($eventFile, false, null, 0, 262144);

        if (! is_string($prefix)) {
            return null;
        }

        foreach (explode("\n", $prefix) as $line) {
            try {
                $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }

            $sessionId = is_array($event) ? ($event['sessionID'] ?? null) : null;

            if (is_string($sessionId) && preg_match(self::SESSION_PATTERN, $sessionId) === 1) {
                return $sessionId;
            }
        }

        return null;
    }

    private function sessionCreatedDuringAttempt(string $sessionId, string $workspace, ModelRunAttempt $attempt): bool
    {
        if ($attempt->started_at === null || $attempt->finished_at === null
            || $attempt->finished_at->lessThan($attempt->started_at)) {
            return false;
        }

        $database = $this->database();

        if ($database === null) {
            return false;
        }

        try {
            $query = $database->prepare('SELECT directory, time_created FROM session WHERE id = :id LIMIT 1');

            if ($query === false) {
                return false;
            }

            $query->bindValue(':id', $sessionId, SQLITE3_TEXT);
            $result = $query->execute();

            if ($result === false) {
                return false;
            }

            $session = $result->fetchArray(SQLITE3_ASSOC);
            $result->finalize();
            $createdAt = is_array($session) ? filter_var($session['time_created'], FILTER_VALIDATE_INT) : false;

            return is_array($session)
                && $session['directory'] === $workspace
                && $createdAt !== false
                && $createdAt >= $attempt->started_at->getTimestamp() * 1000
                && $createdAt < ($attempt->finished_at->getTimestamp() + 1) * 1000;
        } catch (Throwable) {
            return false;
        }
    }

    private function database(): ?SQLite3
    {
        if ($this->databaseChecked) {
            return $this->database;
        }

        $this->databaseChecked = true;
        $path = (string) config('benchforge.opencode_db_path');

        if (! class_exists(SQLite3::class) || ! is_file($path)) {
            return null;
        }

        try {
            $database = new SQLite3($path, SQLITE3_OPEN_READONLY);
            $database->busyTimeout(1000);
            $columns = [];
            $result = $database->query('PRAGMA table_info(session)');

            if ($result === false) {
                $database->close();

                return null;
            }

            while ($column = $result->fetchArray(SQLITE3_ASSOC)) {
                $columns[] = $column['name'];
            }

            $result->finalize();

            if (array_diff(self::REQUIRED_COLUMNS, $columns) !== []) {
                $database->close();

                return null;
            }

            return $this->database = $database;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, int|string|null> $usage */
    private function saveUsage(ModelRun|ModelRunAttempt $subject, array $usage): void
    {
        $current = $subject->usage;

        if (is_array($current)) {
            unset($current['collectedAt']);
        }

        if ($current === $usage) {
            return;
        }

        $subject->update(['usage' => [...$usage, 'collectedAt' => now()->toIso8601String()]]);
    }
}
