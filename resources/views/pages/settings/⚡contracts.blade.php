<?php

use App\Models\CompanySetting;
use Livewire\Component;

new class extends Component {
    public function boot(): void
    {
        if (! auth()->user()?->can('settings.contracts')) {
            abort(403);
        }
    }

    public string $terms_and_conditions = '';
    public string $privacy_policy = '';
    public string $referral_terms = '';
    public string $crew_colorado_compliance_contract = '';
    public string $crew_contractor_agreement = '';
    public string $crew_background_check_consent = '';

    // Spanish versions
    public string $terms_and_conditions_es = '';
    public string $privacy_policy_es = '';
    public string $referral_terms_es = '';
    public string $crew_colorado_compliance_contract_es = '';
    public string $crew_contractor_agreement_es = '';
    public string $crew_background_check_consent_es = '';

    public function mount(): void
    {
        $settings = CompanySetting::getInstance();
        $this->terms_and_conditions = (string) ($settings->terms_and_conditions ?? '');
        $this->privacy_policy = (string) ($settings->privacy_policy ?? '');
        $this->referral_terms = (string) ($settings->referral_terms ?? '');
        $this->crew_colorado_compliance_contract = (string) ($settings->crew_colorado_compliance_contract ?? '');
        $this->crew_contractor_agreement = (string) ($settings->crew_contractor_agreement ?? '');
        $this->crew_background_check_consent = (string) ($settings->crew_background_check_consent ?? '');

        // Spanish versions
        $this->terms_and_conditions_es = (string) ($settings->terms_and_conditions_es ?? '');
        $this->privacy_policy_es = (string) ($settings->privacy_policy_es ?? '');
        $this->referral_terms_es = (string) ($settings->referral_terms_es ?? '');
        $this->crew_colorado_compliance_contract_es = (string) ($settings->crew_colorado_compliance_contract_es ?? '');
        $this->crew_contractor_agreement_es = (string) ($settings->crew_contractor_agreement_es ?? '');
        $this->crew_background_check_consent_es = (string) ($settings->crew_background_check_consent_es ?? '');
    }

    public function saveTerms(): void
    {
        CompanySetting::getInstance()->update([
            'terms_and_conditions' => $this->terms_and_conditions,
            'terms_and_conditions_es' => $this->terms_and_conditions_es,
        ]);

        session()->flash('terms_status', __('Terms & Conditions saved.'));
    }

    public function savePrivacy(): void
    {
        CompanySetting::getInstance()->update([
            'privacy_policy' => $this->privacy_policy,
            'privacy_policy_es' => $this->privacy_policy_es,
        ]);

        session()->flash('privacy_status', __('Privacy Policy saved.'));
    }

    public function saveReferral(): void
    {
        CompanySetting::getInstance()->update([
            'referral_terms' => $this->referral_terms,
            'referral_terms_es' => $this->referral_terms_es,
        ]);

        session()->flash('referral_status', __('Referral Program Terms saved.'));
    }

    public function saveCrewColorado(): void
    {
        CompanySetting::getInstance()->update([
            'crew_colorado_compliance_contract' => $this->crew_colorado_compliance_contract,
            'crew_colorado_compliance_contract_es' => $this->crew_colorado_compliance_contract_es,
        ]);

        session()->flash('crew_colorado_status', __('Colorado Compliance contract saved.'));
    }

    public function saveCrewAgreement(): void
    {
        CompanySetting::getInstance()->update([
            'crew_contractor_agreement' => $this->crew_contractor_agreement,
            'crew_contractor_agreement_es' => $this->crew_contractor_agreement_es,
        ]);

        session()->flash('crew_agreement_status', __('Contractor Agreement saved.'));
    }

    public function saveCrewBackground(): void
    {
        CompanySetting::getInstance()->update([
            'crew_background_check_consent' => $this->crew_background_check_consent,
            'crew_background_check_consent_es' => $this->crew_background_check_consent_es,
        ]);

        session()->flash('crew_background_status', __('Background Check Consent saved.'));
    }
};
?>

