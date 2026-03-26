<?php

use App\Models\CompanySetting;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Property;
use App\Models\User;
use App\Notifications\AgentListingSubmitted;
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

    // Top fields (always required)
    public string $first_name = '';

    public string $last_name = '';

    public string $email = '';

    public string $phone = '';

    public string $company_name = '';

    // Listing fields (optional — triggers tier 2 when address + city filled)
    public string $address = '';

    public string $city = '';

    public string $state = '';

    public string $zip = '';

    public ?string $listing_price = null;

    public string $intent = 'selling';

    public ?int $createdAgentId = null;

    #[On('open-quick-add-agent')]
    public function openModal(): void
    {
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->reset([
            'first_name', 'last_name', 'email', 'phone', 'company_name',
            'address', 'city', 'state', 'zip', 'listing_price', 'intent',
            'createdAgentId',
        ]);
        $this->intent = 'selling';
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
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:50'],
            'zip' => ['nullable', 'string', 'max:20'],
            'listing_price' => ['nullable', 'numeric', 'min:0'],
            'intent' => ['required', 'in:selling,buying'],
        ]);

        $isFullForm = ! empty($validated['address']) && ! empty($validated['city']);

        $user = DB::transaction(function () use ($validated, $isFullForm) {
            // Set type BEFORE save so boot hook creates AgentProfile
            $user = new User([
                'name' => trim($validated['first_name'].' '.$validated['last_name']),
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'password' => bcrypt(str()->random(32)),
                'needs_onboarding' => ! $isFullForm,
            ]);
            $user->type = 'agent';
            $user->is_active = true;
            $user->save(); // created hook fires with type='agent' → AgentProfile auto-created

            // Update the auto-created AgentProfile with form data
            $user->agentProfile->update([
                'company_name' => $validated['company_name'] ?? null,
                'phone' => $validated['phone'] ?? null,
            ]);

            // Tier 2: Create property, deal, and estimate
            if ($isFullForm) {
                $property = Property::create([
                    'agent_id' => $user->id,
                    'source' => 'agent_referral',
                    'address' => $validated['address'],
                    'city' => $validated['city'],
                    'state' => ! empty($validated['state']) ? strtoupper($validated['state']) : null,
                    'zip' => $validated['zip'] ?? null,
                    'listing_status' => 'active',
                    'listing_price' => $validated['listing_price'] ?: null,
                ]);

                $deal = Deal::create([
                    'property_id' => $property->id,
                    'agent_id' => $user->id,
                    'title' => $validated['address'].', '.$validated['city'],
                    'stage' => 'lead',
                    'estimated_value' => $validated['listing_price'] ?: null,
                    'agent_intent' => $validated['intent'],
                    'listing_value' => $validated['listing_price'] ?: null,
                    'roi_scope' => 'interior',
                ]);

                Estimate::create([
                    'deal_id' => $deal->id,
                    'created_by' => auth()->id(),
                    'title' => 'Paint Estimate - '.$validated['address'],
                    'status' => 'draft',
                    'project_type' => 'interior',
                ]);

                // Notify internal team
                $internalUsers = User::where('type', 'internal')->get();
                foreach ($internalUsers as $internalUser) {
                    $internalUser->notify(new AgentListingSubmitted($deal, $user));
                }
            }

            return $user;
        });

        // Send invitation email
        $invitation = null;
        try {
            $invitationService = app(InvitationService::class);
            $invitation = $invitationService->sendInvitation(
                inviter: auth()->user(),
                email: $validated['email'],
                invitedUserType: 'agent',
                existingUserId: $user->id,
            );
        } catch (\InvalidArgumentException $e) {
            Log::warning('Quick-add agent: invitation failed', ['error' => $e->getMessage()]);
        }

        // Send SMS with invite link
        if ($invitation && ! empty($validated['phone'])) {
            try {
                $twilio = app(TwilioService::class);
                if ($twilio->isEnabled()) {
                    $acceptUrl = app(InvitationService::class)->generateAcceptUrl($invitation);
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
                Log::warning('Quick-add agent: SMS failed', ['error' => $e->getMessage()]);
            }
        }

        $this->createdAgentId = $user->id;
        $this->showModal = false;
        $this->showSuccessModal = true;
    }

    public function goToAgent(): void
    {
        $this->redirect(route('agents.show', $this->createdAgentId));
    }

    public function stayOnHub(): void
    {
        $this->showSuccessModal = false;
        $this->dispatch('agent-quick-added');
        $this->reset([
            'first_name', 'last_name', 'email', 'phone', 'company_name',
            'address', 'city', 'state', 'zip', 'listing_price', 'intent',
            'createdAgentId',
        ]);
        $this->intent = 'selling';
    }
};
?>

