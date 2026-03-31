<?php

namespace Database\Factories\Prospector;

use App\Models\Prospector\Pipeline;
use App\Models\Prospector\PipelineStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PipelineStage>
 */
class PipelineStageFactory extends Factory
{
    protected $model = PipelineStage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pipeline_id' => Pipeline::factory(),
            'name' => fake()->randomElement(['New Lead', 'Contacted', 'Qualified', 'Proposal Sent', 'Won', 'Lost']),
            'color' => fake()->hexColor(),
            'sort_order' => 0,
        ];
    }

    public function forPipeline(Pipeline $pipeline): static
    {
        return $this->state(fn () => ['pipeline_id' => $pipeline->id]);
    }
}
