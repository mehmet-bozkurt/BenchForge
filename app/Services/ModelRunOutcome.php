<?php

namespace App\Services;

use App\Models\BenchmarkRun;
use App\Models\ModelRun;
use App\Models\ModelRunAttempt;
use App\Models\StageResult;

/**
 * Explains in one sentence what happened to a model in a run, from its stage results and its own logs.
 * Only the model's own log files are read (through BenchmarkModelFiles), and every excerpt is sanitised.
 *
 * Red is kept for the model's own failures. A model step the provider broke, and a check that passed when
 * the same code was validated again, are marked as outside causes; an unscored model's checks are muted.
 */
class ModelRunOutcome
{
    private const EVENT_TAIL_BYTES = 262144;

    private const EVENT_SCAN_BYTES = 2097152;

    /** Same rule as the boundary monitor: a step restarted after this long without finishing is a provider stall. */
    private const STALL_MINIMUM_SECONDS = 120;

    private const STAGE_TITLES = [
        'lint' => 'Lint kontrolü başarısız',
        'build' => 'Production build başarısız',
        'export' => 'Static export üretilmedi',
        'routes' => 'İstenen rota açılmadı',
        'runtime' => 'Sayfa tarayıcıda hata verdi',
    ];

    /** A validation step the engine stopped at its time limit (exit code 124). */
    private const STAGE_TIMEOUT_TITLES = [
        'lint' => 'Lint süre sınırını aştı',
        'build' => 'Build süre sınırını aştı',
    ];

    public function __construct(private BenchmarkModelFiles $files) {}

    /**
     * @return array{tone: 'pass'|'fail'|'provider'|'warning'|'unknown', title: string, detail: ?string, autoContinued: bool, stageMarks: array<string, array{variant: 'provider'|'muted', note: string}>, recovery: array{tone: 'pass'|'fail'|'unknown', label: string, title: string}|null}
     */
    public function describe(BenchmarkRun $run, ModelRun $model): array
    {
        $stages = $model->stageResults->keyBy('stage');
        $modelStep = $stages->get('opencode');
        $code = $modelStep?->exit_code;
        $autoContinued = $this->files->read($run, $model, '_logs/'.$model->slug.'.auto-continue.log', 2048) !== '';
        $events = fn (): string => $this->events($run, $model, $autoContinued);

        $stalls = in_array($code, [StageResult::PROVIDER_ERROR_CODE, StageResult::INTERRUPTED_AFTER_SUBMISSION_CODE, 124], true)
            ? $this->providerStalls($run, $model)
            : null;
        $stallSentence = $stalls === null ? null : $this->stallSentence($stalls);
        $remeasured = $this->passedOnRevalidation($model);

        $outcome = match (true) {
            $code === StageResult::PROVIDER_ERROR_CODE && $stalls !== null => ['provider', 'Sağlayıcı yanıt vermedi · puanlanmaz', $stallSentence],
            $code === StageResult::PROVIDER_ERROR_CODE => ['provider', 'Sağlayıcı veya hesap hatası · puanlanmaz', $this->lastError($events(), $run, $model)],
            $code === StageResult::INTERRUPTED_AFTER_SUBMISSION_CODE => ['warning', 'Bağlantı sonda koptu · teslim puanlandı', $stallSentence ?? $this->lastError($events(), $run, $model)],
            $code === StageResult::NO_SUBMISSION_CODE => $this->noSubmission($events()),
            $code === 124 && ($stalls['mostlyStalled'] ?? false) => ['warning', 'Sağlayıcı takıldı, süre doldu', $stallSentence.' O ana kadar yazılanlar doğrulandı.'],
            $code === 124 => ['fail', 'Süre sınırı doldu', $stallSentence !== null
                ? 'Model adımı ayrılan sürede bitmedi. '.$stallSentence
                : 'Model adımı ayrılan sürede bitmedi; o ana kadar yazılanlar doğrulandı.'],
            $code === 65 => ['fail', 'Model başlatılamadı', 'Çalışma sınırı hazırlanamadığı için model çağrılmadı.'],
            $code !== null && $code !== 0 && $modelStep?->status === 'fail' => ['fail', "Model adımı hata verdi (kod {$code})", $this->lastError($events(), $run, $model)],
            default => null,
        };

        if ($outcome === null) {
            $failed = $model->stageResults->first(fn (StageResult $stage): bool => $stage->status === 'fail' && $stage->stage !== 'opencode' && ! in_array($stage->stage, $remeasured, true));
            $outcome = match (true) {
                $failed !== null && $failed->exit_code === 124 && isset(self::STAGE_TIMEOUT_TITLES[$failed->stage]) => ['fail', self::STAGE_TIMEOUT_TITLES[$failed->stage], $this->stepTimeoutDetail($run, $model, $failed->stage)],
                $failed !== null => ['fail', self::STAGE_TITLES[$failed->stage] ?? 'Doğrulama başarısız', $this->firstLogError($run, $model, $failed->stage)],
                $model->status === 'pass' => ['pass', 'Tüm kontroller geçti', null],
                $remeasured !== [] => ['warning', 'Ölçüm hatası · yeniden doğrulamada geçti', 'İlk teslimdeki hata aynı kod yeniden doğrulanınca çıkmadı; sorun modelde değil ölçüm ortamındaydı.'],
                default => ['unknown', 'Sonuç okunamadı', null],
            };
        }

        $stageMarks = [];

        if ($modelStep?->status === 'fail' && in_array($outcome[0], ['provider', 'warning'], true)) {
            $stageMarks['opencode'] = ['variant' => 'provider', 'note' => 'sağlayıcı kaynaklı'];
        }

        foreach ($model->stageResults as $stage) {
            if ($outcome[0] === 'provider' && $stage->stage !== 'opencode') {
                $stageMarks[$stage->stage] = ['variant' => 'muted', 'note' => 'puanlanmaz'];
            } elseif (in_array($stage->stage, $remeasured, true)) {
                $stageMarks[$stage->stage] = ['variant' => 'provider', 'note' => 'yeniden doğrulamada geçti'];
            }
        }

        return [
            'tone' => $outcome[0],
            'title' => $outcome[1],
            'detail' => $outcome[2],
            'autoContinued' => $autoContinued,
            'stageMarks' => $stageMarks,
            'recovery' => $this->latestRecovery($model, array_values(array_diff($run->stage_names ?? [], ['opencode']))),
        ];
    }

