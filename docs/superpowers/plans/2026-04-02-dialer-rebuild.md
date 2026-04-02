# Dialer Rebuild — Persistent Conference Architecture

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild the Prospector power dialer around a persistent conference model — agent connects once, contacts are dialed in/out via Participants API, with campaign-driven lists, call rules, timezone filtering, and auto-dial.

**Architecture:** Single Twilio account with logical company separation. Agent's WebRTC connects to one conference at session start and stays connected. Auto-dial loop picks next callable contact, dials them into the agent's conference via Participants API. Campaign call rules (max/day, spacing, exhaustion) govern the queue. Timezone filtering ensures TCPA compliance.

**Tech Stack:** Laravel 12, Livewire 4, Twilio Voice SDK (WebRTC), Twilio REST API (Participants), Alpine.js, Echo/Reverb

**Design Spec:** `docs/superpowers/specs/2026-04-02-dialer-rebuild-design.md`

---

## File Map

| File | Action | Responsibility |
|------|--------|----------------|
| `database/migrations/2026_04_02_000001_add_call_rules_to_campaigns.php` | Create | Campaign call rule columns |
| `database/migrations/2026_04_02_000002_add_tracking_to_campaign_contact.php` | Create | CampaignContact daily/lifetime tracking columns |
| `database/migrations/2026_04_02_000003_add_conference_to_dialer_sessions.php` | Create | Persistent conference name on session |
| `config/prospector-timezones.php` | Create | Area code → timezone lookup (~400 entries) |
| `app/Models/Prospector/Campaign.php` | Modify | Add call rule accessors, nextCampaign relation |
| `app/Models/Prospector/CampaignContact.php` | Modify | Add tracking columns to fillable, new methods |
| `app/Models/Prospector/DialerSession.php` | Modify | Add conference_name column |
| `app/Models/Prospector/Contact.php` | Modify | Auto-populate timezone from area code on boot |
| `app/Services/Prospector/CampaignQueueService.php` | Create | Campaign-driven queue with call rules + timezone filter |
| `app/Services/Prospector/DialerOrchestrationService.php` | Create | Persistent conference session management + auto-dial |
| `app/Console/Commands/ResetDailyCallCounts.php` | Create | Scheduler: reset calls_today at midnight |
| `resources/js/dialer-session.js` | Create | Auto-dial state machine, session orchestration |
| `resources/js/softphone.js` | Modify | Minor: ensure persistent connection support |
| `resources/views/pages/prospector/⚡dialer.blade.php` | Rebuild | New UI: campaign selector, horizontal queue, session controls |
| `app/Http/Controllers/Prospector/TwilioVoiceController.php` | Simplify | handleIncoming stays simple, routeToAgent uses persistent conference |
| `tests/Feature/Prospector/CampaignQueueServiceTest.php` | Create | Queue building, filtering, priority, exhaustion tests |
| `tests/Feature/Prospector/DialerOrchestrationServiceTest.php` | Create | Session lifecycle, dial flow, disposition tests |
| `tests/Feature/Prospector/DailyCallResetTest.php` | Create | Scheduler command test |

---

### Task 1: Database Migrations — Campaign Call Rules

**Files:**
- Create: `database/migrations/2026_04_02_000001_add_call_rules_to_campaigns.php`
- Create: `database/migrations/2026_04_02_000002_add_tracking_to_campaign_contact.php`
- Create: `database/migrations/2026_04_02_000003_add_conference_to_dialer_sessions.php`

- [ ] **Step 1: Create campaign call rules migration**

```bash
php artisan make:migration add_call_rules_to_campaigns --table=prospector_campaigns --no-interaction
```

Edit the migration:

```php
public function up(): void
{
    Schema::table('prospector_campaigns', function (Blueprint $table) {
        $table->unsignedInteger('max_calls_per_day')->default(3)->after('auto_vm_drop');
        $table->unsignedInteger('max_total_attempts')->default(90)->after('max_calls_per_day');
        $table->unsignedInteger('max_days_in_campaign')->default(30)->after('max_total_attempts');
        $table->unsignedInteger('min_minutes_between')->default(240)->after('max_days_in_campaign');
        $table->foreignId('next_campaign_id')->nullable()->after('min_minutes_between')
            ->constrained('prospector_campaigns')->nullOnDelete();
        $table->time('calling_hours_start')->nullable()->after('next_campaign_id');
        $table->time('calling_hours_end')->nullable()->after('calling_hours_start');
        $table->boolean('auto_dial')->default(true)->after('calling_hours_end');
    });
}

public function down(): void
{
    Schema::table('prospector_campaigns', function (Blueprint $table) {
        $table->dropForeign(['next_campaign_id']);
        $table->dropColumn([
            'max_calls_per_day', 'max_total_attempts', 'max_days_in_campaign',
            'min_minutes_between', 'next_campaign_id',
            'calling_hours_start', 'calling_hours_end', 'auto_dial',
        ]);
    });
}
```

- [ ] **Step 2: Create campaign_contact tracking migration**

```bash
php artisan make:migration add_tracking_to_campaign_contact --table=prospector_campaign_contact --no-interaction
```

Edit the migration:

```php
public function up(): void
{
    Schema::table('prospector_campaign_contact', function (Blueprint $table) {
        $table->unsignedInteger('calls_today')->default(0)->after('attempts');
        $table->unsignedInteger('total_attempts')->default(0)->after('calls_today');
        $table->timestamp('entered_at')->useCurrent()->after('total_attempts');
        $table->timestamp('last_called_at')->nullable()->after('entered_at');
        $table->timestamp('next_callable_at')->nullable()->after('last_called_at');
        $table->timestamp('exhausted_at')->nullable()->after('next_callable_at');

        $table->index(['campaign_id', 'status', 'calls_today', 'next_callable_at'], 'cc_queue_idx');
    });
}

public function down(): void
{
    Schema::table('prospector_campaign_contact', function (Blueprint $table) {
        $table->dropIndex('cc_queue_idx');
        $table->dropColumn([
            'calls_today', 'total_attempts', 'entered_at',
            'last_called_at', 'next_callable_at', 'exhausted_at',
        ]);
    });
}
```

- [ ] **Step 3: Create dialer_sessions conference migration**

```bash
php artisan make:migration add_conference_to_dialer_sessions --table=prospector_dialer_sessions --no-interaction
```

Edit the migration:

```php
public function up(): void
{
    Schema::table('prospector_dialer_sessions', function (Blueprint $table) {
        $table->string('conference_name')->nullable()->after('current_batch_id');
    });
}

public function down(): void
{
    Schema::table('prospector_dialer_sessions', function (Blueprint $table) {
        $table->dropColumn('conference_name');
    });
}
```

- [ ] **Step 4: Run migrations**

```bash
php artisan migrate --no-interaction
```

Expected: All 3 migrations run successfully.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/
git commit -m "feat(prospector): add call rules, tracking, and conference columns for dialer rebuild"
```

---

### Task 2: Area Code Timezone Lookup + Contact Auto-Population

**Files:**
- Create: `config/prospector-timezones.php`
- Modify: `app/Models/Prospector/Contact.php`
- Create: `tests/Feature/Prospector/ContactTimezoneTest.php`

- [ ] **Step 1: Write the failing test**

```bash
php artisan make:test Prospector/ContactTimezoneTest --pest --no-interaction
```

```php
<?php

use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;

it('auto-populates timezone from area code on creation', function () {
    $company = Company::factory()->create();
    $contact = Contact::create([
        'company_id' => $company->id,
        'phone' => '+13035551234',
        'first_name' => 'Test',
    ]);

    expect($contact->timezone)->toBe('America/Denver');
});

it('does not overwrite existing timezone', function () {
    $company = Company::factory()->create();
    $contact = Contact::create([
        'company_id' => $company->id,
        'phone' => '+13035551234',
        'first_name' => 'Test',
        'timezone' => 'America/New_York',
    ]);

    expect($contact->timezone)->toBe('America/New_York');
});

