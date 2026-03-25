# IVR & Voicemail Greeting Customization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix voicemail upload bug, add IVR greeting customization (auto/TTS/upload), and add a TTS voice selector for all phone system voice responses.

**Architecture:** Extend existing CompanySetting singleton with 4 new columns. Update TwilioVoiceService to use dynamic voice and support IVR greeting types. Extend the phone-system settings Livewire SFC with voice selector and IVR greeting UI.

**Tech Stack:** Laravel 12, Livewire 4 SFC, Twilio TwiML, Amazon Polly voices, Pest tests

**Spec:** `docs/superpowers/specs/2026-03-25-ivr-voicemail-greeting-customization-design.md`

---

### Task 1: Migration — add IVR greeting and TTS voice columns

**Files:**
- Create: `database/migrations/2026_03_25_XXXXXX_add_ivr_greeting_and_tts_voice_to_company_settings.php`
- Modify: `app/Models/CompanySetting.php:21-167` (add to $fillable)

- [ ] **Step 1: Create migration**

```bash
php artisan make:migration add_ivr_greeting_and_tts_voice_to_company_settings --table=company_settings --no-interaction
```

- [ ] **Step 2: Write migration up/down**

```php
public function up(): void
{
    Schema::table('company_settings', function (Blueprint $table) {
        $table->string('tts_voice')->default('Polly.Joanna')->after('ivr_menu');
        $table->string('ivr_greeting_type')->default('auto')->after('tts_voice');
        $table->text('ivr_greeting_text')->nullable()->after('ivr_greeting_type');
        $table->string('ivr_greeting_path')->nullable()->after('ivr_greeting_text');
    });
}

public function down(): void
{
    Schema::table('company_settings', function (Blueprint $table) {
        $table->dropColumn(['tts_voice', 'ivr_greeting_type', 'ivr_greeting_text', 'ivr_greeting_path']);
    });
}
```

- [ ] **Step 3: Add columns to CompanySetting $fillable**

In `app/Models/CompanySetting.php`, add after `'ivr_menu',` (line 167):

```php
'tts_voice',
'ivr_greeting_type',
'ivr_greeting_text',
'ivr_greeting_path',
```

- [ ] **Step 4: Run migration**

```bash
php artisan migrate --no-interaction
```

