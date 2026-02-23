<?php

namespace Database\Factories;

use App\Models\EmailMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EmailAttachment>
 */
class EmailAttachmentFactory extends Factory
{
    public function definition(): array
    {
        $filename = fake()->word().'.pdf';

        return [
            'email_message_id' => EmailMessage::factory(),
            'path' => 'email-attachments/'.fake()->uuid().'/'.$filename,
            'filename' => $filename,
            'original_filename' => $filename,
            'mime_type' => 'application/pdf',
            'file_size' => fake()->numberBetween(1024, 5 * 1024 * 1024),
            'content_id' => null,
            'is_inline' => false,
        ];
    }

    public function inline(): static
    {
        $filename = fake()->word().'.png';

        return $this->state(fn (array $attributes) => [
            'filename' => $filename,
            'original_filename' => $filename,
            'mime_type' => 'image/png',
            'content_id' => fake()->uuid().'@'.config('email.domain'),
            'is_inline' => true,
        ]);
    }
}
