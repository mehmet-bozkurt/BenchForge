<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Throwable;

class OpenCodeSessionLocator
{
    public function __construct(private BenchmarkProcessEnvironment $processEnvironment) {}

    /** @var list<array<string, mixed>>|null */
    private ?array $sessions = null;

    public function locate(string $workspace, ?string $preferredSessionId = null, ?string $title = null): ?string
    {
        $resolvedWorkspace = realpath($workspace);

        if ($resolvedWorkspace === false || ! File::isDirectory($resolvedWorkspace)) {
            return null;
        }

        return $this->select(
            $this->sessionList($resolvedWorkspace),
            $resolvedWorkspace,
            $preferredSessionId,
            $title,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $sessions
     */
    public function select(array $sessions, string $workspace, ?string $preferredSessionId = null, ?string $title = null): ?string
    {
        $resolvedWorkspace = realpath($workspace);

        if ($resolvedWorkspace === false) {
            return null;
        }

        $matches = collect($sessions)
            ->filter(function (array $session) use ($resolvedWorkspace): bool {
                $id = $session['id'] ?? null;
                $directory = $session['directory'] ?? null;

                if (! is_string($id) || preg_match('/^ses_[A-Za-z0-9_-]+$/', $id) !== 1 || ! is_string($directory)) {
                    return false;
                }

                $resolvedDirectory = realpath($directory);

                return $resolvedDirectory !== false && $resolvedDirectory === $resolvedWorkspace;
            });

        if (is_string($preferredSessionId) && preg_match('/^ses_[A-Za-z0-9_-]+$/', $preferredSessionId) === 1) {
            $preferred = $matches->firstWhere('id', $preferredSessionId);

            if (is_array($preferred)) {
                return $preferredSessionId;
            }

            if ($title === null || $title === '') {
                return null;
            }
        }

        if (is_string($title) && $title !== '') {
            $titled = $matches->filter(fn (array $session): bool => ($session['title'] ?? null) === $title);

            if ($titled->isEmpty()) {
                return null;
            }

            $matches = $titled;
        }

        $session = $matches
            ->sortByDesc(fn (array $item): int => (int) ($item['updated'] ?? $item['created'] ?? 0))
            ->first();

        return is_array($session) ? (string) $session['id'] : null;
    }

    /** @return list<array<string, mixed>> */
    private function sessionList(string $workingDirectory): array
    {
        if ($this->sessions !== null) {
            return $this->sessions;
        }

        $binary = $this->openCodeBinary();

        if ($binary === null) {
            return $this->sessions = [];
        }

        try {
            $process = new Process(
                [$binary, 'session', 'list', '--format', 'json', '-n', '200', '--pure'],
                $workingDirectory,
                $this->environment(),
            );
            $process->setTimeout(10);
            $process->run();

            if (! $process->isSuccessful()) {
                return $this->sessions = [];
            }

            $decoded = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

            return $this->sessions = is_array($decoded)
                ? array_values(array_filter($decoded, 'is_array'))
                : [];
        } catch (Throwable) {
            return $this->sessions = [];
        }
    }

    private function openCodeBinary(): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) config('benchforge.command_path')) as $directory) {
            $candidate = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'opencode';

            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return array<string, string|false> */
    private function environment(): array
    {
        return $this->processEnvironment->make();
    }
}