Expected: Migration runs successfully, 4 columns added.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/*add_ivr_greeting_and_tts_voice* app/Models/CompanySetting.php
git commit -m "feat: add IVR greeting and TTS voice columns to company_settings"
```

---

### Task 2: Fix voicemail type mismatch bug

**Files:**
- Modify: `app/Services/TwilioVoiceService.php:90` (change `'audio'` to `'upload'`)
- Modify: `tests/Feature/Voice/TwilioVoiceServiceTest.php:171` (change `'text'` to `'tts'`)

- [ ] **Step 1: Fix existing test to use canonical type value**

In `tests/Feature/Voice/TwilioVoiceServiceTest.php:171`, change:

```php
// Old:
'voicemail_greeting_type' => 'text',
// New:
'voicemail_greeting_type' => 'tts',
```

- [ ] **Step 2: Run test to verify it still passes (it passes by accident via else branch)**

```bash
php artisan test --compact --filter="uses custom greeting text from company setting"
```

Expected: PASS (the else branch handles TTS regardless of type value)

- [ ] **Step 3: Fix the service — change `'audio'` to `'upload'`**

In `app/Services/TwilioVoiceService.php:90`, change:

```php
// Old:
if ($settings->voicemail_greeting_type === 'audio' && $settings->voicemail_greeting_path) {
// New:
if ($settings->voicemail_greeting_type === 'upload' && $settings->voicemail_greeting_path) {
```

- [ ] **Step 4: Write test for upload type playing audio**

Add to `tests/Feature/Voice/TwilioVoiceServiceTest.php` after the custom greeting text test:

```php
it('plays uploaded audio file when greeting type is upload', function () {
    CompanySetting::clearInstanceCache();
    $settings = CompanySetting::getInstance();
    $settings->update([
        'voicemail_greeting_type' => 'upload',
        'voicemail_greeting_path' => 'voicemail-greetings/custom-greeting.mp3',
    ]);
    CompanySetting::clearInstanceCache();

    $callLog = CallLog::factory()->create();

    $service = app(TwilioVoiceService::class);
    $twiml = $service->generateVoicemailTwiml($callLog);

    expect($twiml)->toContain('<Play')
        ->and($twiml)->toContain('custom-greeting.mp3')
        ->and($twiml)->not->toContain('We are unable to take your call');
});
```

- [ ] **Step 5: Run tests**

```bash
php artisan test --compact tests/Feature/Voice/TwilioVoiceServiceTest.php
```

Expected: All pass including new upload test.

- [ ] **Step 6: Commit**

```bash
git add app/Services/TwilioVoiceService.php tests/Feature/Voice/TwilioVoiceServiceTest.php
git commit -m "fix: voicemail upload greeting type mismatch — 'audio' should be 'upload'"
```

---

### Task 3: Dynamic TTS voice in TwilioVoiceService

**Files:**
- Modify: `app/Services/TwilioVoiceService.php` (all 4 hardcoded `Polly.Joanna` references)
- Modify: `app/Http/Controllers/TwilioVoiceWebhookController.php:718` (whisper voice)
- Modify: `tests/Feature/Voice/WhisperTest.php:25` (update assertion)
- Modify: `tests/Feature/Voice/TwilioVoiceServiceTest.php` (add voice selection test)

- [ ] **Step 1: Write test for dynamic voice in voicemail TwiML**

Add to `tests/Feature/Voice/TwilioVoiceServiceTest.php`:

```php
it('uses configured tts voice in voicemail greeting', function () {
    CompanySetting::clearInstanceCache();
    $settings = CompanySetting::getInstance();
    $settings->update([
        'tts_voice' => 'Polly.Matthew',
        'voicemail_greeting_type' => 'tts',
        'voicemail_greeting_text' => 'Hello from test',
    ]);
    CompanySetting::clearInstanceCache();

    $callLog = CallLog::factory()->create();

    $service = app(TwilioVoiceService::class);
    $twiml = $service->generateVoicemailTwiml($callLog);

    expect($twiml)->toContain('Polly.Matthew')
        ->and($twiml)->not->toContain('Polly.Joanna');
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter="uses configured tts voice in voicemail greeting"
```

Expected: FAIL (voice is still hardcoded to Polly.Joanna)

- [ ] **Step 3: Add helper method to TwilioVoiceService for getting voice**

In `app/Services/TwilioVoiceService.php`, add a private helper method before `generateRingGroupTwiml`:

```php
/**
 * Get the configured TTS voice from company settings.
 */
private function getTtsVoice(?CompanySetting $settings = null): string
{
    $settings ??= CompanySetting::getInstance();

    return $settings->tts_voice ?? 'Polly.Joanna';
}
```

- [ ] **Step 4: Replace all 4 hardcoded `'Polly.Joanna'` in TwilioVoiceService**

In `generateVoicemailTwiml()` (2 occurrences):

```php
// Line ~95 — TTS greeting
$response->say($greetingText, ['voice' => $this->getTtsVoice($settings)]);

// Line ~120 — "We did not receive a recording" fallback
$response->say('We did not receive a recording. Goodbye.', ['voice' => $this->getTtsVoice($settings)]);
```

In `generateIvrMenuTwiml()` (2 occurrences):

```php
// Line ~172 — IVR menu text
$gather->say($menuText, ['voice' => $this->getTtsVoice($settings)]);