<div>
    {{-- Quick Add Agent Form Modal --}}
    <flux:modal wire:model="showModal" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Quick Add Agent') }}</flux:heading>
                <flux:text class="mt-1 text-zinc-600 dark:text-zinc-400">
                    {{ __('Create a real estate agent account. They\'ll receive an invitation email and SMS.') }}
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
                    <flux:input wire:model="company_name" label="{{ __('Brokerage / Company') }}" placeholder="RE/MAX, Keller Williams, etc." />
                    @error('company_name') <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text> @enderror
                </flux:field>

                {{-- Listing Details (optional — expands to tier 2) --}}
                <div x-data="{ expanded: false }">
                    <button type="button" @click="expanded = !expanded" class="flex items-center gap-2 text-sm text-blue-600 dark:text-blue-400 hover:underline">
                        <flux:icon x-show="!expanded" name="plus" class="size-4" />
                        <flux:icon x-show="expanded" name="minus" class="size-4" x-cloak />
                        <span x-text="expanded ? '{{ __('Hide Listing Details') }}' : '{{ __('Add Listing Details (optional)') }}'"></span>
                    </button>

                    <div x-show="expanded" x-cloak class="mt-3 space-y-4 border-t border-zinc-200 dark:border-zinc-700 pt-4">
                        <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">
                            {{ __('Fill these out to skip agent onboarding and create a deal + estimate automatically.') }}
                        </flux:text>

                        <flux:field>
                            <flux:input wire:model="address" label="{{ __('Property Address') }}" placeholder="123 Main St" />
                            @error('address') <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text> @enderror
                        </flux:field>

                        <div class="grid grid-cols-3 gap-3">
                            <flux:field>
                                <flux:input wire:model="city" label="{{ __('City') }}" placeholder="Denver" />
                                @error('city') <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text> @enderror
                            </flux:field>
                            <flux:field>
                                <flux:input wire:model="state" label="{{ __('State') }}" placeholder="CO" />
                                @error('state') <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text> @enderror
                            </flux:field>
                            <flux:field>
                                <flux:input wire:model="zip" label="{{ __('Zip') }}" placeholder="80202" />
                                @error('zip') <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text> @enderror
                            </flux:field>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <flux:field>
                                <flux:input wire:model="listing_price" type="number" label="{{ __('Listing Price') }}" placeholder="450000" />
                                @error('listing_price') <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text> @enderror
                            </flux:field>
                            <flux:field>
                                <flux:text class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">{{ __('Intent') }}</flux:text>
                                <div class="flex gap-4">
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="radio" wire:model="intent" value="selling" class="text-blue-600 focus:ring-blue-500" />
                                        <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ __('Selling') }}</span>
                                    </label>
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="radio" wire:model="intent" value="buying" class="text-blue-600 focus:ring-blue-500" />
                                        <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ __('Buying') }}</span>
                                    </label>
                                </div>
                            </flux:field>
                        </div>
                    </div>
                </div>

                {{-- Actions --}}
                <div class="flex justify-end gap-3 pt-4">
                    <flux:button type="button" wire:click="closeModal" variant="ghost">
                        {{ __('Cancel') }}
                    </flux:button>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="submitQuickAdd">{{ __('Create Agent') }}</span>
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
                <flux:heading size="lg">{{ __('Agent Created!') }}</flux:heading>
                <flux:text class="mt-1 text-zinc-600 dark:text-zinc-400">
                    {{ __('Invitation email and SMS sent.') }}
                </flux:text>
            </div>
            <div class="flex flex-col gap-2 pt-2">
                <flux:button wire:click="goToAgent" variant="primary" class="w-full">
                    {{ __('Go to Agent Page') }}
                </flux:button>
                <flux:button wire:click="stayOnHub" variant="ghost" class="w-full">
                    {{ __('Stay Here') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
