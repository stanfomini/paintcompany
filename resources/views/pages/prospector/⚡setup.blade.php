<?php

use App\Models\Prospector\Agent;
use App\Models\Prospector\Company;
use App\Models\User;
use App\Prospector\Permissions;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts.app')]
#[Title('Prospector Setup')]
class extends Component
{
    public ?int $adminUserId = null;

    public function mount(): void
    {
        // Default to current user
        $this->adminUserId = Auth::id();

        // If admin already exists, redirect away
        if (Agent::hasAnyAdmin()) {
            $this->redirect(route('prospector.dashboard'), navigate: true);
        }
    }

    #[Computed]
    public function internalUsers(): \Illuminate\Support\Collection
    {
        return User::where('type', 'internal')
            ->orderBy('name')
            ->get();
    }

    public function designateAdmin(): void
    {
        $this->validate([
            'adminUserId' => ['required', 'exists:users,id'],
        ]);

        $user = User::findOrFail($this->adminUserId);

        if ($user->type !== 'internal') {
            session()->flash('error', 'Selected user must be an internal user.');

            return;
        }

        // Get a default company to assign (first active one)
        $company = Company::where('is_active', true)->first();

        if (! $company) {
            // Create a default company if none exists
            $company = Company::create([
                'name' => 'Default Company',
                'is_active' => true,
                'twilio_account_id' => \App\Models\Prospector\TwilioAccount::first()?->id,
            ]);
        }

        // Create or update the agent record as admin
        Agent::updateOrCreate(
            ['user_id' => $user->id, 'company_id' => $company->id],
            [
                'role' => Permissions::ROLE_ADMIN,
                'permissions' => null, // Use role defaults (all)
                'is_active' => true,
                'status' => 'offline',
                'max_concurrent_lines' => 1,
                'wrap_up_seconds' => 30,
            ],
        );

        session()->flash('success', "{$user->name} has been designated as the Prospector administrator.");

        $this->redirect(route('prospector.dashboard'), navigate: true);
    }
}; ?>

<div class="mx-auto flex min-h-[80vh] max-w-2xl items-center justify-center p-6">
    <div class="w-full rounded-2xl border border-zinc-200 bg-white p-8 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
        <div class="mb-6 text-center">
            <div class="mb-3 inline-flex size-14 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900/30">
                <flux:icon name="shield-check" class="size-7 text-blue-600 dark:text-blue-400" />
            </div>
            <flux:heading size="xl">{{ __('Prospector Setup') }}</flux:heading>
            <flux:subheading class="mt-2">
                {{ __('Welcome! Before you can use Prospector, designate an administrator who will have full access and the ability to manage other users.') }}
            </flux:subheading>
        </div>

        @if (session('error'))
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">
                {{ session('error') }}
            </div>
        @endif

        <div class="space-y-5">
            <div>
                <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                    {{ __('Select Administrator') }}
                </label>
                <select
                    wire:model="adminUserId"
                    class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100"
                >
                    <option value="">{{ __('Choose a user...') }}</option>
                    @foreach ($this->internalUsers as $user)
                        <option value="{{ $user->id }}">
                            {{ $user->name }} ({{ $user->email }})
                            @if ($user->id === Auth::id()) — {{ __('You') }} @endif
                        </option>
                    @endforeach
                </select>
                <p class="mt-2 text-xs text-zinc-500">
                    {{ __('The administrator will have full access to all Prospector features and can grant permissions to other users.') }}
                </p>
            </div>

            <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-900/20">
                <div class="flex items-start gap-3">
                    <flux:icon name="exclamation-triangle" class="size-5 shrink-0 text-amber-600 dark:text-amber-400" />
                    <div class="text-xs text-amber-800 dark:text-amber-300">
                        <strong>{{ __('Important:') }}</strong>
                        {{ __('This is a one-time setup. Once an administrator is designated, this page will no longer be accessible. The administrator can then add other users and assign roles from the Agents page.') }}
                    </div>
                </div>
            </div>

            <flux:button
                variant="primary"
                wire:click="designateAdmin"
                wire:loading.attr="disabled"
                class="w-full"
            >
                <span wire:loading.remove wire:target="designateAdmin">
                    {{ __('Complete Setup') }}
                </span>
                <span wire:loading wire:target="designateAdmin">
                    {{ __('Setting up...') }}
                </span>
            </flux:button>
        </div>
    </div>
</div>
