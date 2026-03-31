<?php

namespace Database\Factories\Prospector;

use App\Models\Prospector\Contact;
use App\Models\Prospector\ContactNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactNote>
 */
class ContactNoteFactory extends Factory
{
    protected $model = ContactNote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'user_id' => User::factory(),
            'body' => fake()->paragraph(),
        ];
    }

    public function forContact(Contact $contact): static
    {
        return $this->state(fn () => ['contact_id' => $contact->id]);
    }

    public function byUser(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->id]);
    }
}