it('handles unknown area codes gracefully', function () {
    $company = Company::factory()->create();
    $contact = Contact::create([
        'company_id' => $company->id,
        'phone' => '+19995551234',
        'first_name' => 'Test',
    ]);

    expect($contact->timezone)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --filter=ContactTimezoneTest --compact
```

Expected: FAIL — timezone not auto-populated yet.

- [ ] **Step 3: Create the timezone lookup config**

Create `config/prospector-timezones.php` with area code → timezone mapping. This is a partial list — include the most common US area codes:

```php
<?php

/**
 * US area code → IANA timezone mapping.
 * Used to auto-populate Contact.timezone on creation.
 * Source: NANPA area code assignments.
 */
return [
    // Colorado
    '303' => 'America/Denver',
    '719' => 'America/Denver',
    '720' => 'America/Denver',
    '970' => 'America/Denver',

    // Eastern
    '201' => 'America/New_York', // NJ
    '202' => 'America/New_York', // DC
    '203' => 'America/New_York', // CT
    '212' => 'America/New_York', // NY
    '215' => 'America/New_York', // PA
    '216' => 'America/New_York', // OH
    '224' => 'America/Chicago',  // IL
    '225' => 'America/Chicago',  // LA
    '229' => 'America/New_York', // GA
    '231' => 'America/New_York', // MI
    '234' => 'America/New_York', // OH
    '239' => 'America/New_York', // FL
    '240' => 'America/New_York', // MD
    '248' => 'America/New_York', // MI
    '252' => 'America/New_York', // NC
    '253' => 'America/Los_Angeles', // WA
    '254' => 'America/Chicago',  // TX
    '256' => 'America/Chicago',  // AL
    '260' => 'America/New_York', // IN
    '262' => 'America/Chicago',  // WI
    '267' => 'America/New_York', // PA
    '269' => 'America/New_York', // MI
    '270' => 'America/New_York', // KY
    '272' => 'America/New_York', // PA
    '276' => 'America/New_York', // VA
    '281' => 'America/Chicago',  // TX

    // Central
    '210' => 'America/Chicago',  // TX
    '214' => 'America/Chicago',  // TX
    '217' => 'America/Chicago',  // IL
    '218' => 'America/Chicago',  // MN
    '219' => 'America/Chicago',  // IN
    '228' => 'America/Chicago',  // MS
    '312' => 'America/Chicago',  // IL
    '314' => 'America/Chicago',  // MO
    '316' => 'America/Chicago',  // KS
    '317' => 'America/New_York', // IN
    '318' => 'America/Chicago',  // LA
    '319' => 'America/Chicago',  // IA
    '320' => 'America/Chicago',  // MN

    // Mountain
    '385' => 'America/Denver',   // UT
    '406' => 'America/Denver',   // MT
    '435' => 'America/Denver',   // UT
    '480' => 'America/Phoenix',  // AZ
    '505' => 'America/Denver',   // NM
    '520' => 'America/Phoenix',  // AZ
    '602' => 'America/Phoenix',  // AZ
    '623' => 'America/Phoenix',  // AZ
    '928' => 'America/Phoenix',  // AZ

    // Pacific
    '206' => 'America/Los_Angeles', // WA
    '209' => 'America/Los_Angeles', // CA
    '213' => 'America/Los_Angeles', // CA
    '310' => 'America/Los_Angeles', // CA
    '323' => 'America/Los_Angeles', // CA
    '360' => 'America/Los_Angeles', // WA
    '408' => 'America/Los_Angeles', // CA
    '415' => 'America/Los_Angeles', // CA
    '425' => 'America/Los_Angeles', // WA
    '503' => 'America/Los_Angeles', // OR
    '509' => 'America/Los_Angeles', // WA
    '510' => 'America/Los_Angeles', // CA
    '530' => 'America/Los_Angeles', // CA
    '541' => 'America/Los_Angeles', // OR
    '559' => 'America/Los_Angeles', // CA
    '562' => 'America/Los_Angeles', // CA
    '619' => 'America/Los_Angeles', // CA
    '626' => 'America/Los_Angeles', // CA
    '650' => 'America/Los_Angeles', // CA
    '661' => 'America/Los_Angeles', // CA
    '702' => 'America/Los_Angeles', // NV
    '707' => 'America/Los_Angeles', // CA
    '714' => 'America/Los_Angeles', // CA
    '775' => 'America/Los_Angeles', // NV
    '805' => 'America/Los_Angeles', // CA
    '808' => 'Pacific/Honolulu',    // HI
    '818' => 'America/Los_Angeles', // CA
    '831' => 'America/Los_Angeles', // CA
    '858' => 'America/Los_Angeles', // CA
    '907' => 'America/Anchorage',   // AK
    '916' => 'America/Los_Angeles', // CA
    '925' => 'America/Los_Angeles', // CA
    '949' => 'America/Los_Angeles', // CA
    '951' => 'America/Los_Angeles', // CA
    '971' => 'America/Los_Angeles', // OR

    // More Eastern
    '301' => 'America/New_York', // MD
    '302' => 'America/New_York', // DE
    '304' => 'America/New_York', // WV
    '305' => 'America/New_York', // FL
    '307' => 'America/Denver',   // WY
    '308' => 'America/Chicago',  // NE
    '309' => 'America/Chicago',  // IL
    '313' => 'America/New_York', // MI
    '321' => 'America/New_York', // FL
    '330' => 'America/New_York', // OH
    '331' => 'America/Chicago',  // IL
    '332' => 'America/New_York', // NY
    '334' => 'America/Chicago',  // AL
    '336' => 'America/New_York', // NC
    '337' => 'America/Chicago',  // LA
    '339' => 'America/New_York', // MA
    '340' => 'America/Virgin',   // VI
    '346' => 'America/Chicago',  // TX
    '347' => 'America/New_York', // NY
    '351' => 'America/New_York', // MA
    '352' => 'America/New_York', // FL
    '361' => 'America/Chicago',  // TX
    '364' => 'America/New_York', // KY
    '380' => 'America/New_York', // OH
    '386' => 'America/New_York', // FL

    // More common
    '401' => 'America/New_York', // RI
    '402' => 'America/Chicago',  // NE
    '404' => 'America/New_York', // GA
    '405' => 'America/Chicago',  // OK
    '407' => 'America/New_York', // FL
    '409' => 'America/Chicago',  // TX
    '410' => 'America/New_York', // MD
    '412' => 'America/New_York', // PA
    '413' => 'America/New_York', // MA
    '414' => 'America/Chicago',  // WI
    '417' => 'America/Chicago',  // MO
    '419' => 'America/New_York', // OH
    '423' => 'America/New_York', // TN
    '424' => 'America/Los_Angeles', // CA
    '432' => 'America/Chicago',  // TX
    '434' => 'America/New_York', // VA
    '440' => 'America/New_York', // OH
    '442' => 'America/Los_Angeles', // CA
    '443' => 'America/New_York', // MD
    '469' => 'America/Chicago',  // TX
    '470' => 'America/New_York', // GA
    '478' => 'America/New_York', // GA
    '484' => 'America/New_York', // PA
    '501' => 'America/Chicago',  // AR
    '502' => 'America/New_York', // KY
    '504' => 'America/Chicago',  // LA
    '507' => 'America/Chicago',  // MN
    '508' => 'America/New_York', // MA
    '512' => 'America/Chicago',  // TX
    '513' => 'America/New_York', // OH
    '515' => 'America/Chicago',  // IA
    '516' => 'America/New_York', // NY
    '517' => 'America/New_York', // MI
    '518' => 'America/New_York', // NY
    '540' => 'America/New_York', // VA
    '551' => 'America/New_York', // NJ
    '561' => 'America/New_York', // FL
    '563' => 'America/Chicago',  // IA
    '567' => 'America/New_York', // OH
    '570' => 'America/New_York', // PA
    '571' => 'America/New_York', // VA
    '573' => 'America/Chicago',  // MO
    '574' => 'America/New_York', // IN
    '580' => 'America/Chicago',  // OK
    '585' => 'America/New_York', // NY
    '586' => 'America/New_York', // MI
    '601' => 'America/Chicago',  // MS
    '603' => 'America/New_York', // NH
    '605' => 'America/Chicago',  // SD
    '606' => 'America/New_York', // KY
    '607' => 'America/New_York', // NY
    '608' => 'America/Chicago',  // WI
    '609' => 'America/New_York', // NJ
    '610' => 'America/New_York', // PA
    '612' => 'America/Chicago',  // MN
    '614' => 'America/New_York', // OH
    '615' => 'America/Chicago',  // TN
    '616' => 'America/New_York', // MI
    '617' => 'America/New_York', // MA
    '618' => 'America/Chicago',  // IL
    '620' => 'America/Chicago',  // KS
    '630' => 'America/Chicago',  // IL
    '631' => 'America/New_York', // NY
    '636' => 'America/Chicago',  // MO
    '646' => 'America/New_York', // NY
    '651' => 'America/Chicago',  // MN
    '657' => 'America/Los_Angeles', // CA
    '660' => 'America/Chicago',  // MO
    '662' => 'America/Chicago',  // MS
    '678' => 'America/New_York', // GA
    '681' => 'America/New_York', // WV
    '682' => 'America/Chicago',  // TX
    '701' => 'America/Chicago',  // ND
    '703' => 'America/New_York', // VA
    '704' => 'America/New_York', // NC
    '706' => 'America/New_York', // GA
    '708' => 'America/Chicago',  // IL
    '712' => 'America/Chicago',  // IA
    '713' => 'America/Chicago',  // TX
    '715' => 'America/Chicago',  // WI
    '716' => 'America/New_York', // NY
    '717' => 'America/New_York', // PA
    '718' => 'America/New_York', // NY
    '724' => 'America/New_York', // PA
    '725' => 'America/Los_Angeles', // NV
    '727' => 'America/New_York', // FL
    '731' => 'America/Chicago',  // TN
    '732' => 'America/New_York', // NJ
    '734' => 'America/New_York', // MI
    '737' => 'America/Chicago',  // TX
    '740' => 'America/New_York', // OH
    '743' => 'America/New_York', // NC
    '747' => 'America/Los_Angeles', // CA
    '754' => 'America/New_York', // FL
    '757' => 'America/New_York', // VA
    '760' => 'America/Los_Angeles', // CA
    '762' => 'America/New_York', // GA
    '763' => 'America/Chicago',  // MN
    '765' => 'America/New_York', // IN
    '769' => 'America/Chicago',  // MS
    '770' => 'America/New_York', // GA
    '772' => 'America/New_York', // FL
    '773' => 'America/Chicago',  // IL
    '774' => 'America/New_York', // MA
    '779' => 'America/Chicago',  // IL
    '781' => 'America/New_York', // MA
    '786' => 'America/New_York', // FL
    '801' => 'America/Denver',   // UT
    '802' => 'America/New_York', // VT
    '803' => 'America/New_York', // SC
    '804' => 'America/New_York', // VA
    '806' => 'America/Chicago',  // TX
    '810' => 'America/New_York', // MI
    '812' => 'America/New_York', // IN
    '813' => 'America/New_York', // FL
    '814' => 'America/New_York', // PA
    '815' => 'America/Chicago',  // IL
    '816' => 'America/Chicago',  // MO
    '817' => 'America/Chicago',  // TX
    '828' => 'America/New_York', // NC
    '830' => 'America/Chicago',  // TX
    '832' => 'America/Chicago',  // TX
    '833' => null,               // Toll-free
    '843' => 'America/New_York', // SC
    '844' => null,               // Toll-free
    '845' => 'America/New_York', // NY
    '847' => 'America/Chicago',  // IL
    '848' => 'America/New_York', // NJ
    '850' => 'America/New_York', // FL (Central part is Chicago)
    '855' => null,               // Toll-free
    '856' => 'America/New_York', // NJ
    '857' => 'America/New_York', // MA
    '859' => 'America/New_York', // KY
    '860' => 'America/New_York', // CT
    '862' => 'America/New_York', // NJ
    '863' => 'America/New_York', // FL
    '864' => 'America/New_York', // SC
    '865' => 'America/New_York', // TN
    '866' => null,               // Toll-free
    '870' => 'America/Chicago',  // AR
    '872' => 'America/Chicago',  // IL
    '877' => null,               // Toll-free
    '878' => 'America/New_York', // PA
    '888' => null,               // Toll-free
    '901' => 'America/Chicago',  // TN
    '903' => 'America/Chicago',  // TX
    '904' => 'America/New_York', // FL
    '908' => 'America/New_York', // NJ
    '909' => 'America/Los_Angeles', // CA
    '910' => 'America/New_York', // NC
    '912' => 'America/New_York', // GA
    '913' => 'America/Chicago',  // KS
    '914' => 'America/New_York', // NY
    '915' => 'America/Denver',   // TX (El Paso)
    '917' => 'America/New_York', // NY
    '918' => 'America/Chicago',  // OK
    '919' => 'America/New_York', // NC
    '920' => 'America/Chicago',  // WI
    '929' => 'America/New_York', // NY
    '931' => 'America/Chicago',  // TN
    '936' => 'America/Chicago',  // TX
    '937' => 'America/New_York', // OH
    '940' => 'America/Chicago',  // TX
    '941' => 'America/New_York', // FL
    '947' => 'America/New_York', // MI
    '952' => 'America/Chicago',  // MN
    '954' => 'America/New_York', // FL
    '956' => 'America/Chicago',  // TX
    '959' => 'America/New_York', // CT
    '972' => 'America/Chicago',  // TX
    '973' => 'America/New_York', // NJ
    '978' => 'America/New_York', // MA
    '979' => 'America/Chicago',  // TX
    '980' => 'America/New_York', // NC
    '984' => 'America/New_York', // NC
    '985' => 'America/Chicago',  // LA
];
```

- [ ] **Step 4: Add timezone auto-population to Contact model**

In `app/Models/Prospector/Contact.php`, add a `booted()` method (or add to existing boot):

```php
protected static function booted(): void
{
    static::creating(function (Contact $contact) {
        if (empty($contact->timezone) && $contact->phone) {
            $areaCode = $contact->getAreaCode();
            $timezones = config('prospector-timezones');
            $contact->timezone = $timezones[$areaCode] ?? null;
        }
    });
}
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
php artisan test --filter=ContactTimezoneTest --compact
```

Expected: 3 tests pass.

- [ ] **Step 6: Run Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add config/prospector-timezones.php app/Models/Prospector/Contact.php tests/Feature/Prospector/ContactTimezoneTest.php
git commit -m "feat(prospector): area code timezone lookup + auto-populate on contact creation"
```

---

### Task 3: Campaign Model + CampaignContact Model Updates

**Files:**
- Modify: `app/Models/Prospector/Campaign.php`
- Modify: `app/Models/Prospector/CampaignContact.php`
- Modify: `app/Models/Prospector/DialerSession.php`

- [ ] **Step 1: Update Campaign model**

Add new columns to `$fillable` and add the `nextCampaign` relationship and helper methods:

```php
// Add to $fillable array:
'max_calls_per_day',
'max_total_attempts',
'max_days_in_campaign',
'min_minutes_between',
'next_campaign_id',
'calling_hours_start',
'calling_hours_end',
'auto_dial',

// Add relationship:
public function nextCampaign(): BelongsTo
{
    return $this->belongsTo(Campaign::class, 'next_campaign_id');
}

// Add casts:
protected function casts(): array
{
    return [
        'use_local_presence' => 'boolean',
        'auto_vm_drop' => 'boolean',
        'auto_dial' => 'boolean',
        'calling_hours_start' => 'string',
        'calling_hours_end' => 'string',
    ];
}

// Add method to check if a contact is callable right now based on timezone:
public function isContactCallable(Contact $contact): bool
{
    if (! $contact->timezone) {
        return true; // No timezone = callable (TCPA only applies if we know timezone)
    }

    $contactTime = now()->timezone($contact->timezone);
    $hour = (int) $contactTime->format('H');
    $timeStr = $contactTime->format('H:i');

    // TCPA floor: 8am-9pm in contact's local time
    if ($hour < 8 || $hour >= 21) {
        return false;
    }

    // Campaign override (tighter only)
    if ($this->calling_hours_start && $timeStr < $this->calling_hours_start) {
        return false;
    }
    if ($this->calling_hours_end && $timeStr >= $this->calling_hours_end) {
        return false;
    }

    return true;
}
```

- [ ] **Step 2: Update CampaignContact model**

Add new columns to `$fillable` and new methods:

```php
// Add to $fillable array:
'calls_today',
'total_attempts',
'entered_at',
'last_called_at',
'next_callable_at',
'exhausted_at',

// Add casts:
protected function casts(): array
{
    return [
        'is_priority' => 'boolean',
        'entered_at' => 'datetime',
        'last_called_at' => 'datetime',
        'next_callable_at' => 'datetime',
        'exhausted_at' => 'datetime',
        'last_attempted_at' => 'datetime',
        'added_at' => 'datetime',
    ];
}

// Add methods:
public function recordCallAttempt(Campaign $campaign): void
{
    $this->increment('calls_today');
    $this->increment('total_attempts');
    $this->increment('attempts');
    $this->update([
        'last_called_at' => now(),
        'last_attempted_at' => now(),
        'next_callable_at' => now()->addMinutes($campaign->min_minutes_between),
    ]);
}

public function isExhausted(Campaign $campaign): bool
{
    if ($this->total_attempts >= $campaign->max_total_attempts) {
        return true;
    }

    if ($this->entered_at && $this->entered_at->diffInDays(now()) >= $campaign->max_days_in_campaign) {
        return true;
    }

    return false;
}

public function exhaust(): void
{
    $this->update([
        'status' => 'exhausted',
        'exhausted_at' => now(),
    ]);
}

public function isCallableNow(Campaign $campaign): bool
{
    if ($this->calls_today >= $campaign->max_calls_per_day) {
        return false;
    }

    if ($this->next_callable_at && $this->next_callable_at->isFuture()) {
        return false;
    }

    return true;
}
```

- [ ] **Step 3: Update DialerSession model**

Add `conference_name` to `$fillable`:

```php
// Add to $fillable:
'conference_name',
```

- [ ] **Step 4: Run Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/Prospector/Campaign.php app/Models/Prospector/CampaignContact.php app/Models/Prospector/DialerSession.php
git commit -m "feat(prospector): campaign call rules, contact tracking methods, session conference name"
```

---

### Task 4: CampaignQueueService — Campaign-Driven Queue

**Files:**
- Create: `app/Services/Prospector/CampaignQueueService.php`
- Create: `tests/Feature/Prospector/CampaignQueueServiceTest.php`

- [ ] **Step 1: Write failing tests**

```bash
php artisan make:test Prospector/CampaignQueueServiceTest --pest --no-interaction
```

```php
<?php

use App\Models\Prospector\Agent;
use App\Models\Prospector\Campaign;
use App\Models\Prospector\CampaignContact;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Services\Prospector\CampaignQueueService;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->agent = Agent::factory()->create(['company_id' => $this->company->id]);
    $this->campaign = Campaign::factory()->create([
        'company_id' => $this->company->id,
        'status' => 'active',
        'max_calls_per_day' => 3,
        'max_total_attempts' => 90,
        'min_minutes_between' => 240,
    ]);
});

