<?php

namespace Database\Factories\Prospector;

use App\Models\Prospector\Call;
use App\Models\Prospector\CallIntelligence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CallIntelligence>
 */
class CallIntelligenceFactory extends Factory
{
    protected $model = CallIntelligence::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'call_id' => Call::factory()->completed(),
            'transcription_raw' => null,
            'transcription_segments' => null,
            'speaker_labels' => null,
            'sentiment_overall' => null,
            'sentiment_by_segment' => null,
            'lead_score' => null,
            'scoring_factors' => null,
            'keywords' => null,
            'objections' => null,
            'action_items' => null,
            'ai_summary' => null,
            'ai_coaching_notes' => null,
            'model_version' => null,
            'processed_at' => null,
        ];
    }

    public function processed(): static
    {
        return $this->state(fn () => [
            'transcription_raw' => fake()->paragraphs(3, true),
            'transcription_segments' => [
                ['start' => 0.0, 'end' => 5.2, 'text' => fake()->sentence(), 'speaker' => 'agent'],
                ['start' => 5.3, 'end' => 12.1, 'text' => fake()->sentence(), 'speaker' => 'contact'],
                ['start' => 12.2, 'end' => 20.0, 'text' => fake()->sentence(), 'speaker' => 'agent'],
            ],
            'speaker_labels' => ['agent' => 'Sales Rep', 'contact' => 'Homeowner'],
            'sentiment_overall' => fake()->randomFloat(2, -1, 1),
            'sentiment_by_segment' => [
                ['segment' => 0, 'sentiment' => 0.6],
                ['segment' => 1, 'sentiment' => -0.2],
                ['segment' => 2, 'sentiment' => 0.8],
            ],
            'lead_score' => fake()->numberBetween(0, 100),
            'scoring_factors' => [
                ['factor' => 'Expressed interest in service', 'weight' => 30],
                ['factor' => 'Asked about pricing', 'weight' => 20],
                ['factor' => 'Agreed to follow-up', 'weight' => 25],
            ],
            'keywords' => ['painting', 'estimate', 'interior', 'timeline', 'price'],
            'objections' => ['Need to check with spouse', 'Want multiple quotes'],
            'action_items' => ['Send estimate by Friday', 'Schedule in-person walkthrough'],
            'ai_summary' => fake()->paragraph(),
            'ai_coaching_notes' => fake()->paragraph(),
            'model_version' => 'claude-haiku-4-5-20251001',
            'processed_at' => now(),
        ]);
    }

    public function cold(): static
    {
        return $this->processed()->state(fn () => [
            'lead_score' => fake()->numberBetween(0, 30),
            'sentiment_overall' => fake()->randomFloat(2, -1, -0.2),
        ]);
    }

    public function warm(): static
    {
        return $this->processed()->state(fn () => [
            'lead_score' => fake()->numberBetween(31, 60),
            'sentiment_overall' => fake()->randomFloat(2, -0.1, 0.5),
        ]);
    }

    public function hot(): static
    {
        return $this->processed()->state(fn () => [
            'lead_score' => fake()->numberBetween(61, 100),
            'sentiment_overall' => fake()->randomFloat(2, 0.5, 1.0),
        ]);
    }
}
