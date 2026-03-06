<?php

namespace Database\Factories;

use App\Models\CorporateDocument;
use App\Models\Corporation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CorporateDocument>
 */
class CorporateDocumentFactory extends Factory
{
    protected $model = CorporateDocument::class;

    public function definition(): array
    {
        return [
            'corporation_id' => Corporation::factory(),
            'uploaded_by' => User::factory()->internal(),
            'title' => fake()->words(3, true),
            'type' => 'operating_agreement',
            'original_file_path' => 'corporate-documents/'.fake()->uuid().'.pdf',
            'original_filename' => fake()->words(2, true).'.pdf',
            'version' => 1,
            'status' => 'ready',
        ];
    }

    public function processing(): static
    {
        return $this->state(fn () => [
            'status' => 'processing',
        ]);
    }

    public function withParsedContent(): static
    {
        return $this->state(fn () => [
            'parsed_content' => [
                'sections' => [
                    ['title' => 'Article I', 'content' => fake()->paragraph()],
                    ['title' => 'Article II', 'content' => fake()->paragraph()],
                ],
            ],
        ]);
    }

    public function error(): static
    {
        return $this->state(fn () => [
            'status' => 'error',
            'parse_error' => 'Failed to parse document: '.fake()->sentence(),
        ]);
    }
}
