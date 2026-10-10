<?php

namespace App\Services;

use App\Models\BenchmarkRun;
use App\Models\ModelRun;
use App\Models\ModelRunAttempt;

class ModelRunBoundaryReport
{
    public function __construct(private BenchmarkModelFiles $files) {}

    /** @return array<string, mixed> */
    public function forModel(BenchmarkRun $run, ModelRun $model): array
    {
        return $this->read(
            $run,
            $model,
            '_boundary/'.$model->slug.'.json',
            '_policies/'.$model->slug.'/metadata.json',
            ['_logs/'.$model->slug.'.opencode.events.jsonl', '_logs/'.$model->slug.'.opencode.log'],
            '_logs/'.$model->slug.'.opencode.stderr.log',
        );
    }

    /** @return array<string, mixed> */
    public function forAttempt(BenchmarkRun $run, ModelRun $model, ModelRunAttempt $attempt): array
    {
        if ($attempt->model_run_id !== $model->id || $attempt->attempt_number < 1) {
            return $this->emptyReport();
        }

        $prefix = '_attempts/'.$model->slug.'/attempt-'.$attempt->attempt_number.'/';

        return $this->read(
            $run,
            $model,
            $prefix.'boundary-report.json',
            $prefix.'model-policy/metadata.json',
            [$prefix.'opencode.events.jsonl'],
            $prefix.'opencode.stderr.log',
        );
    }

    /** @param list<string> $eventPaths
     * @return array<string, mixed>
     */
    private function read(
        BenchmarkRun $run,
        ModelRun $model,
        string $reportPath,
        string $policyPath,
        array $eventPaths,
        string $stderrPath,
    ): array {
        $content = $this->files->read($run, $model, $reportPath, 65536, false);
        $report = json_decode($content, true);
        $runPath = $this->files->runPath($run, $model);

        if (is_array($report) && ($report['version'] ?? null) === 1) {
            return $this->normalize($report, $runPath);
        }

        $result = $this->emptyReport();
        $policy = json_decode($this->files->read($run, $model, $policyPath, 8192, false), true);
        $result['policyVersion'] = is_array($policy) && ($policy['policyVersion'] ?? null) === ModelRunDiagnosticContext::POLICY_VERSION
            ? ModelRunDiagnosticContext::POLICY_VERSION
            : null;
        $events = '';

        foreach ($eventPaths as $eventPath) {
            $events = $this->files->read($run, $model, $eventPath, 524288);

            if ($events !== '') {
                break;
            }
        }

        $stderr = $this->files->read($run, $model, $stderrPath, 32768);

        if ($events === '' && $stderr === '') {
            return $result;
        }

        $result['source'] = 'legacy_log';
        $result['scanLimited'] = true;
        $run->loadMissing('modelRuns');
        $otherSlugs = $run->modelRuns->reject(fn (ModelRun $other): bool => $other->id === $model->id)->pluck('slug')->all();

        foreach (preg_split('/\R/', $events) ?: [] as $line) {
            $event = json_decode($line, true);
            $event = is_array($event) ? $event : [];
            $part = is_array($event['part'] ?? null) ? $event['part'] : [];
            $state = is_array($part['state'] ?? null) ? $part['state'] : [];
            $tool = is_string($part['tool'] ?? null) ? $part['tool'] : '';

            if (! in_array($tool, ['bash', 'read', 'glob', 'grep'], true)) {
                continue;
            }

            $input = is_array($state['input'] ?? null) ? $state['input'] : [];
            $request = $input['command'] ?? $input['filePath'] ?? $input['path'] ?? '';

            if (! is_string($request) || ! $this->isOutsideRequest($request, $runPath, $model->slug, $tool)) {
                continue;
            }

            $error = is_string($state['error'] ?? null) ? $state['error'] : '';

            if (($state['status'] ?? null) === 'error' && preg_match('/permission|not allowed|denied/i', $error) === 1) {
                $result['blockedCount']++;
                $result['entries'][] = $this->entry('blocked', $tool, '[çalışma alanı dışı erişim]', 'Kapsam dışı erişim engellendi.');
            }

            $output = $state['output'] ?? '';

            if (($state['status'] ?? null) !== 'completed' || ! is_string($output)) {
                continue;
            }

            foreach ($otherSlugs as $otherSlug) {
                if (str_contains($output, '_attempts/'.$otherSlug.'/')
                    || str_contains($output, '_logs/'.$otherSlug.'.')
                    || ($runPath !== null && str_contains($output, $runPath.'/'.$otherSlug.'/'))) {
                    $result['observedCrossModelReadCount']++;
                    $result['entries'][] = $this->entry(
                        'observed_cross_model_read',
                        $tool,
                        '[diğer model kaydı]',
                        'Tamamlanan araç çıktısında başka modele ait kayıt görüldü; karşılaştırma bağımsızlığını inceleyin.',
                    );
                    break;
                }
            }
        }

        $plainStderr = $this->files->sanitize($stderr !== '' ? $stderr : $events, $runPath);
        preg_match_all('/external_directory\s*\(([^\r\n]+)\)[^\r\n]*(?:auto-rejecting|denied|reject)/i', $plainStderr, $matches);
        $stderrBlockedCount = count($matches[1] ?? []);
        $missingEntries = max(0, $stderrBlockedCount - $result['blockedCount']);

        foreach (array_slice($matches[1] ?? [], 0, $missingEntries) as $path) {
            $result['entries'][] = $this->entry('blocked', 'external_directory', mb_substr($path, 0, 240), 'Kapsam dışı erişim engellendi.');
        }

        $result['blockedCount'] = max($result['blockedCount'], $stderrBlockedCount);
        $result['entries'] = array_slice($result['entries'], -20);

        return $result;
    }

