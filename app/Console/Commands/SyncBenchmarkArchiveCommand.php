<?php

namespace App\Console\Commands;

use App\Services\SyncBenchmarkArchive;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('benchforge:sync')]
#[Description('Mevcut benchmark arşivini BenchForge veritabanıyla senkronize eder')]
class SyncBenchmarkArchiveCommand extends Command
{
    public function handle(SyncBenchmarkArchive $syncBenchmarkArchive): int
    {
        $this->components->info('Benchmark arşivi taranıyor...');

        $result = $syncBenchmarkArchive->handle();

        $this->table(
            ['Yeni', 'Güncellenen', 'Değişmeyen', 'Başarısız'],
            [[
                $result['created'],
                $result['updated'],
                $result['unchanged'],
                $result['failed'],
            ]],
        );

        foreach ($result['errors'] as $error) {
            $this->components->error($error);
        }

        if ($result['failed'] > 0) {
            return self::FAILURE;
        }

        $this->components->info('Senkronizasyon tamamlandı.');

        return self::SUCCESS;
    }
}
