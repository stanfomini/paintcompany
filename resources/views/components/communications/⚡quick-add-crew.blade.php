<?php

use App\Models\CompanySetting;
use App\Models\User;
use App\Services\InvitationService;
use App\Services\TwilioService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public bool $showModal = false;

    public bool $showSuccessModal = false;

    public string $first_name = '';

    public string $last_name = '';

    public string $email = '';

    public string $phone = '';

    public string $company_name = '';

    public ?int $createdCrewId = null;

    #[On('open-quick-add-crew')]
    public function openModal(): void
    {
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->reset([
            'first_name', 'last_name', 'email', 'phone',
            'company_name', 'createdCrewId',
        ]);
        $this->resetValidation();
    }

    public function submitQuickAdd(): void
    {
        $validated = $this->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'company_name' => ['nullable', 'string', 'max:255'],
        ]);

        $user = DB::transaction(function () use ($validated) {
            // Set type BEFORE save so boot hook creates CrewProfile
            $user = new User([
                'name' => trim($validated['first_name'].' '.$validated['last_name']),
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'password' => bcrypt(str()->random(32)),
                'needs_onboarding' => true,
            ]);
            $user->type = 'crew';
            $user->is_active = true;
            $user->save(); // created hook fires with type='crew' → CrewProfile auto-created

            // Update the auto-created CrewProfile with form data
            $user->crewProfile->update([
                'company_name' => $validated['company_name'] ?? null,
                'phone' => $validated['phone'] ?? null,
            ]);

            return $user;
        });

        // Send invitation email (with existingUserId to bypass user-exists check)
        $invitation = null;
        try {
            $invitationService = app(InvitationService::class);
            $invitation = $invitationService->sendInvitation(
                inviter: auth()->user(),
                email: $validated['email'],
                invitedUserType: 'crew',
                existingUserId: $user->id,
            );
        } catch (\InvalidArgumentException $e) {
            Log::warning('Quick-add crew: invitation failed', ['error' => $e->getMessage()]);
        }

        // Send SMS with invite link
        if ($invitation && ! empty($validated['phone'])) {
            try {
                $twilio = app(TwilioService::class);
                if ($twilio->isEnabled()) {
                    $invitationService = app(InvitationService::class);
                    $acceptUrl = $invitationService->generateAcceptUrl($invitation);
                    $companyName = CompanySetting::getInstance()->company_name ?? config('app.name');

                    $twilio->sendSms(
                        $validated['phone'],
                        __("Hi :name! You've been invited to join :company. Set up your account here: :link", [
                            'name' => $validated['first_name'],
                            'company' => $companyName,
                            'link' => $acceptUrl,
                        ]),
                    );
                }
            } catch (\Exception $e) {
                Log::warning('Quick-add crew: SMS failed', ['error' => $e->getMessage()]);
            }
        }

        $this->createdCrewId = $user->id;
        $this->showModal = false;
        $this->showSuccessModal = true;
    }

    public function goToCrew(): void
    {
        $this->redirect(route('crews.show', $this->createdCrewId));
    }

    public function stayOnHub(): void
    {
        $this->showSuccessModal = false;
        $this->dispatch('crew-quick-added');
        $this->reset([
            'first_name', 'last_name', 'email', 'phone',
            'company_name', 'createdCrewId',
        ]);
    }
};
?>

<div>
    {{-- Quick Add Crew Form Modal --}}
    <flux:modal wire:model="showModal" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Quick Add Crew') }}</flux:heading>
                <flux:text class="mt-1 text-zinc-600 dark:text-zinc-400">
                    {{ __('Create a crew member account. They\'ll receive an invitation email.') }}
                </flux:text>
            </div>

            <form wire:submit="submitQuickAdd" class="space-y-4">
                {{-- Name --}}
                <div class="grid grid-cols-2 gap-3">
                    <flux:field>
                        <flux:input wire:model="first_name" label="{{ __('First Name') }}" placeholder="First name" required />
                        @error('first_name') <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text> @enderror
                    </flux:field>
                    <flux:field>
                        <flux:input wire:model="last_name" label="{{ __('Last Name') }}" placeholder="Last name" required />
                        @error('last_name') <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text> @enderror
                    </flux:field>
                </div>

                {{-- Contact --}}
                <div class="grid grid-cols-2 gap-3">
                    <flux:field>
                        <flux:input wire:model="email" type="email" label="{{ __('Email') }}" placeholder="email@example.com" required />
                        @error('email') <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text> @enderror
                    </flux:field>
                    <flux:field>
                        <flux:input wire:model="phone" label="{{ __('Phone') }}" placeholder="(555) 123-4567" />
                        @error('phone') <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text> @enderror
                    </flux:field>
                </div>

                {{-- Company --}}
                <flux:field>
                    <flux:input wire:model="company_name" label="{{ __('Company Name') }}" placeholder="Painting company name" />
                    @error('company_name') <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text> @enderror
                </flux:field>

                {{-- Actions --}}
                <div class="flex justify-end gap-3 pt-4">
                    <flux:button type="button" wire:click="closeModal" variant="ghost">
                        {{ __('Cancel') }}
                    </flux:button>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="submitQuickAdd">{{ __('Create Crew') }}</span>
                        <span wire:loading wire:target="submitQuickAdd">{{ __('Creating...') }}</span>
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>

    {{-- Success Modal --}}
    <flux:modal wire:model="showSuccessModal" class="max-w-sm">
        <div class="text-center space-y-4 py-2">
            <div class="mx-auto w-12 h-12 rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
                <flux:icon name="check" class="w-6 h-6 text-green-600 dark:text-green-400" />
            </div>
            <div>
                <flux:heading size="lg">{{ __('Crew Member Created!') }}</flux:heading>
                <flux:text class="mt-1 text-zinc-600 dark:text-zinc-400">
                    {{ __('Invitation email sent.') }}
                </flux:text>
            </div>
            <div class="flex flex-col gap-2 pt-2">
                <flux:button wire:click="goToCrew" variant="primary" class="w-full">
                    {{ __('Go to Crew Page') }}
                </flux:button>
                <flux:button wire:click="stayOnHub" variant="ghost" class="w-full">
                    {{ __('Stay Here') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