// Line ~175 — "We did not receive your selection" fallback
$response->say('We did not receive your selection.', ['voice' => $this->getTtsVoice($settings)]);
```

- [ ] **Step 5: Replace hardcoded voice in TwilioVoiceWebhookController whisper**

In `app/Http/Controllers/TwilioVoiceWebhookController.php:718`, change:

```php
// Old:
$response->say("{$callType} from {$callerName}.", ['voice' => 'Polly.Joanna']);
// New:
$settings = CompanySetting::getInstance();
$response->say("{$callType} from {$callerName}.", ['voice' => $settings->tts_voice ?? 'Polly.Joanna']);
```

- [ ] **Step 6: Update WhisperTest assertion**

In `tests/Feature/Voice/WhisperTest.php:25`, change:

```php
// Old:
->and($response->getContent())->toContain('Polly.Joanna');
// New:
->and($response->getContent())->toContain('voice=');
```

This makes the test verify a voice is present without hardcoding which one (default is still Polly.Joanna but we don't want the test to break if someone changes the default).

- [ ] **Step 7: Run all voice tests**

```bash
php artisan test --compact tests/Feature/Voice/TwilioVoiceServiceTest.php tests/Feature/Voice/WhisperTest.php
```

Expected: All pass.

- [ ] **Step 8: Commit**

```bash
git add app/Services/TwilioVoiceService.php app/Http/Controllers/TwilioVoiceWebhookController.php tests/Feature/Voice/TwilioVoiceServiceTest.php tests/Feature/Voice/WhisperTest.php
git commit -m "feat: use dynamic TTS voice from company settings instead of hardcoded Polly.Joanna"
```

---

### Task 4: IVR greeting type logic in TwilioVoiceService

**Files:**
- Modify: `app/Services/TwilioVoiceService.php` (`generateIvrMenuTwiml` method, lines 129-181)
- Modify: `tests/Feature/Voice/IvrHandlingTest.php` (add tests for greeting types)

- [ ] **Step 1: Write tests for IVR greeting types**

Add to `tests/Feature/Voice/IvrHandlingTest.php` inside `describe('generateIvrMenuTwiml', ...)` block, after the existing tests:

```php
it('uses custom TTS greeting when ivr_greeting_type is tts', function () {
    $settings = CompanySetting::getInstance();
    $settings->update([
        'ivr_enabled' => true,
        'ivr_greeting_type' => 'tts',
        'ivr_greeting_text' => 'Welcome to Painting Made Easy. For estimates press one, for scheduling press two.',
        'ivr_menu' => [
            'options' => [
                ['digit' => '1', 'label' => 'Estimates', 'call_group_id' => 1],
            ],
            'extension_prompt' => false,
            'timeout_action' => 'ring_default',
        ],
    ]);
    CompanySetting::clearInstanceCache();

    $callLog = CallLog::factory()->create();
    $service = app(TwilioVoiceService::class);
    $twiml = $service->generateIvrMenuTwiml($callLog);

    expect($twiml)->toContain('Welcome to Painting Made Easy')
        ->and($twiml)->not->toContain('Press 1 for Estimates');
});

it('plays uploaded audio when ivr_greeting_type is upload', function () {
    $settings = CompanySetting::getInstance();
    $settings->update([
        'ivr_enabled' => true,
        'ivr_greeting_type' => 'upload',
        'ivr_greeting_path' => 'ivr-greetings/custom-ivr.mp3',
        'ivr_menu' => [
            'options' => [
                ['digit' => '1', 'label' => 'Estimates', 'call_group_id' => 1],
            ],
            'extension_prompt' => false,
            'timeout_action' => 'ring_default',
        ],
    ]);
    CompanySetting::clearInstanceCache();

    $callLog = CallLog::factory()->create();
    $service = app(TwilioVoiceService::class);
    $twiml = $service->generateIvrMenuTwiml($callLog);

    expect($twiml)->toContain('<Play')
        ->and($twiml)->toContain('custom-ivr.mp3')
        ->and($twiml)->not->toContain('Press 1 for');
});

