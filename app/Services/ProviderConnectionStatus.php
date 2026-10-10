<?php

namespace App\Services;

use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class ProviderConnectionStatus
{
    public function __construct(private BenchmarkProcessEnvironment $processEnvironment) {}

    /** @var array<string, mixed>|null */
    private ?array $snapshot = null;

    /**
     * @return array{
     *     checkedAt: string,
     *     cli: array{status: 'pass'|'fail', label: string, message: string},
     *     providers: array{
     *         openrouter: array{status: 'pass'|'fail'|'unknown', label: string, message: string},
     *         opencode-go: array{status: 'pass'|'fail'|'unknown', label: string, message: string}
     *     }
     * }
     */
    public function inspect(): array
    {
        if ($this->snapshot !== null) {
            return $this->snapshot;
        }

        $binary = $this->findOpenCodeBinary();

        if ($binary === null) {
            return $this->snapshot = [
                'checkedAt' => now()->toIso8601String(),
                'cli' => $this->result('fail', 'OpenCode CLI', 'OpenCode komutu yapılandırılan PATH içinde bulunamadı.'),
                'providers' => [
                    'openrouter' => $this->result('unknown', 'OpenRouter', 'OpenCode bulunamadığı için bağlantı denetlenemedi.'),
                    'opencode-go' => $this->result('unknown', 'OpenCode Go', 'OpenCode bulunamadığı için bağlantı denetlenemedi.'),
                ],
            ];
        }

        $cli = $this->versionStatus($binary);
        $providerOutput = $this->authenticationList($binary);

        return $this->snapshot = [
            'checkedAt' => now()->toIso8601String(),
            'cli' => $cli,
            'providers' => [
                'openrouter' => $this->providerStatus('openrouter', 'OpenRouter', $providerOutput),
                'opencode-go' => $this->providerStatus('opencode-go', 'OpenCode Go', $providerOutput),
            ],
        ];
    }

    /** @return array{status: 'pass'|'fail', label: string, message: string} */
    private function versionStatus(string $binary): array
    {
        try {
            $process = new Process([$binary, '--version'], null, $this->environment());
            $process->setTimeout(5);
            $process->run();

            if (! $process->isSuccessful()) {
                return $this->result('fail', 'OpenCode CLI', 'Sürüm bilgisi alınamadı.');
            }

            $version = Str::limit(trim(strtok($process->getOutput(), "\r\n") ?: 'sürüm doğrulandı'), 80, '');

            return $this->result('pass', 'OpenCode CLI', $version);
        } catch (Throwable) {
            return $this->result('fail', 'OpenCode CLI', 'OpenCode çalıştırılamadı.');
        }
    }

    private function authenticationList(string $binary): ?string
    {
        try {
            $process = new Process([$binary, 'auth', 'list', '--pure'], null, $this->environment());
            $process->setTimeout(8);
            $process->run();

            if (! $process->isSuccessful()) {
                return null;
            }

            return (string) preg_replace(
                '/\x1B(?:[@-Z\\-_]|\[[0-?]*[ -\/]*[@-~])/',
                '',
                $process->getOutput(),
            );
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{status: 'pass'|'fail'|'unknown', label: string, message: string} */
    private function providerStatus(string $provider, string $label, ?string $output): array
    {
        if ($output === null) {
            return $this->result('unknown', $label, 'OpenCode kimlik listesi okunamadı.');
        }

        $normalizedOutput = Str::of($output)->lower()->squish()->toString();
        $connected = $provider === 'openrouter'
            ? str_contains($normalizedOutput, 'openrouter')
            : preg_match('/open\s*code\s*go|opencode-go/', $normalizedOutput) === 1;

        if ($connected) {
            return $this->result('pass', $label, 'OpenCode kimlik deposunda bağlantı hazır.');
        }

        $message = $provider === 'openrouter'
            ? 'Kimlik bulunamadı. OpenCode içinde /connect ile OpenRouter bağlayın.'
            : 'Kimlik bulunamadı. OpenCode içinde /connect ile OpenCode Go bağlantısını kurun.';

        return $this->result('fail', $label, $message);
    }

    private function findOpenCodeBinary(): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) config('benchforge.command_path')) as $directory) {
            if ($directory === '') {
                continue;
            }

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

    /**
     * @param  'pass'|'fail'|'unknown'  $status
     * @return array{status: 'pass'|'fail'|'unknown', label: string, message: string}
     */
    private function result(string $status, string $label, string $message): array
    {
        return compact('status', 'label', 'message');
    }
}
