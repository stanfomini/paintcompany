<?php

namespace Database\Factories\Prospector;

use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\RvmDrop;
use App\Models\Prospector\RvmTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RvmDrop>
 */
class RvmDropFactory extends Factory
{
    protected $model = RvmDrop::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $fromNumber = '+1'.fake()->randomElement(['303', '720', '719', '970']).fake()->numerify('#######');
        $toNumber = '+1'.fake()->randomElement(['303', '720', '719', '970']).fake()->numerify('#######');

        return [
            'company_id' => Company::factory(),
            'contact_id' => Contact::factory(),
            'template_id' => RvmTemplate::factory(),
            'sequence_enrollment_id' => null,
            'method' => fake()->randomElement(['twilio_amd', 'slybroadcast']),
            'provider_id' => null,
            'status' => 'queued',
            'from_number' => $fromNumber,
            'to_number' => $toNumber,
            'error' => null,
            'sent_at' => null,
            'delivered_at' => null,
        ];
    }

    public function sent(): static
    {
        return $this->state(fn () => [
            'status' => 'sent',
            'provider_id' => 'CA'.fake()->regexify('[a-f0-9]{32}'),
            'sent_at' => now(),
        ]);
    }

    public function delivered(): static
    {
        return $this->state(fn () => [
            'status' => 'delivered',
            'provider_id' => 'CA'.fake()->regexify('[a-f0-9]{32}'),
            'sent_at' => now()->subMinutes(2),
            'delivered_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => 'failed',
            'error' => 'Call could not be completed',
        ]);
    }

    public function viaTwilio(): static
    {
        return $this->state(fn () => ['method' => 'twilio_amd']);
    }

    public function viaSlybroadcast(): static
    {
        return $this->state(fn () => ['method' => 'slybroadcast']);
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn () => ['company_id' => $company->id]);
    }
}
