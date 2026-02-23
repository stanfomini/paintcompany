<?php

namespace Database\Factories;

use App\Models\EmailAccount;
use App\Models\EmailThread;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EmailMessage>
 */
class EmailMessageFactory extends Factory
{
    public function definition(): array
    {
        $bodyText = fake()->paragraphs(3, true);

        return [
            'email_thread_id' => EmailThread::factory(),
            'email_account_id' => EmailAccount::factory(),
            'direction' => 'inbound',
            'from_address' => fake()->safeEmail(),
            'from_name' => fake()->name(),
            'to_addresses' => [['address' => fake()->safeEmail(), 'name' => fake()->name()]],
            'cc_addresses' => null,
            'bcc_addresses' => null,
            'subject' => fake()->sentence(),
            'body_html' => '<p>'.nl2br(e($bodyText)).'</p>',
            'body_text' => $bodyText,
            'snippet' => Str::limit($bodyText, 200),
            'message_id' => '<'.fake()->uuid().'@'.config('email.domain').'>',
            'in_reply_to' => null,
            'references' => null,
            'mailgun_message_id' => null,
            'raw_headers' => null,
            'is_read' => false,
            'is_starred' => false,
            'sent_at' => now(),
            'read_at' => null,
        ];
    }

    public function inbound(): static
    {
        return $this->state(fn (array $attributes) => [
            'direction' => 'inbound',
        ]);
    }

    public function outbound(): static
    {
        return $this->state(fn (array $attributes) => [
            'direction' => 'outbound',
            'is_read' => true,
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'direction' => 'draft',
            'sent_at' => null,
            'message_id' => null,
        ]);
    }

    public function read(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_read' => true,
            'read_at' => now(),
        ]);
    }

    public function starred(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_starred' => true,
        ]);
    }
}