    /**
     * Checks that failed in the first submission but passed when the same code was validated again, by a
     * validation-only attempt with no model continuation before it: the failure was in the measurement.
     *
     * @return list<string>
     */
    private function passedOnRevalidation(ModelRun $model): array
    {
        $failed = $model->stageResults
            ->filter(fn (StageResult $stage): bool => $stage->status === 'fail' && $stage->stage !== 'opencode')
            ->pluck('stage');
        $passed = [];

        foreach ($model->modelRunAttempts->sortBy('attempt_number') as $attempt) {
            if ($attempt->mode !== 'validate') {
                break;
            }

            if ($attempt->status !== 'completed') {
                continue;
            }

            foreach ($failed as $stage) {
                if (($attempt->result['stages'][$stage]['status'] ?? null) === 'pass') {
                    $passed[] = $stage;
                }
            }
        }

        return array_values(array_unique($passed));
    }

    /**
     * The latest finished recovery attempt in a few words. It never changes the first submission's result or score.
     *
     * @param  list<string>  $validationStages
     * @return array{tone: 'pass'|'fail'|'unknown', label: string, title: string}|null
     */
    private function latestRecovery(ModelRun $model, array $validationStages): ?array
    {
        $finished = $model->modelRunAttempts->reject(fn (ModelRunAttempt $attempt): bool => $attempt->isActive())->sortByDesc('attempt_number');
        $attempt = $finished->first(fn (ModelRunAttempt $attempt): bool => is_array($attempt->result['stages'] ?? null)) ?? $finished->first();

        if ($attempt === null) {
            return null;
        }

        $label = ($attempt->mode === 'validate' ? 'Yeniden doğrulama' : 'Kurtarma').' '.$attempt->attempt_number;
        $stages = $attempt->result['stages'] ?? null;

        if (! is_array($stages)) {
            return ['tone' => 'unknown', 'label' => $label, 'title' => $attempt->status === 'stopped' ? 'Durduruldu' : 'Sonuç alınamadı'];
        }

        $failed = collect($validationStages)->first(fn (string $stage): bool => ($stages[$stage]['status'] ?? null) !== 'pass');

        if ($failed !== null) {
            $title = ($stages[$failed]['code'] ?? null) === 124 && isset(self::STAGE_TIMEOUT_TITLES[$failed])
                ? self::STAGE_TIMEOUT_TITLES[$failed]
                : (self::STAGE_TITLES[$failed] ?? 'Doğrulama başarısız');

            return ['tone' => 'fail', 'label' => $label, 'title' => $title];
        }

        $modelCode = $attempt->mode === 'continue' ? ($stages['opencode']['code'] ?? null) : 0;

        return ['tone' => 'pass', 'label' => $label, 'title' => match ($modelCode) {
            0 => 'Tüm kontroller geçti',
            124 => 'Tüm kontroller geçti; model süresi yine doldu',
            default => 'Tüm kontroller geçti; model çağrısı yarıda kaldı',
        }];
    }

