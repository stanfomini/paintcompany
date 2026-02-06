<?php

namespace Database\Seeders;

use App\Services\RoadmapSeederService;
use Illuminate\Database\Seeder;

class RoadmapSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $service = new RoadmapSeederService;
        $service->seedDefaultRoadmap();
    }
}
