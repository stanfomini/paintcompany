<?php

namespace Database\Factories;

use App\Models\ChatMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ChatAttachment>
 */
class ChatAttachmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $filename = fake()->uuid().'.pdf';

        return [
            'message_id' => ChatMessage::factory(),
            'path' => 'chat-attachments/'.$filename,
            'filename' => $filename,
            'original_filename' => fake()->word().'.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => fake()->numberBetween(1024, 10485760),
        ];
    }

    /**
     * Set specific message.
     */
    public function forMessage(ChatMessage $message): static
    {
        return $this->state(fn (array $attributes) => [
            'message_id' => $message->id,
        ]);
    }

    /**
     * Set as an image attachment.
     */
    public function image(): static
    {
        $extension = fake()->randomElement(['jpg', 'png', 'gif', 'webp']);
        $filename = fake()->uuid().'.'.$extension;

        return $this->state(fn (array $attributes) => [
            'path' => 'chat-attachments/'.$filename,
            'filename' => $filename,
            'original_filename' => fake()->word().'.'.$extension,
            'mime_type' => 'image/'.$extension,
            'file_size' => fake()->numberBetween(10240, 5242880),
        ]);
    }

    /**
     * Set as a PDF attachment.
     */
    public function pdf(): static
    {
        $filename = fake()->uuid().'.pdf';

        return $this->state(fn (array $attributes) => [
            'path' => 'chat-attachments/'.$filename,
            'filename' => $filename,
            'original_filename' => fake()->word().'.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => fake()->numberBetween(10240, 10485760),
        ]);
    }

    /**
     * Set as a document attachment.
     */
    public function document(): static
    {
        $extension = fake()->randomElement(['doc', 'docx', 'xls', 'xlsx']);
        $filename = fake()->uuid().'.'.$extension;

        $mimeTypes = [
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];

        return $this->state(fn (array $attributes) => [
            'path' => 'chat-attachments/'.$filename,
            'filename' => $filename,
            'original_filename' => fake()->word().'.'.$extension,
            'mime_type' => $mimeTypes[$extension],
            'file_size' => fake()->numberBetween(10240, 10485760),
        ]);
    }

    /**
     * Set specific file size.
     */
    public function withSize(int $bytes): static
    {
        return $this->state(fn (array $attributes) => [
            'file_size' => $bytes,
        ]);
    }
}
