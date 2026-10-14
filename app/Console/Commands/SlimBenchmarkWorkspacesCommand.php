<?php

namespace App\Console\Commands;

use App\Exceptions\BenchmarkStorageException;
use App\Models\BenchmarkRun;
use App\Services\BenchmarkRunStorage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Number;

#[Signature('benchforge:slim {run? : Yalnız bu koşu (slug)} {--dry-run : Hiçbir şey silmeden ne kazanılacağını göster}')]
#[Description('Koşuların çalışma alanlarından node_modules ve .next klasörlerini siler; kaynak kod, çıktılar ve önizlemeler kalır')]
class SlimBenchmarkWorkspacesCommand extends Command
{
    public function handle(BenchmarkRunStorage $storage): int
    {
        $runs = BenchmarkRun::query()
            ->when($this->argument('run'), fn ($query, string $slug) => $query->where('slug', $slug))
            ->orderBy('slug')
            ->get();

        if ($runs->isEmpty()) {
            $this->components->warn('Koşu bulunamadı.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $rows = [];
        $total = 0;
        $failed = false;

        foreach ($runs as $run) {
            try {
                $result = $storage->slimWorkspaces($run, $dryRun);
                $total += $result['freedBytes'];
                $rows[] = [$run->slug, $result['slimmedCount'], $result['archivedPreviews'], Number::fileSize($result['freedBytes'], 2)];
            } catch (BenchmarkStorageException $exception) {
                $failed = true;
                $rows[] = [$run->slug, '—', '—', $exception->getMessage()];
            }
        }

        $this->table(['Koşu', 'Hafifletilen model', 'Arşive alınan önizleme', $dryRun ? 'Kazanılacak alan' : 'Boşaltılan alan'], $rows);
        $this->components->info(($dryRun ? 'Kazanılacak toplam alan: ' : 'Boşaltılan toplam alan: ').Number::fileSize($total, 2));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