    /**
     * Provider stalls: a step OpenCode restarted without ever finishing. Newer runs carry the boundary
     * monitor's count; for older runs the same rule is applied to the saved event stream.
     *
     * @return array{count: int, seconds: int, compensated: int, mostlyStalled: bool}|null
     */
    private function providerStalls(BenchmarkRun $run, ModelRun $model): ?array
    {
        $count = 0;
        $seconds = 0;
        $compensated = 0;

        foreach (['opencode.log', 'opencode.continue.log'] as $name) {
            $log = $this->files->read($run, $model, '_logs/'.$model->slug.'.'.$name, 4096);

            if (preg_match_all('/BENCHFORGE_STALLS count=(\d+) seconds=(\d+) compensated=(\d+)/', $log, $matches, PREG_SET_ORDER) > 0) {
                $last = end($matches);
                $count += (int) $last[1];
                $seconds += (int) $last[2];
                $compensated += (int) $last[3];
            }
        }

        if ($count > 0) {
            // The engine already turned a call that mostly stalled into a provider outcome.
            return ['count' => $count, 'seconds' => $seconds, 'compensated' => $compensated, 'mostlyStalled' => false];
        }

        $modelSeconds = 0;

        foreach (['opencode.events.jsonl', 'opencode.continue.events.jsonl'] as $name) {
            $events = $this->files->read($run, $model, '_logs/'.$model->slug.'.'.$name, self::EVENT_SCAN_BYTES, false);

            if ($events === '' && $name === 'opencode.events.jsonl') {
                $events = $this->files->read($run, $model, '_logs/'.$model->slug.'.opencode.log', self::EVENT_SCAN_BYTES, false);
            }

            $openStepStartedAt = null;
            $first = null;
            $last = null;

            foreach ($this->jsonLines($events) as $event) {
                $timestamp = $event['timestamp'] ?? null;

                if (! is_int($timestamp)) {
                    continue;
                }

                $first ??= $timestamp;
                $last = $timestamp;

                if (($event['type'] ?? null) === 'step_finish') {
                    $openStepStartedAt = null;
                } elseif (($event['type'] ?? null) === 'step_start') {
                    $gap = $openStepStartedAt === null ? 0 : (int) round(($timestamp - $openStepStartedAt) / 1000);

                    if ($gap >= self::STALL_MINIMUM_SECONDS) {
                        $count++;
                        $seconds += $gap;
                    }

                    $openStepStartedAt = $timestamp;
                }
            }

            if ($first !== null) {
                $modelSeconds += (int) round(($last - $first) / 1000);
            }
        }

        if ($count === 0) {
            return null;
        }

        // Same threshold as the engine: a timeout that was mostly stalled is not the model's fault.
        return ['count' => $count, 'seconds' => $seconds, 'compensated' => 0, 'mostlyStalled' => $seconds * 2 >= $modelSeconds];
    }

    /**
     * @param  array{count: int, seconds: int, compensated: int, mostlyStalled: bool}  $stalls
     */
    private function stallSentence(array $stalls): string
    {
        $sentence = "Sağlayıcı {$stalls['count']} kez yanıt vermedi (toplam ".max(1, (int) round($stalls['seconds'] / 60)).' dk)';

        return $stalls['compensated'] > 0
            ? $sentence.'; '.max(1, (int) round($stalls['compensated'] / 60)).' dakikası modelin süresinden sayılmadı.'
            : $sentence.'.';
    }

