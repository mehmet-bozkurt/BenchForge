<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;

class BenchmarkStorageException extends Exception implements ShouldntReport {}
