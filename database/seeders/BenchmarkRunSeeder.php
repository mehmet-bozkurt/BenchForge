<?php

namespace Database\Seeders;

use App\Models\BenchmarkRun;
use Illuminate\Database\Seeder;

class BenchmarkRunSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        BenchmarkRun::factory()->create();
    }
}
