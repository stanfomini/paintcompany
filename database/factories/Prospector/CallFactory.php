<?php

namespace Database\Factories\Prospector;

use App\Models\Prospector\Agent;
use App\Models\Prospector\Call;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Call>
 */
class CallFactory extends Factory
{
    protected $model = Call::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $areaCode = fake()->randomElement(['303', '720', '719', '970']);
        $fromNumber = '+1'.$areaCode.fake()->numerify('#######');
        $toNumber = '+1'.fake()->randomElement(['303', '720', '719', '970']).fake()->numerify('#######');

        return [
            'company_id' => Company::factory(),
            'dialer_session_id' => null,
            'agent_id' => Agent::factory(),
            'contact_id' => Contact::factory(),
            'campaign_id' => null,
            'direction' => 'outbound',
            'from_number' => $fromNumber,
            'from_phone_number_id' => null,
            'to_number' => $toNumber,
            'twilio_call_sid' => 'CA'.fake()->regexify('[a-f0-9]{32}'),
            'twilio_conference_sid' => null,
            'status' => 'queued',
            'disposition' => null,
            'disposition_notes' => null,
            'amd_result' => null,
            'duration_seconds' => null,
            'talk_seconds' => null,
            'recording_url' => null,
            'recording_path' => null,
            'callback_at' => null,
            'started_at' => now(),
            'answered_at' => null,
            'ended_at' => null,
        ];
    }

    public function outbound(): static
    {
        return $this->state(fn () => ['direction' => 'outbound']);
    }

    public function inbound(): static
    {
        return $this->state(fn () => ['direction' => 'inbound']);
    }

    public function completed(): static
    {
        $startedAt = now()->subMinutes(fake()->numberBetween(1, 15));
        $answeredAt = $startedAt->copy()->addSeconds(fake()->numberBetween(5, 20));
        $endedAt = $answeredAt->copy()->addSeconds(fake()->numberBetween(30, 600));

        return $this->state(fn () => [
            'status' => 'completed',
            'disposition' => fake()->randomElement(['interested', 'not_interested', 'callback', 'do_not_call']),
            'amd_result' => 'human',
            'started_at' => $startedAt,
            'answered_at' => $answeredAt,
            'ended_at' => $endedAt,
            'duration_seconds' => $startedAt->diffInSeconds($endedAt),
            'talk_seconds' => $answeredAt->diffInSeconds($endedAt),
        ]);
    }

    public function noAnswer(): static
    {
        return $this->state(fn () => [
            'status' => 'no_answer',
            'ended_at' => now(),
            'duration_seconds' => fake()->numberBetween(15, 45),
        ]);
    }

    public function voicemail(): static
    {
        return $this->state(fn () => [
            'status' => 'voicemail',
            'amd_result' => 'machine',
            'ended_at' => now(),
            'duration_seconds' => fake()->numberBetween(10, 30),
        ]);
    }
}