    private function isOutsideRequest(string $request, ?string $runPath, string $slug, string $tool): bool
    {
        if ($runPath === null) {
            return false;
        }

        $workspace = $runPath.'/'.$slug;

        if ($tool === 'bash') {
            preg_match_all('~(?:^|[\s"\'=])((?:/|\.{1,2}/|\~/)[^\s"\';&|<>]+)~', $request, $matches);
            $paths = $matches[1] ?? [];
        } else {
            $paths = [$request];
        }

        foreach ($paths as $path) {
            if (str_starts_with($path, '~/')) {
                return true;
            }

            $absolute = str_starts_with($path, '/') ? $path : $workspace.'/'.$path;
            $segments = [];

            foreach (explode('/', $absolute) as $segment) {
                if ($segment === '' || $segment === '.') {
                    continue;
                }

                if ($segment === '..') {
                    array_pop($segments);
                } else {
                    $segments[] = $segment;
                }
            }

            $normalized = '/'.implode('/', $segments);

            if ($normalized !== $workspace && ! str_starts_with($normalized, $workspace.'/')) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function normalize(array $report, ?string $runPath): array
    {
        $result = $this->emptyReport();
        $result['source'] = 'report';
        $result['policyVersion'] = ($report['policyVersion'] ?? null) === ModelRunDiagnosticContext::POLICY_VERSION
            ? ModelRunDiagnosticContext::POLICY_VERSION
            : null;
        $result['blockedCount'] = is_int($report['blockedCount'] ?? null) ? max(0, $report['blockedCount']) : 0;
        $result['observedCrossModelReadCount'] = is_int($report['observedCrossModelReadCount'] ?? null)
            ? max(0, $report['observedCrossModelReadCount'])
            : 0;
        $result['stoppedForBoundary'] = ($report['stoppedForBoundary'] ?? false) === true;

        foreach (array_slice(is_array($report['entries'] ?? null) ? $report['entries'] : [], -20) as $entry) {
            if (! is_array($entry) || ! in_array($entry['kind'] ?? null, ['blocked', 'observed_cross_model_read'], true)) {
                continue;
            }

            $result['entries'][] = $this->entry(
                $entry['kind'],
                in_array($entry['tool'] ?? null, ['bash', 'read', 'write', 'edit', 'glob', 'grep', 'external_directory'], true) ? $entry['tool'] : 'araç',
                is_string($entry['path'] ?? null) ? mb_substr($this->files->sanitize($entry['path'], $runPath), 0, 240) : '[kayıt yok]',
                $entry['kind'] === 'blocked'
                    ? 'Kapsam dışı erişim engellendi.'
                    : 'Tamamlanan araç çıktısında başka modele ait kayıt görüldü; karşılaştırma bağımsızlığını inceleyin.',
            );
        }

        return $result;
    }

    /** @return array{kind: string, tool: string, path: string, reason: string} */
    private function entry(string $kind, string $tool, string $path, string $reason): array
    {
        return compact('kind', 'tool', 'path', 'reason');
    }

    /** @return array<string, mixed> */
    private function emptyReport(): array
    {
        return [
            'version' => 1,
            'policyVersion' => null,
            'blockedCount' => 0,
            'observedCrossModelReadCount' => 0,
            'stoppedForBoundary' => false,
            'entries' => [],
            'source' => 'unavailable',
            'scanLimited' => false,
        ];
    }
}
