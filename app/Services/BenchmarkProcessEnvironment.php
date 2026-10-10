<?php

namespace App\Services;

class BenchmarkProcessEnvironment
{
    /** @var list<string> */
    private const INHERITED_VARIABLES = [
        'LANG', 'LANGUAGE', 'LC_ALL', 'LC_CTYPE', 'LC_NUMERIC', 'LC_TIME',
        'LC_COLLATE', 'LC_MONETARY', 'LC_MESSAGES', 'LC_PAPER', 'LC_NAME',
        'LC_ADDRESS', 'LC_TELEPHONE', 'LC_MEASUREMENT', 'LC_IDENTIFICATION',
        'TZ', 'TMPDIR', 'TMP', 'TEMP', 'TERM', 'COLORTERM', 'NO_COLOR',
        'XDG_CONFIG_HOME', 'XDG_DATA_HOME', 'XDG_CACHE_HOME', 'XDG_STATE_HOME',
    ];

    /**
     * Symfony merges inherited variables even when a custom environment is supplied.
     * False entries remove every unapproved variable without retaining its value.
     *
     * @return array<string, string|false>
     */
    public function make(?string $homePath = null, ?string $commandPath = null): array
    {
        $inherited = $_ENV + (getenv() ?: []) + $_SERVER;
        $environment = array_fill_keys(array_keys($inherited), false);

        foreach (self::INHERITED_VARIABLES as $name) {
            $value = $inherited[$name] ?? null;

            if (is_string($value) && ! str_contains($value, "\0")) {
                $environment[$name] = $value;
            }
        }

        $environment['HOME'] = $homePath ?? (string) config('benchforge.home_path');
        $environment['PATH'] = $commandPath ?? (string) config('benchforge.command_path');

        return $environment;
    }
}