it('returns queued contacts for a campaign', function () {
    $contact = Contact::factory()->create(['company_id' => $this->company->id]);
    CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $contact->id,
        'status' => 'queued',
    ]);

    $service = app(CampaignQueueService::class);
    $queue = $service->getQueue($this->campaign, $this->agent);

    expect($queue)->toHaveCount(1);
    expect($queue->first()->contact_id)->toBe($contact->id);
});

it('excludes contacts that hit daily call limit', function () {
    $contact = Contact::factory()->create(['company_id' => $this->company->id]);
    CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $contact->id,
        'status' => 'queued',
        'calls_today' => 3, // At limit
    ]);

    $service = app(CampaignQueueService::class);
    $queue = $service->getQueue($this->campaign, $this->agent);

    expect($queue)->toHaveCount(0);
});

it('excludes contacts before next_callable_at', function () {
    $contact = Contact::factory()->create(['company_id' => $this->company->id]);
    CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $contact->id,
        'status' => 'queued',
        'next_callable_at' => now()->addHours(2),
    ]);

    $service = app(CampaignQueueService::class);
    $queue = $service->getQueue($this->campaign, $this->agent);

    expect($queue)->toHaveCount(0);
});

it('excludes do not call contacts', function () {
    $contact = Contact::factory()->create([
        'company_id' => $this->company->id,
        'do_not_call' => true,
    ]);
    CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $contact->id,
        'status' => 'queued',
    ]);

    $service = app(CampaignQueueService::class);
    $queue = $service->getQueue($this->campaign, $this->agent);

    expect($queue)->toHaveCount(0);
});

