<?php

namespace Database\Seeders;

use App\Models\Prospector\Pipeline;
use App\Models\Prospector\PipelineStage;
use Illuminate\Database\Seeder;

class PipelineSeeder extends Seeder
{
    public function run(): void
    {
        $pipeline = Pipeline::firstOrCreate(
            ['is_default' => true],
            [
                'name' => 'Sales Pipeline',
                'color' => '#3B82F6',
                'sort_order' => 0,
            ],
        );

        $stages = [
            ['name' => 'New Lead', 'color' => '#3B82F6', 'sort_order' => 0],
            ['name' => 'Contacted', 'color' => '#F59E0B', 'sort_order' => 1],
            ['name' => 'Interested', 'color' => '#10B981', 'sort_order' => 2],
            ['name' => 'Appointment Set', 'color' => '#8B5CF6', 'sort_order' => 3],
            ['name' => 'Won', 'color' => '#059669', 'sort_order' => 4],
            ['name' => 'Lost', 'color' => '#EF4444', 'sort_order' => 5],
        ];

        foreach ($stages as $stage) {
            PipelineStage::firstOrCreate(
                ['pipeline_id' => $pipeline->id, 'name' => $stage['name']],
                ['color' => $stage['color'], 'sort_order' => $stage['sort_order']],
            );
        }
    }
}
