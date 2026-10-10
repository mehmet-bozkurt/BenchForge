<?php

namespace Database\Seeders;

use App\Models\StageResult;
use Illuminate\Database\Seeder;

class StageResultSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        StageResult::factory()->create();
    }
}