it('prioritizes flagged contacts first', function () {
    $regular = Contact::factory()->create(['company_id' => $this->company->id, 'first_name' => 'Regular']);
    $priority = Contact::factory()->create(['company_id' => $this->company->id, 'first_name' => 'Priority']);

    CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $regular->id,
        'status' => 'queued',
    ]);
    CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $priority->id,
        'status' => 'queued',
        'is_priority' => true,
    ]);

    $service = app(CampaignQueueService::class);
    $queue = $service->getQueue($this->campaign, $this->agent);

    expect($queue->first()->contact_id)->toBe($priority->id);
});

it('gets next callable contact', function () {
    $contact = Contact::factory()->create(['company_id' => $this->company->id]);
    CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $contact->id,
        'status' => 'queued',
    ]);

    $service = app(CampaignQueueService::class);
    $next = $service->getNext($this->campaign, $this->agent);

    expect($next)->not->toBeNull();
    expect($next->contact_id)->toBe($contact->id);
});

it('handles exhaustion by moving contact to next campaign', function () {
    $nextCampaign = Campaign::factory()->create([
        'company_id' => $this->company->id,
        'status' => 'active',
    ]);
    $this->campaign->update(['next_campaign_id' => $nextCampaign->id]);

    $contact = Contact::factory()->create(['company_id' => $this->company->id]);
    $cc = CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $contact->id,
        'status' => 'queued',
        'total_attempts' => 90, // At limit
    ]);

    $service = app(CampaignQueueService::class);
    $service->checkExhaustion($cc, $this->campaign);

    expect($cc->fresh()->status)->toBe('exhausted');
    expect(CampaignContact::where('campaign_id', $nextCampaign->id)->where('contact_id', $contact->id)->exists())->toBeTrue();
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --filter=CampaignQueueServiceTest --compact
```

Expected: FAIL — CampaignQueueService doesn't exist.

- [ ] **Step 3: Create CampaignQueueService**

```bash
php artisan make:class Services/Prospector/CampaignQueueService --no-interaction
```

```php
<?php

namespace App\Services\Prospector;

use App\Models\Prospector\Agent;
use App\Models\Prospector\Campaign;
use App\Models\Prospector\CampaignContact;
use Illuminate\Support\Collection;

class CampaignQueueService
{
    public function getQueue(Campaign $campaign, Agent $agent, int $limit = 50): Collection
    {
        return CampaignContact::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', 'queued')
            ->where('calls_today', '<', $campaign->max_calls_per_day)
            ->where('total_attempts', '<', $campaign->max_total_attempts)
            ->where(function ($q) {
                $q->whereNull('next_callable_at')
                    ->orWhere('next_callable_at', '<=', now());
            })
            ->where(function ($q) use ($agent) {
                $q->whereNull('claimed_by_agent_id')
                    ->orWhere('claimed_by_agent_id', $agent->id);
            })
            ->whereHas('contact', function ($q) {
                $q->where('do_not_call', false);
            })
            ->with('contact')
            ->orderByDesc('is_priority')
            ->orderByRaw("CASE WHEN EXISTS (
                SELECT 1 FROM prospector_contacts
                WHERE prospector_contacts.id = prospector_campaign_contact.contact_id
                AND prospector_contacts.follow_up_at IS NOT NULL
                AND prospector_contacts.follow_up_at <= ?
            ) THEN 0 ELSE 1 END", [now()])
            ->orderBy('last_called_at')
            ->orderBy('sort_order')
            ->limit($limit)
            ->get()
            ->filter(function (CampaignContact $cc) use ($campaign) {
                // Timezone filter (applied in PHP — timezone logic is complex for raw SQL)
                return $campaign->isContactCallable($cc->contact);
            })
            ->values();
    }

    public function getNext(Campaign $campaign, Agent $agent): ?CampaignContact
    {
        return $this->getQueue($campaign, $agent, 1)->first();
    }

    public function claim(CampaignContact $cc, Agent $agent): bool
    {
        return $cc->claim($agent->id);
    }

    public function release(CampaignContact $cc): void
    {
        $cc->unclaim();
    }

    public function checkExhaustion(CampaignContact $cc, Campaign $campaign): bool
    {
        if (! $cc->isExhausted($campaign)) {
            return false;
        }

        $cc->exhaust();

        // Move to next campaign if configured
        if ($campaign->next_campaign_id) {
            CampaignContact::firstOrCreate(
                [
                    'campaign_id' => $campaign->next_campaign_id,
                    'contact_id' => $cc->contact_id,
                ],
                [
                    'status' => 'queued',
                    'calls_today' => 0,
                    'total_attempts' => 0,
                    'entered_at' => now(),
                    'sort_order' => 0,
                    'attempts' => 0,
                ],
            );
        }

        return true;
    }

    public function getQueueAsArray(Campaign $campaign, Agent $agent, int $limit = 50): array
    {
        return $this->getQueue($campaign, $agent, $limit)
            ->map(function (CampaignContact $cc) use ($campaign) {
                $contact = $cc->contact;

                return [
                    'id' => $contact->id,
                    'campaign_contact_id' => $cc->id,
                    'name' => $contact->getFullName(),
                    'phone' => $contact->getDisplayPhone(),
                    'company_id' => $contact->company_id,
                    'company_name' => $contact->company?->name,
                    'company_color' => $contact->company?->color,
                    'calls_today' => $cc->calls_today,
                    'max_calls_today' => $campaign->max_calls_per_day,
                    'total_attempts' => $cc->total_attempts,
                    'max_total' => $campaign->max_total_attempts,
                    'is_priority' => $cc->is_priority,
                    'follow_up_at' => $contact->follow_up_at?->toISOString(),
                ];
            })
            ->all();
    }
}
```

- [ ] **Step 4: Run tests**

```bash
php artisan test --filter=CampaignQueueServiceTest --compact
```

Expected: 7 tests pass.

- [ ] **Step 5: Run Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Prospector/CampaignQueueService.php tests/Feature/Prospector/CampaignQueueServiceTest.php
git commit -m "feat(prospector): CampaignQueueService — campaign-driven queue with call rules and timezone filter"
```

---

### Task 5: DialerOrchestrationService — Persistent Conference Session

**Files:**
- Create: `app/Services/Prospector/DialerOrchestrationService.php`
- Create: `tests/Feature/Prospector/DialerOrchestrationServiceTest.php`

- [ ] **Step 1: Write failing tests**

```bash
php artisan make:test Prospector/DialerOrchestrationServiceTest --pest --no-interaction
```