    private function events(BenchmarkRun $run, ModelRun $model, bool $autoContinued): string
    {
        $name = $autoContinued ? 'opencode.continue.events.jsonl' : 'opencode.events.jsonl';
        $events = $this->files->read($run, $model, '_logs/'.$model->slug.'.'.$name, self::EVENT_TAIL_BYTES);

        // Older runs stored the event stream in the plain OpenCode log.
        return $events !== '' ? $events : $this->files->read($run, $model, '_logs/'.$model->slug.'.opencode.log', self::EVENT_TAIL_BYTES);
    }

    /**
     * @return array{0: string, 1: string, 2: ?string}
     */
    private function noSubmission(string $events): array
    {
        $last = null;

        foreach ($this->jsonLines($events) as $event) {
            if (($event['type'] ?? null) === 'step_finish') {
                $last = $event['part'] ?? null;
            }
        }

        if (is_array($last) && ($last['reason'] ?? null) === 'length') {
            $tokens = is_array($last['tokens'] ?? null) ? $last['tokens'] : [];
            $format = fn (mixed $value): string => number_format((int) $value, 0, ',', '.');

            return ['fail', 'Çıktı sınırına takıldı, hiç dosya yazmadı', 'Tek bir yanıtta token sınırına ulaştı (düşünme '.$format($tokens['reasoning'] ?? 0).', çıktı '.$format($tokens['output'] ?? 0).') ve oturum dosya yazılmadan bitti.'];
        }

        return ['fail', 'Teslim yok', 'Model başlangıç şablonunda hiçbir dosyayı değiştirmedi.'];
    }

    private function lastError(string $events, BenchmarkRun $run, ModelRun $model): ?string
    {
        $message = null;

        foreach ($this->jsonLines($events) as $event) {
            if (($event['type'] ?? null) === 'error') {
                $data = $event['error']['data'] ?? [];
                $message = is_array($data) && is_string($data['message'] ?? null) ? $data['message'] : ($event['error']['name'] ?? null);
            }
        }

        return is_string($message) ? $this->excerpt($message, $run, $model) : null;
    }

    /** The engine writes the limit into the step log when it stops the step. */
    private function stepTimeoutDetail(BenchmarkRun $run, ModelRun $model, string $stage): string
    {
        $log = $this->files->read($run, $model, '_logs/'.$model->slug.'.'.$stage.'.log', 4096);
        $limit = preg_match('/BENCHFORGE: Adim (\d+) saniyelik sure sinirini asti/', $log, $match) === 1
            ? ' '.max(1, (int) round((int) $match[1] / 60)).' dakikada'
            : '';

        return "Adım{$limit} bitmedi; komut ve başlattığı süreçler durduruldu. Modelin komutu kapanmayan bir süreç (örneğin bir test sunucusu) başlatmış olabilir.";
    }

    private function firstLogError(BenchmarkRun $run, ModelRun $model, string $stage): ?string
    {
        $log = $this->files->read($run, $model, '_logs/'.$model->slug.'.'.$stage.'.log', 16384, false);

        $lines = preg_split('/\R/', $log) ?: [];

        // Prefer the line that names the error over status lines such as "✗ /".
        foreach (['/(PAGE ERROR|CONSOLE ERROR|Error:|error TS\d+|Type error|SyntaxError|Module not found)/i', '/(error|failed|cannot|not found|exception|✗)/i'] as $pattern) {
            foreach ($lines as $line) {
                if (preg_match($pattern, $line) === 1) {
                    $line = preg_replace('~;? ?visit https://react\.dev/errors/\S+.*$~', '', $line) ?? $line;

                    return $this->excerpt($line, $run, $model);
                }
            }
        }

        return null;
    }

    private function excerpt(string $text, BenchmarkRun $run, ModelRun $model): string
    {
        $clean = $this->files->sanitize($text, $this->files->runPath($run, $model), $model->slug);

        return mb_strimwidth(preg_replace('/\s+/', ' ', $clean) ?? $clean, 0, 220, '…');
    }

    /**
     * @return iterable<array<string, mixed>>
     */
    private function jsonLines(string $text): iterable
    {
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if ($line === '' || $line[0] !== '{') {
                continue;
            }

            $decoded = json_decode($line, true);

            if (is_array($decoded)) {
                yield $decoded;
            }
        }
    }
}
