<?php

namespace Database\Seeders;

use App\Models\Artifact;
use Illuminate\Database\Seeder;

class ArtifactSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Artifact::factory()->create();
    }
}
