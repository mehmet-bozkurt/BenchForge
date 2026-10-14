<?php

namespace App\Services;

use App\Models\BenchmarkRun;
use App\Models\ModelRun;
use App\Models\StageResult;

/**
 * Explains in one sentence what happened to a model in a run, from its stage results and its own logs.
 * Only the model's own log files are read (through BenchmarkModelFiles), and every excerpt is sanitised.
 */
class ModelRunOutcome
{
    private const EVENT_TAIL_BYTES = 262144;

    private const STAGE_TITLES = [
        'lint' => 'Lint kontrolü başarısız',
        'build' => 'Production build başarısız',
        'export' => 'Static export üretilmedi',
        'routes' => 'İstenen rota açılmadı',
        'runtime' => 'Sayfa tarayıcıda hata verdi',
    ];

    public function __construct(private BenchmarkModelFiles $files) {}

    /**
     * @return array{tone: 'pass'|'fail'|'provider'|'warning'|'unknown', title: string, detail: ?string, autoContinued: bool}
     */
    public function describe(BenchmarkRun $run, ModelRun $model): array
    {
        $stages = $model->stageResults->keyBy('stage');
        $modelStep = $stages->get('opencode');
        $code = $modelStep?->exit_code;
        $autoContinued = $this->files->read($run, $model, '_logs/'.$model->slug.'.auto-continue.log', 2048) !== '';
        $events = fn (): string => $this->events($run, $model, $autoContinued);

        $outcome = match (true) {
            $code === StageResult::PROVIDER_ERROR_CODE => ['provider', 'Sağlayıcı veya hesap hatası · puanlanmaz', $this->lastError($events(), $run, $model)],
            $code === StageResult::INTERRUPTED_AFTER_SUBMISSION_CODE => ['warning', 'Bağlantı sonda koptu · teslim puanlandı', $this->lastError($events(), $run, $model)],
            $code === StageResult::NO_SUBMISSION_CODE => $this->noSubmission($events()),
            $code === 124 => ['fail', 'Süre sınırı doldu', 'Model adımı ayrılan sürede bitmedi; o ana kadar yazılanlar doğrulandı.'],
            $code === 65 => ['fail', 'Model başlatılamadı', 'Çalışma sınırı hazırlanamadığı için model çağrılmadı.'],
            $code !== null && $code !== 0 && $modelStep?->status === 'fail' => ['fail', "Model adımı hata verdi (kod {$code})", $this->lastError($events(), $run, $model)],
            default => null,
        };

        if ($outcome === null) {
            $failed = $model->stageResults->first(fn (StageResult $stage): bool => $stage->status === 'fail' && $stage->stage !== 'opencode');
            $outcome = match (true) {
                $failed !== null => ['fail', self::STAGE_TITLES[$failed->stage] ?? 'Doğrulama başarısız', $this->firstLogError($run, $model, $failed->stage)],
                $model->status === 'pass' => ['pass', 'Tüm kontroller geçti', null],
                default => ['unknown', 'Sonuç okunamadı', null],
            };
        }

        return ['tone' => $outcome[0], 'title' => $outcome[1], 'detail' => $outcome[2], 'autoContinued' => $autoContinued];
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
