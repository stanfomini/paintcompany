<?php

namespace Database\Factories;

use App\Models\Keyword;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Keyword>
 */
class KeywordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'keyword' => $this->faker->unique()->words(3, true),
            'search_volume' => $this->faker->numberBetween(10, 5000),
            'competition' => $this->faker->randomElement(Keyword::COMPETITIONS),
            'cpc' => $this->faker->randomFloat(2, 0.5, 25),
            'intent' => $this->faker->randomElement(Keyword::INTENTS),
            'status' => 'researched',
            'source' => $this->faker->randomElement(Keyword::SOURCES),
            'default_sentence' => null,
            'notes' => null,
            'last_researched_at' => now(),
        ];
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function source(string $source): static
    {
        return $this->state(fn () => ['source' => $source]);
    }

    public function manual(): static
    {
        return $this->source('manual');
    }
}