it('falls back to auto-generated greeting when tts type has empty text', function () {
    $settings = CompanySetting::getInstance();
    $settings->update([
        'ivr_enabled' => true,
        'ivr_greeting_type' => 'tts',
        'ivr_greeting_text' => '',
        'ivr_menu' => [
            'options' => [
                ['digit' => '1', 'label' => 'Estimates', 'call_group_id' => 1],
            ],
            'extension_prompt' => false,
            'timeout_action' => 'ring_default',
        ],
    ]);
    CompanySetting::clearInstanceCache();

    $callLog = CallLog::factory()->create();
    $service = app(TwilioVoiceService::class);
    $twiml = $service->generateIvrMenuTwiml($callLog);

    expect($twiml)->toContain('Press 1 for Estimates');
});

it('falls back to auto-generated greeting when upload type has no file', function () {
    $settings = CompanySetting::getInstance();
    $settings->update([
        'ivr_enabled' => true,
        'ivr_greeting_type' => 'upload',
        'ivr_greeting_path' => null,
        'ivr_menu' => [
            'options' => [
                ['digit' => '1', 'label' => 'Estimates', 'call_group_id' => 1],
            ],
            'extension_prompt' => false,
            'timeout_action' => 'ring_default',
        ],
    ]);
    CompanySetting::clearInstanceCache();

    $callLog = CallLog::factory()->create();
    $service = app(TwilioVoiceService::class);
    $twiml = $service->generateIvrMenuTwiml($callLog);

    expect($twiml)->toContain('Press 1 for Estimates');
});

