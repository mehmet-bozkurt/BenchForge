<?php

namespace App\Services;

use Illuminate\Database\Migrations\Migrator;
use Throwable;

/**
 * Database updates (migrations) that ship with the code but have not been applied to the local database yet.
 * Results of a run cannot be saved while one is pending, so the app says so before a run starts.
 */
class PendingMigrations
{
    public const COMMAND = 'php artisan migrate';

    /**
     * @return list<string> Names of the migrations that still have to run; empty when the database is up to date
     *                      or cannot be inspected (a missing database is reported by the connection checks).
     */
    public function names(): array
    {
        try {
            /** @var Migrator $migrator */
            $migrator = app('migrator');

            if (! $migrator->repositoryExists()) {
                return [];
            }

            $files = $migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]);

            return array_values(array_diff(array_keys($files), $migrator->getRepository()->getRan()));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * A plain sentence for the user, or null when nothing is pending.
     */
    public function warning(): ?string
    {
        $count = count($this->names());

        return $count === 0
            ? null
            : "{$count} veritabanı güncellemesi uygulanmamış; koşu sonuçları kaydedilemez. Proje klasöründe `".self::COMMAND.'` komutunu çalıştırın.';
    }
}