```php
<?php

use App\Models\Prospector\Agent;
use App\Models\Prospector\Campaign;
use App\Models\Prospector\CampaignContact;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\DialerSession;
use App\Models\Prospector\PhoneNumber;
use App\Models\Prospector\TwilioAccount;
use App\Services\Prospector\ConferenceService;
use App\Services\Prospector\DialerOrchestrationService;

beforeEach(function () {
    $this->twilioAccount = TwilioAccount::factory()->create(['is_primary' => true, 'is_active' => true]);
    $this->company = Company::factory()->create(['twilio_account_id' => $this->twilioAccount->id]);
    $this->phone = PhoneNumber::factory()->create([
        'company_id' => $this->company->id,
        'twilio_account_id' => $this->twilioAccount->id,
        'role' => 'main',
    ]);
    $this->company->update(['main_phone_number_id' => $this->phone->id]);
    $this->agent = Agent::factory()->create(['company_id' => $this->company->id]);
    $this->campaign = Campaign::factory()->create([
        'company_id' => $this->company->id,
        'status' => 'active',
        'max_calls_per_day' => 3,
    ]);
});

it('starts a session with a persistent conference name', function () {
    $service = app(DialerOrchestrationService::class);
    $session = $service->startSession($this->agent, $this->campaign);

    expect($session)->toBeInstanceOf(DialerSession::class);
    expect($session->status)->toBe('active');
    expect($session->conference_name)->toStartWith('agent-' . $this->agent->id . '-');
    expect($session->agent_id)->toBe($this->agent->id);
    expect($session->campaign_id)->toBe($this->campaign->id);
});

it('ends a session and marks it completed', function () {
    $service = app(DialerOrchestrationService::class);
    $session = $service->startSession($this->agent, $this->campaign);

    $service->endSession($session);

    expect($session->fresh()->status)->toBe('completed');
    expect($session->fresh()->ended_at)->not->toBeNull();
});

it('gets the next dial target with phone number', function () {
    $contact = Contact::factory()->create(['company_id' => $this->company->id, 'phone' => '+13035551234']);
    CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $contact->id,
        'status' => 'queued',
    ]);

    $service = app(DialerOrchestrationService::class);
    $session = $service->startSession($this->agent, $this->campaign);
    $target = $service->getNextDialTarget($session);

    expect($target)->not->toBeNull();
    expect($target['contact']->id)->toBe($contact->id);
    expect($target['fromNumber'])->toBeInstanceOf(PhoneNumber::class);
    expect($target['campaignContact'])->toBeInstanceOf(CampaignContact::class);
});

it('records a call attempt and updates counters', function () {
    $contact = Contact::factory()->create(['company_id' => $this->company->id]);
    $cc = CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $contact->id,
        'status' => 'queued',
    ]);

    $service = app(DialerOrchestrationService::class);
    $session = $service->startSession($this->agent, $this->campaign);

    $call = $service->recordDialAttempt($session, $contact, $cc, $this->phone);

    expect($call->agent_id)->toBe($this->agent->id);
    expect($call->contact_id)->toBe($contact->id);
    expect($call->twilio_conference_sid)->toBe($session->conference_name);
    expect($cc->fresh()->calls_today)->toBe(1);
    expect($cc->fresh()->total_attempts)->toBe(1);
});

it('dispositions a call and checks exhaustion', function () {
    $contact = Contact::factory()->create(['company_id' => $this->company->id]);
    $cc = CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $contact->id,
        'status' => 'queued',
        'total_attempts' => 89, // One away from max
    ]);

    $service = app(DialerOrchestrationService::class);
    $session = $service->startSession($this->agent, $this->campaign);
    $call = $service->recordDialAttempt($session, $contact, $cc, $this->phone);

    $service->dispositionCall($call, 'no_answer');

    // total_attempts was 89, recordDialAttempt bumped to 90, disposition should exhaust
    expect($cc->fresh()->status)->toBe('exhausted');
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --filter=DialerOrchestrationServiceTest --compact
```

Expected: FAIL — DialerOrchestrationService doesn't exist.

- [ ] **Step 3: Create DialerOrchestrationService**

```bash
php artisan make:class Services/Prospector/DialerOrchestrationService --no-interaction
```

```php
<?php

namespace App\Services\Prospector;

use App\Events\Prospector\DialerCallRinging;
use App\Models\Prospector\Agent;
use App\Models\Prospector\Call;
use App\Models\Prospector\Campaign;
use App\Models\Prospector\CampaignContact;
use App\Models\Prospector\Contact;
use App\Models\Prospector\DialerSession;
use App\Models\Prospector\PhoneNumber;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DialerOrchestrationService
{
    public function __construct(
        private CampaignQueueService $queueService,
        private NumberSelectionService $numberService,
        private ConferenceService $conferenceService,
        private AgentStateService $agentStateService,
    ) {}

    /**
     * Start a persistent conference session for the agent.
     */
    public function startSession(Agent $agent, Campaign $campaign): DialerSession
    {
        // End any existing active session
        DialerSession::where('agent_id', $agent->id)
            ->where('status', 'active')
            ->each(fn (DialerSession $s) => $s->complete());

        $conferenceName = 'agent-'.$agent->id.'-'.Str::ulid();

        $session = DialerSession::create([
            'ulid' => Str::ulid()->toBase32(),
            'agent_id' => $agent->id,
            'campaign_id' => $campaign->id,
            'mode' => 'single',
            'status' => 'active',
            'conference_name' => $conferenceName,
            'started_at' => now(),
        ]);

        $this->agentStateService->setStatus($agent, 'available');

        return $session;
    }

    /**
     * End the session and clean up.
     */
    public function endSession(DialerSession $session): void
    {
        $session->complete();
        $this->agentStateService->setStatus($session->agent, 'offline');
    }

    /**
     * Pause auto-dialing but keep conference alive.
     */
    public function pauseSession(DialerSession $session): void
    {
        $session->update(['status' => 'paused']);
    }

    /**
     * Resume auto-dialing.
     */
    public function resumeSession(DialerSession $session): void
    {
        $session->update(['status' => 'active']);
    }

    /**
     * Get the next contact to dial with their phone number.
     * Returns null if queue is empty.
     */
    public function getNextDialTarget(DialerSession $session): ?array
    {
        $campaign = $session->campaign;
        $agent = $session->agent;

        $cc = $this->queueService->getNext($campaign, $agent);

        if (! $cc) {
            return null;
        }

        $contact = $cc->contact;
        $contact->load('company');

        try {
            $fromNumber = $this->numberService->selectNumber(
                $contact->company,
                $contact,
                $campaign,
            );
        } catch (\RuntimeException $e) {
            Log::warning('No phone number available for contact', [
                'contact_id' => $contact->id,
                'company_id' => $contact->company_id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return [
            'contact' => $contact,
            'fromNumber' => $fromNumber,
            'campaignContact' => $cc,
        ];
    }

    /**
     * Record that a dial attempt was made. Creates Call record, updates counters.
     * Does NOT actually dial — the Twilio Participants API call happens separately.
     */
    public function recordDialAttempt(
        DialerSession $session,
        Contact $contact,
        CampaignContact $cc,
        PhoneNumber $fromNumber,
    ): Call {
        $call = Call::create([
            'ulid' => Str::ulid()->toBase32(),
            'company_id' => $contact->company_id,
            'dialer_session_id' => $session->id,
            'agent_id' => $session->agent_id,
            'contact_id' => $contact->id,
            'campaign_id' => $session->campaign_id,
            'from_phone_number_id' => $fromNumber->id,
            'from_number' => $fromNumber->getEffectiveCallerId(),
            'to_number' => $contact->phone,
            'direction' => 'outbound',
            'status' => 'ringing',
            'started_at' => now(),
            'twilio_conference_sid' => $session->conference_name,
        ]);

        // Update campaign contact counters
        $cc->recordCallAttempt($session->campaign);

        // Update session stats
        $session->incrementAttempted();

        // Claim the contact
        $this->queueService->claim($cc, $session->agent);

        return $call;
    }

    /**
     * Dial a contact into the agent's persistent conference via Twilio Participants API.
     */
    public function dialContactIntoConference(
        DialerSession $session,
        Contact $contact,
        PhoneNumber $fromNumber,
        Call $call,
    ): string {
        $amdParams = [];
        if (config('prospector.defaults.amd_enabled')) {
            $amdParams = app(AmdService::class)->getAmdParams();
        }

        $callSid = $this->conferenceService->dialContactIntoConference(
            $session->conference_name,
            $contact,
            $fromNumber,
            $amdParams,
        );

        $call->update(['twilio_call_sid' => $callSid]);

        // Broadcast ringing event
        broadcast(new DialerCallRinging($call, $session->agent_id));
        $this->agentStateService->setStatus($session->agent, 'ringing');

        $session->agent->incrementCallCount();

        return $callSid;
    }

    /**
     * Disposition a call and handle post-call logic.
     */
    public function dispositionCall(Call $call, string $disposition, ?string $notes = null): void
    {
        $call->load('contact', 'campaign', 'agent');

        // Mark call completed
        $call->markCompleted($disposition, $notes);
        $call->contact->markContacted($disposition);

        // Update campaign contact
        $cc = CampaignContact::where('campaign_id', $call->campaign_id)
            ->where('contact_id', $call->contact_id)
            ->first();

        if ($cc) {
            $cc->markCompleted($disposition);

            // Handle DNC
            if ($disposition === 'dnc') {
                $call->contact->update(['do_not_call' => true]);
            }

            // Handle callback
            if ($disposition === 'callback' && $call->callback_at) {
                $cc->markCallbackScheduled();
                $call->contact->update(['follow_up_at' => $call->callback_at]);
            }

            // Check exhaustion
            $campaign = $call->campaign;
            if ($campaign) {
                $this->queueService->checkExhaustion($cc, $campaign);
            }

            // Release claim
            $this->queueService->release($cc);
        }

        // Update campaign stats
        if ($call->campaign) {
            $call->campaign->increment('calls_attempted');
            if (! in_array($disposition, ['no_answer', 'busy'])) {
                $call->campaign->increment('calls_connected');
            }
        }
    }
}
```