<div>
    <div class="mx-auto px-4 py-8 sm:px-6 lg:px-8">
        @include('pages.settings.layout', [
            'heading' => __('Contracts & Legal'),
            'subheading' => __('Manage your terms, privacy policy, referral terms, and crew contracts.'),
            'fullWidth' => true,
        ])

        <div class="mt-6 space-y-6">
            {{-- Terms & Conditions --}}
            <div x-data="{ open: true, lang: 'en' }" class="rounded-lg border border-zinc-200 dark:border-zinc-700">
                <button
                    type="button"
                    @click="open = !open"
                    class="flex w-full items-center justify-between px-5 py-4 text-left"
                >
                    <div>
                        <flux:heading size="sm">{{ __('Terms & Conditions') }}</flux:heading>
                        <flux:subheading size="sm">{{ __('Displayed when customers accept estimates.') }}</flux:subheading>
                    </div>
                    <flux:icon.chevron-down class="size-5 text-zinc-400 transition-transform" ::class="open ? 'rotate-180' : ''" />
                </button>

                <div x-show="open" x-collapse>
                    <div class="border-t border-zinc-200 px-5 py-4 dark:border-zinc-700">
                        <form wire:submit="saveTerms" class="space-y-4">
                            {{-- Language Tabs --}}
                            <div class="flex gap-1 rounded-lg bg-zinc-100 p-1 dark:bg-zinc-800">
                                <button type="button" @click="lang = 'en'" :class="lang === 'en' ? 'bg-white shadow dark:bg-zinc-700 dark:text-white' : 'text-zinc-500 dark:text-zinc-400'" class="flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition-colors">
                                    {{ __('English') }}
                                </button>
                                <button type="button" @click="lang = 'es'" :class="lang === 'es' ? 'bg-white shadow dark:bg-zinc-700 dark:text-white' : 'text-zinc-500 dark:text-zinc-400'" class="flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition-colors">
                                    {{ __('Spanish') }}
                                </button>
                            </div>

                            <div x-show="lang === 'en'">
                                <flux:field>
                                    <flux:textarea wire:model="terms_and_conditions" rows="12" :placeholder="__('Enter your terms and conditions...')" />
                                    <flux:description>
                                        {{ str_word_count($terms_and_conditions) }} {{ str_word_count($terms_and_conditions) === 1 ? __('word') : __('words') }}
                                    </flux:description>
                                </flux:field>
                            </div>

                            <div x-show="lang === 'es'">
                                <flux:field>
                                    <flux:textarea wire:model="terms_and_conditions_es" rows="12" :placeholder="__('Enter your terms and conditions in Spanish...')" />
                                    <flux:description>
                                        {{ str_word_count($terms_and_conditions_es) }} {{ str_word_count($terms_and_conditions_es) === 1 ? __('word') : __('words') }}
                                    </flux:description>
                                </flux:field>
                            </div>

                            <div class="flex items-center gap-4">
                                <flux:button type="submit" variant="primary" size="sm">{{ __('Save Terms') }}</flux:button>
                                <a href="/terms" target="_blank" class="text-sm text-zinc-500 underline hover:text-zinc-700 dark:hover:text-zinc-300">{{ __('Preview') }}</a>

                                @if (session('terms_status'))
                                    <flux:text class="text-sm text-emerald-600 dark:text-emerald-400">{{ session('terms_status') }}</flux:text>
                                @endif
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            {{-- Privacy Policy --}}
            <div x-data="{ open: true, lang: 'en' }" class="rounded-lg border border-zinc-200 dark:border-zinc-700">
                <button
                    type="button"
                    @click="open = !open"
                    class="flex w-full items-center justify-between px-5 py-4 text-left"
                >
                    <div>
                        <flux:heading size="sm">{{ __('Privacy Policy') }}</flux:heading>
                        <flux:subheading size="sm">{{ __('Your company privacy policy for customers.') }}</flux:subheading>
                    </div>
                    <flux:icon.chevron-down class="size-5 text-zinc-400 transition-transform" ::class="open ? 'rotate-180' : ''" />
                </button>

                <div x-show="open" x-collapse>
                    <div class="border-t border-zinc-200 px-5 py-4 dark:border-zinc-700">
                        <form wire:submit="savePrivacy" class="space-y-4">
                            {{-- Language Tabs --}}
                            <div class="flex gap-1 rounded-lg bg-zinc-100 p-1 dark:bg-zinc-800">
                                <button type="button" @click="lang = 'en'" :class="lang === 'en' ? 'bg-white shadow dark:bg-zinc-700 dark:text-white' : 'text-zinc-500 dark:text-zinc-400'" class="flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition-colors">
                                    {{ __('English') }}
                                </button>
                                <button type="button" @click="lang = 'es'" :class="lang === 'es' ? 'bg-white shadow dark:bg-zinc-700 dark:text-white' : 'text-zinc-500 dark:text-zinc-400'" class="flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition-colors">
                                    {{ __('Spanish') }}
                                </button>
                            </div>

                            <div x-show="lang === 'en'">
                                <flux:field>
                                    <flux:textarea wire:model="privacy_policy" rows="12" :placeholder="__('Enter your privacy policy...')" />
                                    <flux:description>
                                        {{ str_word_count($privacy_policy) }} {{ str_word_count($privacy_policy) === 1 ? __('word') : __('words') }}
                                    </flux:description>
                                </flux:field>
                            </div>

                            <div x-show="lang === 'es'">
                                <flux:field>
                                    <flux:textarea wire:model="privacy_policy_es" rows="12" :placeholder="__('Enter your privacy policy in Spanish...')" />
                                    <flux:description>
                                        {{ str_word_count($privacy_policy_es) }} {{ str_word_count($privacy_policy_es) === 1 ? __('word') : __('words') }}
                                    </flux:description>
                                </flux:field>
                            </div>

                            <div class="flex items-center gap-4">
                                <flux:button type="submit" variant="primary" size="sm">{{ __('Save Privacy Policy') }}</flux:button>
                                <a href="/privacy" target="_blank" class="text-sm text-zinc-500 underline hover:text-zinc-700 dark:hover:text-zinc-300">{{ __('Preview') }}</a>

                                @if (session('privacy_status'))
                                    <flux:text class="text-sm text-emerald-600 dark:text-emerald-400">{{ session('privacy_status') }}</flux:text>
                                @endif
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            {{-- Referral Program Terms --}}
            <div x-data="{ open: true, lang: 'en' }" class="rounded-lg border border-zinc-200 dark:border-zinc-700">
                <button
                    type="button"
                    @click="open = !open"
                    class="flex w-full items-center justify-between px-5 py-4 text-left"
                >
                    <div>
                        <flux:heading size="sm">{{ __('Referral Program Terms') }}</flux:heading>
                        <flux:subheading size="sm">{{ __('Terms agents must accept to participate in referrals.') }}</flux:subheading>
                    </div>
                    <flux:icon.chevron-down class="size-5 text-zinc-400 transition-transform" ::class="open ? 'rotate-180' : ''" />
                </button>

                <div x-show="open" x-collapse>
                    <div class="border-t border-zinc-200 px-5 py-4 dark:border-zinc-700">
                        <form wire:submit="saveReferral" class="space-y-4">
                            {{-- Language Tabs --}}
                            <div class="flex gap-1 rounded-lg bg-zinc-100 p-1 dark:bg-zinc-800">
                                <button type="button" @click="lang = 'en'" :class="lang === 'en' ? 'bg-white shadow dark:bg-zinc-700 dark:text-white' : 'text-zinc-500 dark:text-zinc-400'" class="flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition-colors">
                                    {{ __('English') }}
                                </button>
                                <button type="button" @click="lang = 'es'" :class="lang === 'es' ? 'bg-white shadow dark:bg-zinc-700 dark:text-white' : 'text-zinc-500 dark:text-zinc-400'" class="flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition-colors">
                                    {{ __('Spanish') }}
                                </button>
                            </div>

                            <div x-show="lang === 'en'">
                                <flux:field>
                                    <flux:textarea wire:model="referral_terms" rows="12" :placeholder="__('Enter your referral program terms...')" />
                                    <flux:description>
                                        {{ str_word_count($referral_terms) }} {{ str_word_count($referral_terms) === 1 ? __('word') : __('words') }}
                                    </flux:description>
                                </flux:field>
                            </div>

                            <div x-show="lang === 'es'">
                                <flux:field>
                                    <flux:textarea wire:model="referral_terms_es" rows="12" :placeholder="__('Enter your referral program terms in Spanish...')" />
                                    <flux:description>
                                        {{ str_word_count($referral_terms_es) }} {{ str_word_count($referral_terms_es) === 1 ? __('word') : __('words') }}
                                    </flux:description>
                                </flux:field>
                            </div>

                            <div class="flex items-center gap-4">
                                <flux:button type="submit" variant="primary" size="sm">{{ __('Save Referral Terms') }}</flux:button>
                                <a href="/referral-terms" target="_blank" class="text-sm text-zinc-500 underline hover:text-zinc-700 dark:hover:text-zinc-300">{{ __('Preview') }}</a>

                                @if (session('referral_status'))
                                    <flux:text class="text-sm text-emerald-600 dark:text-emerald-400">{{ session('referral_status') }}</flux:text>
                                @endif
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            {{-- Crew Contracts Section Header --}}
            <div class="pt-4">
                <flux:heading size="lg">{{ __('Crew Contracts') }}</flux:heading>
                <flux:subheading>{{ __('Contracts that crew members must sign during onboarding.') }}</flux:subheading>
            </div>

            {{-- Colorado Contractor Compliance --}}
            <div x-data="{ open: false, lang: 'en' }" class="rounded-lg border border-zinc-200 dark:border-zinc-700">
                <button
                    type="button"
                    @click="open = !open"
                    class="flex w-full items-center justify-between px-5 py-4 text-left"
                >
                    <div>
                        <flux:heading size="sm">{{ __('Colorado Contractor Compliance') }}</flux:heading>
                        <flux:subheading size="sm">{{ __('State-specific independent contractor requirements and legal obligations.') }}</flux:subheading>
                    </div>
                    <flux:icon.chevron-down class="size-5 text-zinc-400 transition-transform" ::class="open ? 'rotate-180' : ''" />
                </button>

                <div x-show="open" x-collapse>
                    <div class="border-t border-zinc-200 px-5 py-4 dark:border-zinc-700">
                        <form wire:submit="saveCrewColorado" class="space-y-4">
                            {{-- Language Tabs --}}
                            <div class="flex gap-1 rounded-lg bg-zinc-100 p-1 dark:bg-zinc-800">
                                <button type="button" @click="lang = 'en'" :class="lang === 'en' ? 'bg-white shadow dark:bg-zinc-700 dark:text-white' : 'text-zinc-500 dark:text-zinc-400'" class="flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition-colors">
                                    {{ __('English') }}
                                </button>
                                <button type="button" @click="lang = 'es'" :class="lang === 'es' ? 'bg-white shadow dark:bg-zinc-700 dark:text-white' : 'text-zinc-500 dark:text-zinc-400'" class="flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition-colors">
                                    {{ __('Spanish') }}
                                </button>
                            </div>

                            <div x-show="lang === 'en'">
                                <flux:field>
                                    <flux:textarea wire:model="crew_colorado_compliance_contract" rows="16" :placeholder="__('Enter the Colorado contractor compliance contract...')" />
                                    <flux:description>
                                        {{ str_word_count($crew_colorado_compliance_contract) }} {{ str_word_count($crew_colorado_compliance_contract) === 1 ? __('word') : __('words') }}
                                    </flux:description>
                                </flux:field>
                            </div>

                            <div x-show="lang === 'es'">
                                <flux:field>
                                    <flux:textarea wire:model="crew_colorado_compliance_contract_es" rows="16" :placeholder="__('Enter the Colorado contractor compliance contract in Spanish...')" />
                                    <flux:description>
                                        {{ str_word_count($crew_colorado_compliance_contract_es) }} {{ str_word_count($crew_colorado_compliance_contract_es) === 1 ? __('word') : __('words') }}
                                    </flux:description>
                                </flux:field>
                            </div>

                            <div class="flex items-center gap-4">
                                <flux:button type="submit" variant="primary" size="sm">{{ __('Save') }}</flux:button>

                                @if (session('crew_colorado_status'))
                                    <flux:text class="text-sm text-emerald-600 dark:text-emerald-400">{{ session('crew_colorado_status') }}</flux:text>
                                @endif
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            {{-- Contractor Agreement --}}
            <div x-data="{ open: false, lang: 'en' }" class="rounded-lg border border-zinc-200 dark:border-zinc-700">
                <button
                    type="button"
                    @click="open = !open"
                    class="flex w-full items-center justify-between px-5 py-4 text-left"
                >
                    <div>
                        <flux:heading size="sm">{{ __('Contractor Agreement') }}</flux:heading>
                        <flux:subheading size="sm">{{ __('Platform usage, conduct expectations, pay structure, and quality standards.') }}</flux:subheading>
                    </div>
                    <flux:icon.chevron-down class="size-5 text-zinc-400 transition-transform" ::class="open ? 'rotate-180' : ''" />
                </button>

                <div x-show="open" x-collapse>
                    <div class="border-t border-zinc-200 px-5 py-4 dark:border-zinc-700">
                        <form wire:submit="saveCrewAgreement" class="space-y-4">
                            {{-- Language Tabs --}}
                            <div class="flex gap-1 rounded-lg bg-zinc-100 p-1 dark:bg-zinc-800">
                                <button type="button" @click="lang = 'en'" :class="lang === 'en' ? 'bg-white shadow dark:bg-zinc-700 dark:text-white' : 'text-zinc-500 dark:text-zinc-400'" class="flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition-colors">
                                    {{ __('English') }}
                                </button>
                                <button type="button" @click="lang = 'es'" :class="lang === 'es' ? 'bg-white shadow dark:bg-zinc-700 dark:text-white' : 'text-zinc-500 dark:text-zinc-400'" class="flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition-colors">
                                    {{ __('Spanish') }}
                                </button>
                            </div>

                            <div x-show="lang === 'en'">
                                <flux:field>
                                    <flux:textarea wire:model="crew_contractor_agreement" rows="16" :placeholder="__('Enter the contractor agreement...')" />
                                    <flux:description>
                                        {{ str_word_count($crew_contractor_agreement) }} {{ str_word_count($crew_contractor_agreement) === 1 ? __('word') : __('words') }}
                                    </flux:description>
                                </flux:field>
                            </div>

                            <div x-show="lang === 'es'">
                                <flux:field>
                                    <flux:textarea wire:model="crew_contractor_agreement_es" rows="16" :placeholder="__('Enter the contractor agreement in Spanish...')" />
                                    <flux:description>
                                        {{ str_word_count($crew_contractor_agreement_es) }} {{ str_word_count($crew_contractor_agreement_es) === 1 ? __('word') : __('words') }}
                                    </flux:description>
                                </flux:field>
                            </div>

                            <div class="flex items-center gap-4">
                                <flux:button type="submit" variant="primary" size="sm">{{ __('Save') }}</flux:button>

                                @if (session('crew_agreement_status'))
                                    <flux:text class="text-sm text-emerald-600 dark:text-emerald-400">{{ session('crew_agreement_status') }}</flux:text>
                                @endif
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            {{-- Background Check Consent --}}
            <div x-data="{ open: false, lang: 'en' }" class="rounded-lg border border-zinc-200 dark:border-zinc-700">
                <button
                    type="button"
                    @click="open = !open"
                    class="flex w-full items-center justify-between px-5 py-4 text-left"
                >
                    <div>
                        <flux:heading size="sm">{{ __('Background Check Consent') }}</flux:heading>
                        <flux:subheading size="sm">{{ __('FCRA-compliant authorization for background investigations.') }}</flux:subheading>
                    </div>
                    <flux:icon.chevron-down class="size-5 text-zinc-400 transition-transform" ::class="open ? 'rotate-180' : ''" />
                </button>

                <div x-show="open" x-collapse>
                    <div class="border-t border-zinc-200 px-5 py-4 dark:border-zinc-700">
                        <form wire:submit="saveCrewBackground" class="space-y-4">
                            {{-- Language Tabs --}}
                            <div class="flex gap-1 rounded-lg bg-zinc-100 p-1 dark:bg-zinc-800">
                                <button type="button" @click="lang = 'en'" :class="lang === 'en' ? 'bg-white shadow dark:bg-zinc-700 dark:text-white' : 'text-zinc-500 dark:text-zinc-400'" class="flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition-colors">
                                    {{ __('English') }}
                                </button>
                                <button type="button" @click="lang = 'es'" :class="lang === 'es' ? 'bg-white shadow dark:bg-zinc-700 dark:text-white' : 'text-zinc-500 dark:text-zinc-400'" class="flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition-colors">
                                    {{ __('Spanish') }}
                                </button>
                            </div>

                            <div x-show="lang === 'en'">
                                <flux:field>
                                    <flux:textarea wire:model="crew_background_check_consent" rows="16" :placeholder="__('Enter the background check consent form...')" />
                                    <flux:description>
                                        {{ str_word_count($crew_background_check_consent) }} {{ str_word_count($crew_background_check_consent) === 1 ? __('word') : __('words') }}
                                    </flux:description>
                                </flux:field>
                            </div>

                            <div x-show="lang === 'es'">
                                <flux:field>
                                    <flux:textarea wire:model="crew_background_check_consent_es" rows="16" :placeholder="__('Enter the background check consent form in Spanish...')" />
                                    <flux:description>
                                        {{ str_word_count($crew_background_check_consent_es) }} {{ str_word_count($crew_background_check_consent_es) === 1 ? __('word') : __('words') }}
                                    </flux:description>
                                </flux:field>
                            </div>

                            <div class="flex items-center gap-4">
                                <flux:button type="submit" variant="primary" size="sm">{{ __('Save') }}</flux:button>

                                @if (session('crew_background_status'))
                                    <flux:text class="text-sm text-emerald-600 dark:text-emerald-400">{{ session('crew_background_status') }}</flux:text>
                                @endif
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
