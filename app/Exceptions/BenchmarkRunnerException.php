<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;

class BenchmarkRunnerException extends Exception implements ShouldntReport
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message, private readonly array $details = [])
    {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->details;
    }
}