- [ ] **Step 4: Run tests**

```bash
php artisan test --filter=DialerOrchestrationServiceTest --compact
```

Expected: 5 tests pass.

- [ ] **Step 5: Run Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Prospector/DialerOrchestrationService.php tests/Feature/Prospector/DialerOrchestrationServiceTest.php
git commit -m "feat(prospector): DialerOrchestrationService — persistent conference session lifecycle and dial flow"
```

---

### Task 6: Daily Call Count Reset Command

**Files:**
- Create: `app/Console/Commands/ResetDailyCallCounts.php`
- Create: `tests/Feature/Prospector/DailyCallResetTest.php`

- [ ] **Step 1: Write failing test**

```bash
php artisan make:test Prospector/DailyCallResetTest --pest --no-interaction
```

```php
<?php

use App\Models\Prospector\Campaign;
use App\Models\Prospector\CampaignContact;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;

it('resets calls_today to zero for all campaign contacts', function () {
    $company = Company::factory()->create();
    $campaign = Campaign::factory()->create(['company_id' => $company->id]);
    $contact = Contact::factory()->create(['company_id' => $company->id]);

    CampaignContact::create([
        'campaign_id' => $campaign->id,
        'contact_id' => $contact->id,
        'status' => 'queued',
        'calls_today' => 3,
    ]);

    $this->artisan('prospector:reset-daily-calls')
        ->assertExitCode(0);

    expect(CampaignContact::first()->calls_today)->toBe(0);
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --filter=DailyCallResetTest --compact
```

- [ ] **Step 3: Create the command**

```bash
php artisan make:command ResetDailyCallCounts --no-interaction
```

Move to correct namespace and edit:

```php
<?php

namespace App\Console\Commands;

use App\Models\Prospector\CampaignContact;
use Illuminate\Console\Command;

class ResetDailyCallCounts extends Command
{
    protected $signature = 'prospector:reset-daily-calls';

    protected $description = 'Reset calls_today counter for all campaign contacts (runs at midnight)';

    public function handle(): int
    {
        $updated = CampaignContact::where('calls_today', '>', 0)->update(['calls_today' => 0]);

        $this->info("Reset calls_today for {$updated} campaign contacts.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Register in scheduler**

In `routes/console.php`, add:

```php
Schedule::command('prospector:reset-daily-calls')->dailyAt('00:00');
```

- [ ] **Step 5: Run test**

```bash
php artisan test --filter=DailyCallResetTest --compact
```

Expected: PASS.

- [ ] **Step 6: Run Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Console/Commands/ResetDailyCallCounts.php tests/Feature/Prospector/DailyCallResetTest.php routes/console.php
git commit -m "feat(prospector): daily call count reset scheduler command"
```

---

### Task 7: DialerSession JS Module — Auto-Dial State Machine

**Files:**
- Create: `resources/js/dialer-session.js`

- [ ] **Step 1: Create the DialerSession JS module**

```javascript
/**
 * DialerSession — Auto-dial state machine and session orchestration.
 *
 * Works alongside SoftphoneManager to manage the persistent conference
 * auto-dial loop. Coordinates between the Twilio Voice SDK (via SoftphoneManager)
 * and the Livewire dialer component.
 *
 * States: idle → starting → ready → dialing → on_call → disposition → dialing (loop)
 *
 * Usage:
 *   const session = new DialerSession(softphone, $wire);
 *   await session.start(campaignId);  // Connects to conference, starts auto-dial
 *   session.pause();                   // Pause auto-dial, stay in conference
 *   session.resume();                  // Resume auto-dial
 *   session.stop();                    // End session, disconnect
 *   session.on('state-change', (state) => { ... });
 */
export default class DialerSession {
    constructor(softphone, wire) {
        this._softphone = softphone;
        this._wire = wire;
        this._state = 'idle'; // idle, starting, ready, dialing, on_call, disposition
        this._autoDial = false;
        this._listeners = {};
        this._dialTimer = null;
        this._currentContactId = null;
        this._sessionId = null;
        this._conferenceName = null;
    }

    // ── Event Emitter ──

    on(event, fn) {
        if (!this._listeners[event]) this._listeners[event] = [];
        this._listeners[event].push(fn);
        return this;
    }

    _emit(event, ...args) {
        (this._listeners[event] || []).forEach(fn => {
            try { fn(...args); } catch (e) { console.error(`DialerSession [${event}]:`, e); }
        });
    }

    // ── Getters ──

    get state() { return this._state; }
    get isActive() { return this._state !== 'idle'; }
    get isAutoDialing() { return this._autoDial && this._state === 'ready'; }
    get sessionId() { return this._sessionId; }
    get conferenceName() { return this._conferenceName; }

    // ── Session Lifecycle ──

    /**
     * Start a dialer session: create session on server, connect to conference.
     */
    async start(campaignId) {
        if (this._state !== 'idle') return;

        this._setState('starting');

        try {
            // Create session on server
            const result = await this._wire.startDialerSession(campaignId);
            if (result.error) {
                this._emit('error', result.error);
                this._setState('idle');
                return;
            }

            this._sessionId = result.sessionId;
            this._conferenceName = result.conferenceName;

            // Connect agent to the persistent conference via SoftphoneManager
            await this._softphone.dial({ conference: this._conferenceName });

            this._setState('ready');
            this._emit('session-started', { sessionId: this._sessionId, conferenceName: this._conferenceName });

        } catch (err) {
            this._emit('error', 'Failed to start session: ' + (err.message || err));
            this._setState('idle');
        }
    }

    /**
     * Start auto-dialing through the queue.
     */
    startAutoDial() {
        if (this._state !== 'ready') return;
        this._autoDial = true;
        this._emit('auto-dial-started');
        this._dialNext();
    }

    /**
     * Pause auto-dial but keep conference alive.
     */
    pause() {
        this._autoDial = false;
        if (this._dialTimer) {
            clearTimeout(this._dialTimer);
            this._dialTimer = null;
        }
        this._wire.pauseDialerSession();
        this._emit('auto-dial-paused');
    }

    /**
     * Resume auto-dialing.
     */
    resume() {
        if (this._state !== 'ready' && this._state !== 'disposition') return;
        this._autoDial = true;
        this._wire.resumeDialerSession();
        this._emit('auto-dial-resumed');
        if (this._state === 'ready') {
            this._dialNext();
        }
    }

    /**
     * Stop session completely, disconnect from conference.
     */
    async stop() {
        this._autoDial = false;
        if (this._dialTimer) {
            clearTimeout(this._dialTimer);
            this._dialTimer = null;
        }

        if (this._sessionId) {
            await this._wire.endDialerSession();
        }

        this._softphone.hangup();
        this._sessionId = null;
        this._conferenceName = null;
        this._currentContactId = null;
        this._setState('idle');
        this._emit('session-ended');
    }

    // ── Call Flow (called by Alpine event handlers) ──

    /**
     * Called when the contact answers (from Echo event).
     */
    onCallConnected(contactId) {
        this._currentContactId = contactId;
        this._setState('on_call');
    }

    /**
     * Called when the call ends (from Echo event).
     */
    onCallEnded() {
        this._currentContactId = null;
        this._setState('disposition');
    }

    /**
     * Called after agent selects a disposition.
     */
    onDispositioned() {
        this._setState('ready');
        if (this._autoDial) {
            // Brief pause before next dial (1.5 seconds for UI transition)
            this._dialTimer = setTimeout(() => this._dialNext(), 1500);
        }
    }

    /**
     * Called when no answer / machine detected (auto-advance).
     */
    onNoAnswer() {
        this._currentContactId = null;
        this._setState('ready');
        if (this._autoDial) {
            this._dialTimer = setTimeout(() => this._dialNext(), 500);
        }
    }

    // ── Internal ──

    async _dialNext() {
        if (this._state !== 'ready' || !this._autoDial) return;

        this._setState('dialing');

        try {
            const result = await this._wire.dialNextFromSession();

            if (result.error) {
                if (result.error === 'queue_empty') {
                    this._emit('queue-empty');
                    this._autoDial = false;
                    this._setState('ready');
                } else {
                    this._emit('error', result.error);
                    this._setState('ready');
                }
                return;
            }

            // Server dialed the contact into the conference.
            // We'll get a DialerCallConnected or DialerCallEnded event via Echo.
            this._currentContactId = result.contactId;

        } catch (err) {
            this._emit('error', 'Dial failed: ' + (err.message || err));
            this._setState('ready');
        }
    }

    _setState(state) {
        const prev = this._state;
        this._state = state;
        if (prev !== state) {
            this._emit('state-change', state, prev);
        }
    }

    destroy() {
        if (this._dialTimer) clearTimeout(this._dialTimer);
        this._listeners = {};
    }
}
```

- [ ] **Step 2: Register lazy loader in app.js**

Add after the `loadSoftphone` block in `resources/js/app.js`:

```javascript
window.loadDialerSession = async () => {
    if (!window._dialerSessionModule) {
        const module = await import('./dialer-session.js');
        window._dialerSessionModule = module.default;
    }
    return window._dialerSessionModule;
};
```

- [ ] **Step 3: Build and verify**

```bash
npm run build
```

Expected: Build succeeds, `dialer-session.js` in the bundle.

- [ ] **Step 4: Commit**

```bash
git add resources/js/dialer-session.js resources/js/app.js
git commit -m "feat(prospector): DialerSession JS module — auto-dial state machine with persistent conference"
```

---

### Task 8: Rebuild Dialer Blade — Livewire PHP Methods

**Files:**
- Modify: `resources/views/pages/prospector/⚡dialer.blade.php`

This task rewrites the PHP class at the top of the dialer SFC. The template (Task 9) comes next.

- [ ] **Step 1: Rewrite the Livewire PHP section**

The key changes:
- Replace `dialContact()`, `dialNext()`, `dialContactIntoConference()` with session-based methods
- Add `startDialerSession()`, `endDialerSession()`, `pauseDialerSession()`, `resumeDialerSession()`
- Add `dialNextFromSession()` — picks next contact from campaign queue, dials into conference, returns result
- Add `syncCampaignQueue()` — replaces `syncQueue()` with campaign-driven queue
- Keep existing: SMS, notes, disposition, transfer, contact preview, agent status methods
- Remove: old `dialOut`-related methods, `join-conference` events, `ensure-voice-company` events

**New session methods to add:**

```php
public function startDialerSession(int $campaignId): array
{
    if (! $this->agent) {
        return ['error' => 'No agent'];
    }

    $campaign = Campaign::where('id', $campaignId)->where('status', 'active')->first();
    if (! $campaign) {
        return ['error' => 'Campaign not found or not active'];
    }

    $service = app(DialerOrchestrationService::class);
    $session = $service->startSession($this->agent, $campaign);

    $this->activeSessionId = $session->id;
    $this->selectedCampaignId = $campaign->id;
    $this->agentStatus = 'available';

    return [
        'sessionId' => $session->id,
        'conferenceName' => $session->conference_name,
    ];
}

public function endDialerSession(): void
{
    if (! $this->activeSessionId) {
        return;
    }

    $session = DialerSession::find($this->activeSessionId);
    if ($session) {
        app(DialerOrchestrationService::class)->endSession($session);
    }

    $this->activeSessionId = null;
    $this->agentStatus = 'offline';
    $this->resetCallState();
}

public function pauseDialerSession(): void
{
    if (! $this->activeSessionId) {
        return;
    }
    $session = DialerSession::find($this->activeSessionId);
    if ($session) {
        app(DialerOrchestrationService::class)->pauseSession($session);
    }
}

public function resumeDialerSession(): void
{
    if (! $this->activeSessionId) {
        return;
    }
    $session = DialerSession::find($this->activeSessionId);
    if ($session) {
        app(DialerOrchestrationService::class)->resumeSession($session);
    }
}

public function dialNextFromSession(): array
{
    if (! $this->activeSessionId) {
        return ['error' => 'No active session'];
    }

    $session = DialerSession::with('campaign')->find($this->activeSessionId);
    if (! $session || ! $session->isActive()) {
        return ['error' => 'Session not active'];
    }

    $service = app(DialerOrchestrationService::class);
    $target = $service->getNextDialTarget($session);

    if (! $target) {
        return ['error' => 'queue_empty'];
    }

    $contact = $target['contact'];
    $fromNumber = $target['fromNumber'];
    $cc = $target['campaignContact'];

    // Record the attempt (creates Call record, updates counters)
    $call = $service->recordDialAttempt($session, $contact, $cc, $fromNumber);

    // Set UI state
    $this->setActiveCallFromCall($call->load('contact'));
    $this->agentStatus = 'ringing';
    $this->showDisposition = false;

    // Actually dial the contact into the conference
    try {
        $callSid = $service->dialContactIntoConference($session, $contact, $fromNumber, $call);
    } catch (\Exception $e) {
        $call->markFailed();
        return ['error' => 'Failed to dial: ' . $e->getMessage()];
    }

    $this->clearCaches();

    return [
        'contactId' => $contact->id,
        'callId' => $call->id,
    ];
}

public function syncCampaignQueue(): array
{
    if (! $this->selectedCampaignId || ! $this->agent) {
        return [];
    }

    $campaign = Campaign::find($this->selectedCampaignId);
    if (! $campaign) {
        return [];
    }

    $service = app(CampaignQueueService::class);
    return $service->getQueueAsArray($campaign, $this->agent);
}
```

**New properties to add:**

```php
public ?int $activeSessionId = null;
public ?int $selectedCampaignId = null;
```

- [ ] **Step 2: Update submitDisposition to use new service**

Replace the existing `submitDisposition()` to use `DialerOrchestrationService`:

```php
public function submitDisposition(string $disposition, ?string $callbackAt = null): void
{
    if (! $this->activeCallId) {
        return;
    }

    $call = Call::findOrFail($this->activeCallId);

    if ($callbackAt) {
        $call->update(['callback_at' => $callbackAt]);
    }

    $service = app(DialerOrchestrationService::class);
    $service->dispositionCall($call, $disposition, $this->dispositionNotes ?: null);

    $this->dispositionNotes = '';
    $this->resetCallState();
    $this->clearCaches();
    $this->dispatch('queue-reload');
}
```

- [ ] **Step 3: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 4: Commit**

```bash
git add "resources/views/pages/prospector/⚡dialer.blade.php"
git commit -m "feat(prospector): rewrite dialer PHP methods for persistent conference session"
```

---

### Task 9: Rebuild Dialer Blade — Alpine JS + Template

**Files:**
- Modify: `resources/views/pages/prospector/⚡dialer.blade.php`

- [ ] **Step 1: Rewrite Alpine x-data for session-based dialing**

Replace the Alpine init/phone/dial methods with DialerSession integration:

```javascript
// New Alpine properties
sessionState: 'idle', // idle, starting, ready, dialing, on_call, disposition
sessionError: null,
autoDial: false,
dialerSession: null, // DialerSession instance

// In init():
async initDialerSession() {
    const SoftphoneManager = await window.loadSoftphone();
    window.__softphone = new SoftphoneManager();
    const phone = window.__softphone;

    phone.on('ready', (companyId) => {
        this.phoneReady = true;
        this.phoneCompanyId = companyId;
    });

    phone.on('error', (msg) => {
        this.sessionError = msg;
    });

    phone.on('disconnected', () => {
        this.stopTimer();
    });

    await phone.init();

    // Load DialerSession module
    const DialerSessionClass = await window.loadDialerSession();
    this.dialerSession = new DialerSessionClass(phone, $wire);

    this.dialerSession.on('state-change', (state) => {
        this.sessionState = state;
    });

    this.dialerSession.on('error', (msg) => {
        this.sessionError = msg;
        setTimeout(() => { this.sessionError = null; }, 10000);
    });

    this.dialerSession.on('queue-empty', () => {
        this.sessionError = 'Queue is empty — no more contacts to call';
        this.autoDial = false;
    });

    this.dialerSession.on('session-started', () => {
        this.loadQueue();
    });

    this.dialerSession.on('session-ended', () => {
        this.stopTimer();
    });
},

async startSession() {
    if (!$wire.selectedCampaignId) {
        this.sessionError = 'Select a campaign first';
        return;
    }
    await this.dialerSession.start($wire.selectedCampaignId);
    this.autoDial = true;
    this.dialerSession.startAutoDial();
},

pauseSession() {
    this.autoDial = false;
    this.dialerSession.pause();
},

resumeSession() {
    this.autoDial = true;
    this.dialerSession.resume();
},

async stopSession() {
    this.autoDial = false;
    await this.dialerSession.stop();
},
```

- [ ] **Step 2: Update Echo event handlers for session model**

```javascript
.listen('.dialer.call.connected', (e) => {
    $wire.onCallConnected(e);
    this.startTimer();
    if (this.dialerSession) {
        this.dialerSession.onCallConnected(e.contact?.id);
    }
})
.listen('.dialer.call.ended', (e) => {
    this.stopTimer();
    $wire.onCallEnded(e);
    if (this.dialerSession) {
        if (e.disposition === 'no_answer' || e.disposition === 'busy') {
            this.dialerSession.onNoAnswer();
        } else {
            this.dialerSession.onCallEnded();
        }
    }
})
```

- [ ] **Step 3: Update the disposition handler**

After disposition is submitted, notify the DialerSession to advance:

```javascript
// In the disposition button click handler, after $wire.submitDisposition():
if (this.dialerSession) {
    this.dialerSession.onDispositioned();
}
```

- [ ] **Step 4: Rewrite the template**

Replace the queue section with horizontal scroll cards, campaign selector, and play/pause controls. Replace the dial buttons with session controls.

Key template sections:

**Top bar — Campaign selector + session controls:**
```blade
<div class="flex items-center gap-3 border-b border-zinc-200 px-4 py-2 dark:border-zinc-700">
    <flux:select wire:model.live="selectedCampaignId" size="sm" class="w-48">
        <option value="">Select Campaign</option>
        @foreach($this->agentCampaigns as $campaign)
            <option value="{{ $campaign->id }}">{{ $campaign->name }}</option>
        @endforeach
    </flux:select>

    <template x-if="sessionState === 'idle'">
        <flux:button size="sm" variant="primary" icon="play" x-on:click="startSession()">
            Start
        </flux:button>
    </template>
    <template x-if="sessionState !== 'idle' && autoDial">
        <flux:button size="sm" variant="filled" icon="pause" x-on:click="pauseSession()">
            Pause
        </flux:button>
    </template>
    <template x-if="sessionState !== 'idle' && !autoDial">
        <div class="flex gap-2">
            <flux:button size="sm" variant="primary" icon="play" x-on:click="resumeSession()">
                Resume
            </flux:button>
            <flux:button size="sm" variant="danger" icon="stop" x-on:click="stopSession()">
                Stop
            </flux:button>
        </div>
    </template>

    <div class="ml-auto text-xs text-zinc-500" x-text="sessionStatusText"></div>
</div>
```

**Horizontal queue cards:**
```blade
<div class="flex gap-2 overflow-x-auto border-b border-zinc-200 px-4 py-2 dark:border-zinc-700"
     x-ref="queueScroll">
    <template x-for="item in queueItems" :key="item.id">
        <div class="flex-shrink-0 w-36 rounded-lg border border-zinc-200 p-2 text-xs dark:border-zinc-700 cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800"
             x-on:click="$wire.previewContact(item.id)"
             :class="{ 'ring-2 ring-blue-500': $wire.previewContactId === item.id }">
            <div class="flex items-center gap-1.5 mb-1">
                <span class="inline-block size-2 rounded-full" :style="'background:' + (item.company_color || '#6b7280')"></span>
                <span class="font-medium truncate" x-text="item.name"></span>
            </div>
            <div class="text-zinc-500 truncate" x-text="item.phone"></div>
            <div class="text-zinc-400 mt-0.5" x-text="'Call ' + item.calls_today + '/' + item.max_calls_today + ' · ' + item.total_attempts + '/' + item.max_total"></div>
        </div>
    </template>
    <div x-show="queueItems.length === 0" class="text-xs text-zinc-400 py-3">
        No contacts in queue
    </div>
</div>
```

- [ ] **Step 5: Add computed for session status text**

```javascript
get sessionStatusText() {
    switch (this.sessionState) {
        case 'idle': return 'Select a campaign and press Start';
        case 'starting': return 'Connecting...';
        case 'ready': return this.autoDial ? `Auto-dialing · ${this.queueItems.length} remaining` : 'Paused · Conference active';
        case 'dialing': return 'Dialing...';
        case 'on_call': return 'Connected · ' + this.formatTime(this.callTimer);
        case 'disposition': return 'Disposition';
        default: return '';
    }
},
```

- [ ] **Step 6: Build, run Pint, commit**

```bash
npm run build
vendor/bin/pint --dirty --format agent
git add "resources/views/pages/prospector/⚡dialer.blade.php"
git commit -m "feat(prospector): rebuild dialer UI with session controls, horizontal queue, auto-dial"
```

---

### Task 10: Simplify TwilioVoiceController + Wire Inbound to Persistent Conference

**Files:**
- Modify: `app/Http/Controllers/Prospector/TwilioVoiceController.php`

- [ ] **Step 1: Update routeToAgent to use persistent conference**

In `routeToAgent()`, instead of creating a new conference for inbound calls, check if the agent has an active session and route into their existing conference:

```php
// In routeToAgent(), before creating a new conference:
$activeSession = DialerSession::where('agent_id', $agent->id)
    ->where('status', 'active')
    ->first();

if ($activeSession && $activeSession->conference_name) {
    // Route inbound caller into agent's existing persistent conference
    $conferenceName = $activeSession->conference_name;
} else {
    // No active session — create a new conference for this inbound call
    $conferenceName = 'inbound-' . Str::ulid();
}
```

- [ ] **Step 2: Run existing TwilioVoice tests**

```bash
php artisan test --filter=TwilioVoice --compact
```

Expected: All existing tests pass.

- [ ] **Step 3: Run Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Prospector/TwilioVoiceController.php
git commit -m "feat(prospector): route inbound calls into agent's persistent conference when active"
```

---

### Task 11: End-to-End Integration Test

**Files:**
- Create: `tests/Feature/Prospector/PersistentConferenceFlowTest.php`

- [ ] **Step 1: Write integration test**

```php
<?php

use App\Models\Prospector\Agent;
use App\Models\Prospector\Campaign;
use App\Models\Prospector\CampaignContact;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\PhoneNumber;
use App\Models\Prospector\TwilioAccount;
use App\Services\Prospector\ConferenceService;
use App\Services\Prospector\DialerOrchestrationService;

it('completes a full dial cycle: start session → dial → disposition → advance', function () {
    $account = TwilioAccount::factory()->create(['is_primary' => true, 'is_active' => true]);
    $company = Company::factory()->create(['twilio_account_id' => $account->id]);
    $phone = PhoneNumber::factory()->create([
        'company_id' => $company->id,
        'twilio_account_id' => $account->id,
        'role' => 'main',
    ]);
    $company->update(['main_phone_number_id' => $phone->id]);
    $agent = Agent::factory()->create(['company_id' => $company->id]);
    $campaign = Campaign::factory()->create([
        'company_id' => $company->id,
        'status' => 'active',
        'max_calls_per_day' => 3,
        'max_total_attempts' => 90,
    ]);

    // Add 2 contacts to campaign
    $contact1 = Contact::factory()->create(['company_id' => $company->id, 'phone' => '+13035551111']);
    $contact2 = Contact::factory()->create(['company_id' => $company->id, 'phone' => '+13035552222']);
    CampaignContact::create(['campaign_id' => $campaign->id, 'contact_id' => $contact1->id, 'status' => 'queued']);
    CampaignContact::create(['campaign_id' => $campaign->id, 'contact_id' => $contact2->id, 'status' => 'queued']);

    // Mock the ConferenceService so we don't hit Twilio
    $mock = Mockery::mock(ConferenceService::class);
    $mock->shouldReceive('dialContactIntoConference')->andReturn('CA_FAKE_SID');
    app()->instance(ConferenceService::class, $mock);

    $service = app(DialerOrchestrationService::class);

    // 1. Start session
    $session = $service->startSession($agent, $campaign);
    expect($session->conference_name)->toStartWith('agent-');
    expect($session->status)->toBe('active');

    // 2. Get next target and dial
    $target = $service->getNextDialTarget($session);
    expect($target)->not->toBeNull();

    $call = $service->recordDialAttempt($session, $target['contact'], $target['campaignContact'], $target['fromNumber']);
    expect($call->twilio_conference_sid)->toBe($session->conference_name);

    $callSid = $service->dialContactIntoConference($session, $target['contact'], $target['fromNumber'], $call);
    expect($callSid)->toBe('CA_FAKE_SID');

    // 3. Disposition
    $service->dispositionCall($call, 'not_interested');
    expect($call->fresh()->disposition)->toBe('not_interested');

    // 4. Get next target (should be contact2)
    $target2 = $service->getNextDialTarget($session);
    expect($target2)->not->toBeNull();
    expect($target2['contact']->id)->toBe($contact2->id);

    // 5. End session
    $service->endSession($session);
    expect($session->fresh()->status)->toBe('completed');
});
```

- [ ] **Step 2: Run the test**

```bash
php artisan test --filter=PersistentConferenceFlowTest --compact
```

Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Prospector/PersistentConferenceFlowTest.php
git commit -m "test(prospector): end-to-end persistent conference dial cycle integration test"
```

---

### Task 12: Deploy and Verify

- [ ] **Step 1: Run full test suite for prospector**

```bash
php artisan test --filter=Prospector --compact
```

Fix any failures.

- [ ] **Step 2: Build frontend**

```bash
npm run build
```

- [ ] **Step 3: Run Pint on all changed files**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 4: Commit and push**

```bash
git add -A
git commit -m "feat(prospector): complete dialer rebuild — persistent conference, campaign queues, auto-dial"
git push origin main
```

- [ ] **Step 5: Clear caches on production**

```bash
ssh forge@paintingmadeeasydenver.com "cd /home/forge/paintingmadeeasydenver.com && php artisan migrate --no-interaction && php artisan optimize:clear"
```

- [ ] **Step 6: Test on production**

1. Navigate to dialer page
2. Select a campaign from dropdown
3. Click Play → verify phone connects to conference
4. Verify queue shows contacts with call step indicators
5. Verify auto-dial picks next contact and dials
6. Test disposition → auto-advance
7. Test Pause/Resume
8. Test Stop → conference ends