it('uses configured tts voice for IVR greeting', function () {
    $settings = CompanySetting::getInstance();
    $settings->update([
        'ivr_enabled' => true,
        'tts_voice' => 'Polly.Stephen',
        'ivr_greeting_type' => 'auto',
        'ivr_menu' => [
            'options' => [
                ['digit' => '1', 'label' => 'Sales', 'call_group_id' => 1],
            ],
            'extension_prompt' => false,
            'timeout_action' => 'ring_default',
        ],
    ]);
    CompanySetting::clearInstanceCache();

    $callLog = CallLog::factory()->create();
    $service = app(TwilioVoiceService::class);
    $twiml = $service->generateIvrMenuTwiml($callLog);

    expect($twiml)->toContain('Polly.Stephen')
        ->and($twiml)->not->toContain('Polly.Joanna');
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact --filter="uses custom TTS greeting|plays uploaded audio when ivr|falls back to auto-generated|uses configured tts voice for IVR"
```

Expected: FAIL (greeting type logic not implemented yet)

- [ ] **Step 3: Implement IVR greeting type logic**

Replace the greeting section in `generateIvrMenuTwiml()` (lines ~158-172). The full updated method body:

```php
public function generateIvrMenuTwiml(CallLog $callLog): string
{
    $response = new VoiceResponse;
    $settings = CompanySetting::getInstance();
    $menu = $settings->ivr_menu ?? [];
    $options = $menu['options'] ?? [];
    $extensionPrompt = $menu['extension_prompt'] ?? false;
    $voice = $this->getTtsVoice($settings);

    $gatherOptions = [
        'input' => 'dtmf',
        'timeout' => 5,
    ];

    if ($extensionPrompt) {
        $gatherOptions['finishOnKey'] = '#';
    } else {
        $gatherOptions['numDigits'] = 1;
    }

    try {
        $gatherOptions['action'] = route('webhooks.twilio.voice.ivr-select', [
            'callLog' => $callLog->ulid,
        ]);
    } catch (\Throwable) {
        // Route may not exist yet
    }

    $gather = $response->gather($gatherOptions);

    // Determine greeting based on ivr_greeting_type
    $greetingType = $settings->ivr_greeting_type ?? 'auto';

    if ($greetingType === 'upload' && ! empty($settings->ivr_greeting_path)) {
        $gather->play(storage_url($settings->ivr_greeting_path));
    } elseif ($greetingType === 'tts' && ! empty($settings->ivr_greeting_text)) {
        $gather->say($settings->ivr_greeting_text, ['voice' => $voice]);
    } else {
        // Auto-generate from menu options (default/fallback)
        $menuText = 'Thank you for calling. ';
        foreach ($options as $option) {
            $digit = $option['digit'] ?? '';
            $label = $option['label'] ?? "Option {$digit}";
            $menuText .= "Press {$digit} for {$label}. ";
        }

        if ($extensionPrompt) {
            $menuText .= 'Or dial your party\'s extension at any time.';
        }

        $gather->say($menuText, ['voice' => $voice]);
    }

    // No input fallback
    $response->say('We did not receive your selection.', ['voice' => $voice]);
    $response->redirect(route('webhooks.twilio.voice.voicemail-fallback', [
        'callLog' => $callLog->ulid,
    ]));

    return $response->__toString();
}
```

- [ ] **Step 4: Run all IVR tests**

```bash
php artisan test --compact tests/Feature/Voice/IvrHandlingTest.php
```

Expected: All pass.

- [ ] **Step 5: Commit**

```bash
git add app/Services/TwilioVoiceService.php tests/Feature/Voice/IvrHandlingTest.php
git commit -m "feat: add IVR greeting type support — auto, custom TTS, or uploaded audio"
```

---

### Task 5: Phone settings UI — voice selector and IVR greeting controls

**Files:**
- Modify: `resources/views/pages/settings/⚡phone-system.blade.php` (PHP properties, mount, saveGeneral, saveIvr, and template sections)
- Modify: `tests/Feature/Voice/PhoneSystemSettingsTest.php` (add tests)

- [ ] **Step 1: Write tests for new settings fields**

Add to `tests/Feature/Voice/PhoneSystemSettingsTest.php`:

After the general settings tests section:

```php
it('can update TTS voice in general settings', function () {
    Livewire::test('pages::settings.phone-system')
        ->set('ttsVoice', 'Polly.Matthew')
        ->call('saveGeneral')
        ->assertDispatched('general-saved');

    CompanySetting::clearInstanceCache();
    expect(CompanySetting::getInstance()->tts_voice)->toBe('Polly.Matthew');
});

it('validates TTS voice must be a valid option', function () {
    Livewire::test('pages::settings.phone-system')
        ->set('ttsVoice', 'InvalidVoice')
        ->call('saveGeneral')
        ->assertHasErrors(['ttsVoice']);
});
```

After the IVR settings tests section:

```php
it('can save IVR greeting type as tts with custom text', function () {
    Livewire::test('pages::settings.phone-system')
        ->set('ivrEnabled', true)
        ->set('ivrGreetingType', 'tts')
        ->set('ivrGreetingText', 'Welcome to our company.')
        ->call('saveIvr')
        ->assertDispatched('ivr-saved');

    CompanySetting::clearInstanceCache();
    $settings = CompanySetting::getInstance();
    expect($settings->ivr_greeting_type)->toBe('tts')
        ->and($settings->ivr_greeting_text)->toBe('Welcome to our company.');
});

it('can save IVR greeting type as auto', function () {
    Livewire::test('pages::settings.phone-system')
        ->set('ivrEnabled', true)
        ->set('ivrGreetingType', 'auto')
        ->call('saveIvr')
        ->assertDispatched('ivr-saved');

    CompanySetting::clearInstanceCache();
    expect(CompanySetting::getInstance()->ivr_greeting_type)->toBe('auto');
});

it('validates IVR greeting type must be valid option', function () {
    Livewire::test('pages::settings.phone-system')
        ->set('ivrGreetingType', 'invalid')
        ->call('saveIvr')
        ->assertHasErrors(['ivrGreetingType']);
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact --filter="can update TTS voice|validates TTS voice|can save IVR greeting type|validates IVR greeting type"
```

Expected: FAIL (properties don't exist yet)

- [ ] **Step 3: Add Livewire properties to the PHP block**

In `resources/views/pages/settings/⚡phone-system.blade.php`, add after `public bool $callRecordingEnabled = false;` (line 28):

```php
public string $ttsVoice = 'Polly.Joanna';
```

Add after `public string $timeoutAction = 'ring_default';` (line 53):

```php
// IVR Greeting
public string $ivrGreetingType = 'auto';
public string $ivrGreetingText = '';
public $ivrGreetingFile = null;
public ?string $existingIvrGreetingPath = null;
```

- [ ] **Step 4: Update mount() to load new settings**

In `mount()`, add after `$this->callRecordingEnabled = ...` (line 78):

```php
$this->ttsVoice = $settings->tts_voice ?? 'Polly.Joanna';
```

Add after `$this->timeoutAction = ...` (line 98):

```php
// IVR Greeting
$this->ivrGreetingType = $settings->ivr_greeting_type ?? 'auto';
$this->ivrGreetingText = $settings->ivr_greeting_text ?? '';
$this->existingIvrGreetingPath = $settings->ivr_greeting_path;
```

- [ ] **Step 5: Update saveGeneral() to include tts_voice**

In `saveGeneral()`, add validation and save. Replace the method body:

```php
public function saveGeneral(): void
{
    $this->ensureInternalUser();

    $this->validate([
        'ttsVoice' => ['required', 'string', 'in:Polly.Joanna,Polly.Matthew,Polly.Salli,Polly.Kendra,Polly.Joey,Polly.Ruth,Polly.Stephen'],
    ]);

    CompanySetting::getInstance()->update([
        'phone_system_enabled' => $this->phoneSystemEnabled,
        'phone_routing_mode' => $this->phoneRoutingMode,
        'call_recording_enabled' => $this->callRecordingEnabled,
        'tts_voice' => $this->ttsVoice,
    ]);

    $this->dispatch('general-saved');
}
```

- [ ] **Step 6: Update saveIvr() to include greeting fields**

Replace the `saveIvr()` method:

```php
public function saveIvr(): void
{
    $this->ensureInternalUser();

    $this->validate([
        'ivrEnabled' => ['boolean'],
        'ivrGreetingType' => ['required', 'string', 'in:auto,tts,upload'],
        'ivrGreetingText' => ['nullable', 'string', 'max:1000'],
        'ivrGreetingFile' => ['nullable', 'file', 'mimes:mp3,wav,ogg,m4a', 'max:10240'],
        'ivrOptions' => ['array'],
        'ivrOptions.*.digit' => ['required', 'string', 'size:1'],
        'ivrOptions.*.label' => ['required', 'string', 'max:100'],
        'ivrOptions.*.action' => ['required', 'string', 'in:route_to_group,voicemail'],
        'ivrOptions.*.call_group_id' => ['nullable', 'exists:call_groups,id'],
        'timeoutAction' => ['required', 'in:ring_default,voicemail'],
    ]);

    $data = [
        'ivr_enabled' => $this->ivrEnabled,
        'ivr_greeting_type' => $this->ivrGreetingType,
        'ivr_greeting_text' => $this->ivrGreetingText ?: null,
        'ivr_menu' => [
            'options' => $this->ivrOptions,
            'timeout_action' => $this->timeoutAction,
        ],
    ];

    if ($this->ivrGreetingFile) {
        $path = $this->ivrGreetingFile->store('ivr-greetings', 'public');
        $data['ivr_greeting_path'] = $path;
        $this->existingIvrGreetingPath = $path;
    }

    CompanySetting::getInstance()->update($data);
    $this->ivrGreetingFile = null;

    $this->dispatch('ivr-saved');
}
```

- [ ] **Step 7: Add voice selector UI to General Settings section**

In the template, inside `{{-- Section 1: General Settings --}}`, add after the call recording switch (after line 504, before the closing `</div>`):

```blade
<flux:select wire:model="ttsVoice" :label="__('TTS Voice')" :description="__('Voice used for all text-to-speech (voicemail, IVR, call announcements)')">
    <flux:select.option value="Polly.Joanna">{{ __('Joanna — Female (Standard)') }}</flux:select.option>
    <flux:select.option value="Polly.Matthew">{{ __('Matthew — Male (Standard)') }}</flux:select.option>
    <flux:select.option value="Polly.Salli">{{ __('Salli — Female (Standard)') }}</flux:select.option>
    <flux:select.option value="Polly.Kendra">{{ __('Kendra — Female (Standard)') }}</flux:select.option>
    <flux:select.option value="Polly.Joey">{{ __('Joey — Male (Standard)') }}</flux:select.option>
    <flux:select.option value="Polly.Ruth">{{ __('Ruth — Female (Neural)') }}</flux:select.option>
    <flux:select.option value="Polly.Stephen">{{ __('Stephen — Male (Neural)') }}</flux:select.option>
</flux:select>
```

- [ ] **Step 8: Add IVR greeting UI to IVR section**

In the template, inside `{{-- Section 4: IVR Menu --}}`, add after the IVR enabled switch and before the `@if ($ivrEnabled)` options block. Replace the `@if ($ivrEnabled)` block content to add greeting controls at the top:

After the `<flux:switch wire:model.live="ivrEnabled" ...>` line and before the existing IVR options loop, add inside the `@if ($ivrEnabled)` block:

```blade
<flux:separator class="my-3" />

<flux:select wire:model.live="ivrGreetingType" :label="__('Greeting Type')">
    <flux:select.option value="auto">{{ __('Auto-generate from menu options') }}</flux:select.option>
    <flux:select.option value="tts">{{ __('Custom Text-to-Speech') }}</flux:select.option>
    <flux:select.option value="upload">{{ __('Upload Audio File') }}</flux:select.option>
</flux:select>

@if ($ivrGreetingType === 'tts')
    <flux:textarea wire:model="ivrGreetingText" :label="__('IVR Greeting Message')" :placeholder="__('Welcome to Painting Made Easy. For estimates, press 1. For scheduling, press 2. To leave a voicemail, press 9.')" rows="3" />
@elseif ($ivrGreetingType === 'upload')
    <flux:field>
        <flux:label>{{ __('Upload IVR Greeting') }}</flux:label>
        <input type="file" wire:model="ivrGreetingFile" accept=".mp3,.wav,.ogg,.m4a" class="block w-full text-sm text-zinc-500 file:mr-4 file:rounded file:border-0 file:bg-zinc-100 file:px-4 file:py-2 file:text-sm file:font-medium hover:file:bg-zinc-200 dark:text-zinc-400 dark:file:bg-zinc-700 dark:hover:file:bg-zinc-600" />
        @if ($existingIvrGreetingPath)
            <flux:text class="mt-1 text-xs text-zinc-500">{{ __('Current file') }}: {{ basename($existingIvrGreetingPath) }}</flux:text>
        @endif
        @error('ivrGreetingFile')
            <flux:text class="mt-1 text-xs text-red-500">{{ $message }}</flux:text>
        @enderror
    </flux:field>
@endif

<flux:separator class="my-3" />
```

- [ ] **Step 9: Run all phone system settings tests**

```bash
php artisan test --compact tests/Feature/Voice/PhoneSystemSettingsTest.php
```

Expected: All pass.

- [ ] **Step 10: Commit**

```bash
git add "resources/views/pages/settings/⚡phone-system.blade.php" tests/Feature/Voice/PhoneSystemSettingsTest.php
git commit -m "feat: add TTS voice selector and IVR greeting customization UI"
```

---

### Task 6: Final integration test run and cleanup

**Files:**
- Read-only verification of all voice tests

- [ ] **Step 1: Run all voice tests**

```bash
php artisan test --compact tests/Feature/Voice/
```

Expected: All pass.

- [ ] **Step 2: Run Pint formatter**

```bash
vendor/bin/pint --dirty
```

- [ ] **Step 3: Commit any formatting fixes**

```bash
git add -A && git diff --cached --quiet || git commit -m "style: format code with Pint"
```

- [ ] **Step 4: Run full test suite (if user agrees)**

```bash
php artisan test --compact
```

Expected: All existing tests still pass.
