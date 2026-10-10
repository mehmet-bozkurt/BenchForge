<?php

namespace Database\Seeders;

use App\Models\ModelRun;
use Illuminate\Database\Seeder;

class ModelRunSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        ModelRun::factory()->create();
    }
}
