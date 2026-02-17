<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'type' => 'internal',
            'is_active' => true,
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the user is an internal team member.
     */
    public function internal(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'internal',
        ]);
    }

    /**
     * Indicate that the user is an agent (real estate agent).
     */
    public function agent(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'agent',
        ]);
    }

    /**
     * Indicate that the user is a customer (homeowner).
     */
    public function customer(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'customer',
        ]);
    }

    /**
     * Indicate that the user is a crew member (paint crew).
     */
    public function crew(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'crew',
        ]);
    }

    /**
     * Indicate that the user account is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * Give the user full access (owner role with all permissions).
     * Must be used with internal() state. Seeds permissions if needed.
     */
    public function withFullAccess(): static
    {
        return $this->afterCreating(function (\App\Models\User $user) {
            $registrar = app(\Spatie\Permission\PermissionRegistrar::class);
            $registrar->forgetCachedPermissions();

            // Seed permissions if none exist yet
            if (\Spatie\Permission\Models\Permission::count() === 0) {
                (new \Database\Seeders\RolesAndPermissionsSeeder)->run();
            }

            $ownerRole = \Spatie\Permission\Models\Role::firstOrCreate(
                ['name' => 'owner', 'guard_name' => 'web']
            );
            $ownerRole->syncPermissions(\Spatie\Permission\Models\Permission::all());
            $user->assignRole('owner');
        });
    }

    /**
     * Assign a specific role to the user. Seeds permissions if needed.
     * Must be used with internal() state.
     */
    public function withRole(string $role): static
    {
        return $this->afterCreating(function (\App\Models\User $user) use ($role) {
            $registrar = app(\Spatie\Permission\PermissionRegistrar::class);
            $registrar->forgetCachedPermissions();

            if (\Spatie\Permission\Models\Permission::count() === 0) {
                (new \Database\Seeders\RolesAndPermissionsSeeder)->run();
            }

            $user->assignRole($role);
        });
    }

    /**
     * Indicate that the user has an uploaded avatar.
     */
    public function withAvatar(): static
    {
        return $this->state(fn (array $attributes) => [
            'avatar_path' => 'avatars/test-avatar.jpg',
        ]);
    }

    /**
     * Configure the internal user to have a signature.
     * Only applies to internal users.
     */
    public function withSignature(): static
    {
        return $this->afterCreating(function (\App\Models\User $user) {
            if ($user->type === 'internal' && $user->internalUser) {
                $user->internalUser->update([
                    'signature_data' => $user->name,
                    'signature_type' => 'typed',
                    'signature_updated_at' => now(),
                ]);
            }
        });
    }
}
