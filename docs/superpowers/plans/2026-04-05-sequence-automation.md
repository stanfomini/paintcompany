# Sequence Automation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Auto-enroll contacts in sequences based on call dispositions, new contact creation, and tag additions — with DNC cascade to exit all enrollments.

**Architecture:** New `SequenceAutoEnrollmentService` handles all auto-enrollment. Disposition mapping uses two-level lookup (campaign override → company default). `new_contact` and `tag_added` use sequence-level `trigger_type`/`trigger_config`. One active disposition-triggered sequence per contact; DNC exits everything.

**Tech Stack:** Laravel 12, Livewire 4 SFC, Pest 4, Eloquent JSON columns

**Spec:** `docs/superpowers/specs/2026-04-05-sequence-automation-design.md`

---

## File Structure

| Action | File | Responsibility |
|--------|------|---------------|
| Create | `app/Services/Prospector/SequenceAutoEnrollmentService.php` | All auto-enrollment logic |
| Create | `database/migrations/2026_04_05_*_add_sequence_automation_columns.php` | DB schema changes |
| Create | `tests/Feature/Prospector/SequenceAutoEnrollmentTest.php` | All tests for the service |
| Modify | `app/Models/Prospector/Company.php` | Add `default_disposition_sequences` to fillable/casts |
| Modify | `app/Models/Prospector/SequenceEnrollment.php` | Add `enrolled_via` to fillable |
| Modify | `app/Services/Prospector/DialerOrchestrationService.php` | Call handleDisposition after routing |
| Modify | `app/Services/Prospector/ContactIntakeService.php` | Call handleNewContact on creation |
| Modify | `resources/views/pages/prospector/⚡contacts.blade.php` | Call handleTagAdded in bulkAddTag/save |
| Modify | `resources/views/pages/prospector/⚡settings.blade.php` | Add Automation tab |
| Modify | `resources/views/pages/prospector/⚡campaign-detail.blade.php` | Add sequence dropdown per disposition |
| Modify | `resources/views/pages/prospector/⚡sequence-builder.blade.php` | Trigger config UI for new_contact/tag_added |
| Modify | `resources/views/pages/prospector/⚡sequences.blade.php` | Trigger config fields in create/edit modal |

---

### Task 1: Migration & Model Updates

**Files:**
- Create: `database/migrations/2026_04_05_XXXXXX_add_sequence_automation_columns.php`
- Modify: `app/Models/Prospector/Company.php`
- Modify: `app/Models/Prospector/SequenceEnrollment.php`

- [ ] **Step 1: Create migration**

```bash
php artisan make:migration add_sequence_automation_columns --no-interaction
```

Edit the migration file:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospector_companies', function (Blueprint $table) {
            $table->json('default_disposition_sequences')->nullable()->after('default_rvm_template_id');
        });

        Schema::table('prospector_sequence_enrollments', function (Blueprint $table) {
            $table->string('enrolled_via', 20)->default('manual')->after('enrolled_by');
        });
    }

    public function down(): void
    {
        Schema::table('prospector_companies', function (Blueprint $table) {
            $table->dropColumn('default_disposition_sequences');
        });

        Schema::table('prospector_sequence_enrollments', function (Blueprint $table) {
            $table->dropColumn('enrolled_via');
        });
    }
};
```

- [ ] **Step 2: Run migration**

```bash
php artisan migrate --no-interaction
```

Expected: Migration runs successfully.

- [ ] **Step 3: Update Company model**

In `app/Models/Prospector/Company.php`, add `'default_disposition_sequences'` to the `$fillable` array (after `'default_rvm_template_id'` on line 36):

```php
    protected $fillable = [
        'ulid',
        'name',
        'twilio_account_id',
        'main_phone_number_id',
        'timezone',
        'business_hours_start',
        'business_hours_end',
        'business_days',
        'webhook_secret',
        'settings',
        'is_active',
        'color',
        'notes',
        'default_campaign_id',
        'default_rvm_template_id',
        'default_disposition_sequences',
    ];
```

Add to the `casts()` method (line ~42):

```php
    protected function casts(): array
    {
        return [
            'business_days' => 'array',
            'settings' => 'array',
            'is_active' => 'boolean',
            'default_disposition_sequences' => 'array',
        ];
    }
```

- [ ] **Step 4: Update SequenceEnrollment model**

In `app/Models/Prospector/SequenceEnrollment.php`, add `'enrolled_via'` to the `$fillable` array (after `'enrolled_by'` on line 28):

```php
    protected $fillable = [
        'sequence_id',
        'contact_id',
        'current_step_id',
        'status',
        'exit_reason',
        'next_execution_at',
        'enrolled_at',
        'completed_at',
        'enrolled_by',
        'enrolled_via',
    ];
```

- [ ] **Step 5: Run Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/*add_sequence_automation_columns* app/Models/Prospector/Company.php app/Models/Prospector/SequenceEnrollment.php
git commit -m "feat(prospector): add sequence automation migration and model updates"
```

---

### Task 2: SequenceAutoEnrollmentService — Disposition Handling

**Files:**
- Create: `app/Services/Prospector/SequenceAutoEnrollmentService.php`
- Create: `tests/Feature/Prospector/SequenceAutoEnrollmentTest.php`

- [ ] **Step 1: Write failing tests for disposition enrollment**

Create test file `tests/Feature/Prospector/SequenceAutoEnrollmentTest.php`:

```php
<?php

use App\Models\Prospector\Campaign;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\Sequence;
use App\Models\Prospector\SequenceEnrollment;
use App\Models\Prospector\SequenceStep;
use App\Services\Prospector\SequenceAutoEnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->contact = Contact::factory()->create(['company_id' => $this->company->id]);
    $this->sequence = Sequence::factory()->create([
        'company_id' => $this->company->id,
        'status' => 'active',
    ]);
    SequenceStep::factory()->create([
        'sequence_id' => $this->sequence->id,
        'sort_order' => 0,
        'type' => 'sms',
        'config' => ['body' => 'Hello {first_name}'],
    ]);
    $this->service = app(SequenceAutoEnrollmentService::class);
});

it('enrolls contact in sequence from company default disposition mapping', function () {
    $this->company->update([
        'default_disposition_sequences' => ['no_answer' => $this->sequence->id],
    ]);

    $this->service->handleDisposition($this->contact, 'no_answer');

    $enrollment = SequenceEnrollment::where('contact_id', $this->contact->id)
        ->where('sequence_id', $this->sequence->id)
        ->first();

    expect($enrollment)->not->toBeNull()
        ->and($enrollment->status)->toBe('active')
        ->and($enrollment->enrolled_via)->toBe('disposition');
});

it('uses campaign override over company default', function () {
    $overrideSequence = Sequence::factory()->create([
        'company_id' => $this->company->id,
        'status' => 'active',
    ]);
    SequenceStep::factory()->create([
        'sequence_id' => $overrideSequence->id,
        'sort_order' => 0,
        'type' => 'sms',
    ]);

    $this->company->update([
        'default_disposition_sequences' => ['no_answer' => $this->sequence->id],
    ]);

    $campaign = Campaign::factory()->create([
        'company_id' => $this->company->id,
        'disposition_routes' => [
            'no_answer' => ['action' => 'stay', 'target_sequence_id' => $overrideSequence->id],
        ],
    ]);

    $this->service->handleDisposition($this->contact, 'no_answer', $campaign);

    expect(SequenceEnrollment::where('contact_id', $this->contact->id)
        ->where('sequence_id', $overrideSequence->id)->exists())->toBeTrue()
        ->and(SequenceEnrollment::where('contact_id', $this->contact->id)
            ->where('sequence_id', $this->sequence->id)->exists())->toBeFalse();
});

it('exits previous disposition enrollment before enrolling in new sequence', function () {
    $newSequence = Sequence::factory()->create([
        'company_id' => $this->company->id,
        'status' => 'active',
    ]);
    SequenceStep::factory()->create([
        'sequence_id' => $newSequence->id,
        'sort_order' => 0,
        'type' => 'sms',
    ]);

    // First enrollment via disposition
    SequenceEnrollment::create([
        'sequence_id' => $this->sequence->id,
        'contact_id' => $this->contact->id,
        'status' => 'active',
        'enrolled_via' => 'disposition',
        'enrolled_at' => now(),
        'next_execution_at' => now(),
    ]);

    $this->company->update([
        'default_disposition_sequences' => ['voicemail' => $newSequence->id],
    ]);

    $this->service->handleDisposition($this->contact, 'voicemail');

    $old = SequenceEnrollment::where('contact_id', $this->contact->id)
        ->where('sequence_id', $this->sequence->id)->first();
    $new = SequenceEnrollment::where('contact_id', $this->contact->id)
        ->where('sequence_id', $newSequence->id)->first();

    expect($old->status)->toBe('exited')
        ->and($old->exit_reason)->toBe('disposition_change')
        ->and($new->status)->toBe('active')
        ->and($new->enrolled_via)->toBe('disposition');
});

it('does not exit manual enrollments on disposition change', function () {
    // Manual enrollment
    SequenceEnrollment::create([
        'sequence_id' => $this->sequence->id,
        'contact_id' => $this->contact->id,
        'status' => 'active',
        'enrolled_via' => 'manual',
        'enrolled_at' => now(),
        'next_execution_at' => now(),
    ]);

    $newSequence = Sequence::factory()->create([
        'company_id' => $this->company->id,
        'status' => 'active',
    ]);
    SequenceStep::factory()->create([
        'sequence_id' => $newSequence->id,
        'sort_order' => 0,
        'type' => 'sms',
    ]);

    $this->company->update([
        'default_disposition_sequences' => ['voicemail' => $newSequence->id],
    ]);

    $this->service->handleDisposition($this->contact, 'voicemail');

    $manual = SequenceEnrollment::where('contact_id', $this->contact->id)
        ->where('sequence_id', $this->sequence->id)->first();

    expect($manual->status)->toBe('active');
});

it('does nothing when no sequence mapped for disposition', function () {
    $this->service->handleDisposition($this->contact, 'no_answer');

    expect(SequenceEnrollment::where('contact_id', $this->contact->id)->count())->toBe(0);
});

it('skips enrollment when sequence is not active', function () {
    $this->sequence->update(['status' => 'draft']);
    $this->company->update([
        'default_disposition_sequences' => ['no_answer' => $this->sequence->id],
    ]);

    $this->service->handleDisposition($this->contact, 'no_answer');

    expect(SequenceEnrollment::where('contact_id', $this->contact->id)->count())->toBe(0);
});
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
php artisan test --compact --filter=SequenceAutoEnrollmentTest
```

Expected: All tests fail (class not found).

- [ ] **Step 3: Implement SequenceAutoEnrollmentService**

Create `app/Services/Prospector/SequenceAutoEnrollmentService.php`:

```php
<?php

namespace App\Services\Prospector;

use App\Models\Prospector\Campaign;
use App\Models\Prospector\Contact;
use App\Models\Prospector\Sequence;
use App\Models\Prospector\SequenceEnrollment;
use Illuminate\Support\Facades\Log;

class SequenceAutoEnrollmentService
{
    public function __construct(
        private SequenceEngine $engine,
    ) {}

    /**
     * Handle disposition-triggered enrollment.
     * Resolves target sequence from campaign override or company default.
     * Exits any active disposition-triggered enrollment before enrolling.
     */
    public function handleDisposition(Contact $contact, string $disposition, ?Campaign $campaign = null): void
    {
        if ($disposition === 'dnc') {
            $this->handleDnc($contact);

            return;
        }

        $sequence = $this->resolveDispositionSequence($contact, $disposition, $campaign);

        if (! $sequence) {
            return;
        }

        // Exit any active disposition-triggered enrollment
        $this->exitActiveEnrollments($contact, 'disposition_change', 'disposition');

        // Skip if already enrolled in this exact sequence
        $existing = SequenceEnrollment::where('sequence_id', $sequence->id)
            ->where('contact_id', $contact->id)
            ->whereIn('status', ['active', 'paused'])
            ->exists();

        if ($existing) {
            return;
        }

        $enrollment = $this->engine->enrollContact($contact, $sequence);
        $enrollment->update(['enrolled_via' => 'disposition']);

        Log::info('SequenceAutoEnrollment: disposition enrollment', [
            'contact_id' => $contact->id,
            'disposition' => $disposition,
            'sequence_id' => $sequence->id,
        ]);
    }

    /**
     * Handle DNC — exit ALL active sequence enrollments.
     */
    public function handleDnc(Contact $contact): void
    {
        $this->exitActiveEnrollments($contact, 'do_not_call');

        Log::info('SequenceAutoEnrollment: DNC cascade', [
            'contact_id' => $contact->id,
        ]);
    }

    /**
     * Handle new contact creation.
     * Find active sequences with trigger_type='new_contact' for the company.
     */
    public function handleNewContact(Contact $contact): void
    {
        $sequences = Sequence::where('company_id', $contact->company_id)
            ->where('trigger_type', 'new_contact')
            ->active()
            ->get();

        foreach ($sequences as $sequence) {
            // Filter by source if trigger_config.sources is set
            $sources = $sequence->trigger_config['sources'] ?? null;
            if ($sources && ! in_array($contact->source, $sources)) {
                continue;
            }

            // Skip if already enrolled
            $existing = SequenceEnrollment::where('sequence_id', $sequence->id)
                ->where('contact_id', $contact->id)
                ->exists();

            if ($existing) {
                continue;
            }

            $enrollment = $this->engine->enrollContact($contact, $sequence);
            $enrollment->update(['enrolled_via' => 'new_contact']);

            Log::info('SequenceAutoEnrollment: new_contact enrollment', [
                'contact_id' => $contact->id,
                'sequence_id' => $sequence->id,
            ]);
        }
    }

    /**
     * Handle tag(s) added to contact.
     * Find active sequences with trigger_type='tag_added' where trigger_config.tags matches.
     */
    public function handleTagAdded(Contact $contact, array $newTags): void
    {
        if (empty($newTags)) {
            return;
        }

        $sequences = Sequence::where('company_id', $contact->company_id)
            ->where('trigger_type', 'tag_added')
            ->active()
            ->get();

        foreach ($sequences as $sequence) {
            $triggerTags = $sequence->trigger_config['tags'] ?? [];
            if (empty($triggerTags)) {
                continue;
            }

            // Check if any of the new tags match the trigger tags
            if (empty(array_intersect($newTags, $triggerTags))) {
                continue;
            }

            // Skip if already enrolled
            $existing = SequenceEnrollment::where('sequence_id', $sequence->id)
                ->where('contact_id', $contact->id)
                ->whereIn('status', ['active', 'paused'])
                ->exists();

            if ($existing) {
                continue;
            }

            $enrollment = $this->engine->enrollContact($contact, $sequence);
            $enrollment->update(['enrolled_via' => 'tag_added']);

            Log::info('SequenceAutoEnrollment: tag_added enrollment', [
                'contact_id' => $contact->id,
                'sequence_id' => $sequence->id,
                'matched_tags' => array_intersect($newTags, $triggerTags),
            ]);
        }
    }

    /**
     * Exit active enrollments for a contact.
     * If $enrolledVia is provided, only exit enrollments with that source.
     * If null, exit ALL active enrollments.
     */
    private function exitActiveEnrollments(Contact $contact, string $reason, ?string $enrolledVia = null): void
    {
        $query = SequenceEnrollment::where('contact_id', $contact->id)
            ->where('status', 'active');

        if ($enrolledVia) {
            $query->where('enrolled_via', $enrolledVia);
        }

        $query->each(fn (SequenceEnrollment $e) => $e->exit($reason));
    }

    /**
     * Resolve which sequence to enroll for a disposition.
     * Campaign override takes priority over company default.
     * Returns null if no mapping exists or sequence is not active.
     */
    private function resolveDispositionSequence(Contact $contact, string $disposition, ?Campaign $campaign): ?Sequence
    {
        $sequenceId = null;

        // Check campaign-level override
        if ($campaign) {
            $route = $campaign->disposition_routes[$disposition] ?? null;
            $sequenceId = $route['target_sequence_id'] ?? null;
        }

        // Fall back to company default
        if (! $sequenceId) {
            $company = $contact->company ?? Company::find($contact->company_id);
            $defaults = $company?->default_disposition_sequences ?? [];
            $sequenceId = $defaults[$disposition] ?? null;
        }

        if (! $sequenceId) {
            return null;
        }

        // Only return active sequences
        return Sequence::where('id', $sequenceId)->active()->first();
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test --compact --filter=SequenceAutoEnrollmentTest
```

Expected: All 6 tests pass.

- [ ] **Step 5: Run Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Prospector/SequenceAutoEnrollmentService.php tests/Feature/Prospector/SequenceAutoEnrollmentTest.php
git commit -m "feat(prospector): add SequenceAutoEnrollmentService with disposition handling"
```

---

### Task 3: DNC Cascade & Trigger Tests

**Files:**
- Modify: `tests/Feature/Prospector/SequenceAutoEnrollmentTest.php`

- [ ] **Step 1: Write failing tests for DNC cascade and triggers**

Append to `tests/Feature/Prospector/SequenceAutoEnrollmentTest.php`:

```php
it('exits all active enrollments on DNC', function () {
    $sequence2 = Sequence::factory()->create([
        'company_id' => $this->company->id,
        'status' => 'active',
    ]);

    // Two active enrollments — different sources
    SequenceEnrollment::create([
        'sequence_id' => $this->sequence->id,
        'contact_id' => $this->contact->id,
        'status' => 'active',
        'enrolled_via' => 'disposition',
        'enrolled_at' => now(),
        'next_execution_at' => now(),
    ]);
    SequenceEnrollment::create([
        'sequence_id' => $sequence2->id,
        'contact_id' => $this->contact->id,
        'status' => 'active',
        'enrolled_via' => 'manual',
        'enrolled_at' => now(),
        'next_execution_at' => now(),
    ]);

    $this->service->handleDnc($this->contact);

    expect(SequenceEnrollment::where('contact_id', $this->contact->id)
        ->where('status', 'active')->count())->toBe(0)
        ->and(SequenceEnrollment::where('contact_id', $this->contact->id)
            ->where('status', 'exited')
            ->where('exit_reason', 'do_not_call')->count())->toBe(2);
});

it('disposition with dnc calls handleDnc', function () {
    SequenceEnrollment::create([
        'sequence_id' => $this->sequence->id,
        'contact_id' => $this->contact->id,
        'status' => 'active',
        'enrolled_via' => 'disposition',
        'enrolled_at' => now(),
        'next_execution_at' => now(),
    ]);

    $this->service->handleDisposition($this->contact, 'dnc');

    expect(SequenceEnrollment::where('contact_id', $this->contact->id)
        ->where('status', 'exited')
        ->where('exit_reason', 'do_not_call')->count())->toBe(1);
});

it('enrolls new contact in matching new_contact sequences', function () {
    $this->sequence->update([
        'trigger_type' => 'new_contact',
        'trigger_config' => null,
    ]);

    $this->service->handleNewContact($this->contact);

    $enrollment = SequenceEnrollment::where('contact_id', $this->contact->id)
        ->where('sequence_id', $this->sequence->id)->first();

    expect($enrollment)->not->toBeNull()
        ->and($enrollment->enrolled_via)->toBe('new_contact');
});

it('filters new_contact by source when trigger_config.sources set', function () {
    $this->sequence->update([
        'trigger_type' => 'new_contact',
        'trigger_config' => ['sources' => ['webhook']],
    ]);

    // Contact with non-matching source
    $this->contact->update(['source' => 'manual']);
    $this->service->handleNewContact($this->contact);
    expect(SequenceEnrollment::where('contact_id', $this->contact->id)->count())->toBe(0);

    // Contact with matching source
    $this->contact->update(['source' => 'webhook']);
    $this->service->handleNewContact($this->contact);
    expect(SequenceEnrollment::where('contact_id', $this->contact->id)->count())->toBe(1);
});

it('enrolls contact when matching tag added', function () {
    $this->sequence->update([
        'trigger_type' => 'tag_added',
        'trigger_config' => ['tags' => ['hot-lead', 'interested']],
    ]);

    $this->service->handleTagAdded($this->contact, ['hot-lead']);

    $enrollment = SequenceEnrollment::where('contact_id', $this->contact->id)
        ->where('sequence_id', $this->sequence->id)->first();

    expect($enrollment)->not->toBeNull()
        ->and($enrollment->enrolled_via)->toBe('tag_added');
});

it('does not enroll when tag does not match trigger config', function () {
    $this->sequence->update([
        'trigger_type' => 'tag_added',
        'trigger_config' => ['tags' => ['hot-lead']],
    ]);

    $this->service->handleTagAdded($this->contact, ['cold-lead']);

    expect(SequenceEnrollment::where('contact_id', $this->contact->id)->count())->toBe(0);
});

it('skips tag enrollment if already enrolled in sequence', function () {
    $this->sequence->update([
        'trigger_type' => 'tag_added',
        'trigger_config' => ['tags' => ['hot-lead']],
    ]);

    SequenceEnrollment::create([
        'sequence_id' => $this->sequence->id,
        'contact_id' => $this->contact->id,
        'status' => 'active',
        'enrolled_via' => 'tag_added',
        'enrolled_at' => now(),
        'next_execution_at' => now(),
    ]);

    $this->service->handleTagAdded($this->contact, ['hot-lead']);

    expect(SequenceEnrollment::where('contact_id', $this->contact->id)
        ->where('sequence_id', $this->sequence->id)->count())->toBe(1);
});
```

- [ ] **Step 2: Run tests**

```bash
php artisan test --compact --filter=SequenceAutoEnrollmentTest
```

Expected: All 13 tests pass.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Prospector/SequenceAutoEnrollmentTest.php
git commit -m "test(prospector): add DNC cascade, new_contact, and tag_added tests"
```

---

### Task 4: Integration — DialerOrchestrationService

**Files:**
- Modify: `app/Services/Prospector/DialerOrchestrationService.php`

- [ ] **Step 1: Add auto-enrollment call after disposition routing**

In `app/Services/Prospector/DialerOrchestrationService.php`, find the `disposition()` method. After the switch block and before the campaign stats increment (after the closing `}` of the `if ($entry)` block around line 446), add the auto-enrollment call:

```php
        // Auto-enroll in sequence based on disposition
        app(SequenceAutoEnrollmentService::class)
            ->handleDisposition($call->contact, $disposition, $call->campaign);
```

Also add the import at the top of the file:

```php
use App\Services\Prospector\SequenceAutoEnrollmentService;
```

- [ ] **Step 2: Run existing dialer tests to verify no regression**

```bash
php artisan test --compact --filter=DialerOrchestration
```

Expected: All existing tests pass.

- [ ] **Step 3: Run Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Prospector/DialerOrchestrationService.php
git commit -m "feat(prospector): hook disposition auto-enrollment into dialer orchestration"
```

---

### Task 5: Integration — ContactIntakeService (new_contact trigger)

**Files:**
- Modify: `app/Services/Prospector/ContactIntakeService.php`

- [ ] **Step 1: Add handleNewContact call after contact creation**

In `app/Services/Prospector/ContactIntakeService.php`, the `findOrCreate()` method returns early when a contact exists (line ~35). After the `broadcast(new NewLeadArrived(...))` call on line ~62 and before the final `return`, add:

```php
            app(SequenceAutoEnrollmentService::class)->handleNewContact($contact);
```

Add the import at top:

```php
use App\Services\Prospector\SequenceAutoEnrollmentService;
```

The modified section (around lines 60-66) should look like:

```php
            broadcast(new NewLeadArrived($contact, $company));

            app(SequenceAutoEnrollmentService::class)->handleNewContact($contact);

            return new ContactIntakeResult(contact: $contact, wasCreated: true);
```

- [ ] **Step 2: Run existing intake tests**

```bash
php artisan test --compact --filter=ContactIntake
```

Expected: All existing tests pass.

- [ ] **Step 3: Run Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Prospector/ContactIntakeService.php
git commit -m "feat(prospector): hook new_contact trigger into ContactIntakeService"
```

---

### Task 6: Integration — Contacts UI (tag_added trigger)

**Files:**
- Modify: `resources/views/pages/prospector/⚡contacts.blade.php`

- [ ] **Step 1: Add handleTagAdded to bulkAddTag method**

In `resources/views/pages/prospector/⚡contacts.blade.php`, find the `bulkAddTag()` method (line ~708). After the `foreach` loop that updates each contact's tags (around line 725), add the tag trigger call inside the loop:

The modified loop should look like:

```php
    $contacts = Contact::whereIn('id', $this->selectedContacts)->get();
    foreach ($contacts as $contact) {
        $existing = $contact->tags ?? [];
        $merged = array_unique(array_merge($existing, $newTags));
        $contact->update(['tags' => array_values($merged)]);

        // Trigger tag_added sequences for newly added tags
        $addedTags = array_values(array_diff($merged, $existing));
        if (! empty($addedTags)) {
            app(\App\Services\Prospector\SequenceAutoEnrollmentService::class)
                ->handleTagAdded($contact, $addedTags);
        }
    }
```

- [ ] **Step 2: Add handleTagAdded to save method for editing contacts**

In the `save()` method (line ~221), when editing an existing contact (`if ($this->editingId)`), capture old tags and compare after save. The modified section around lines 252-258:

```php
    if ($this->editingId) {
        $contact = Contact::findOrFail($this->editingId);
        $oldTags = $contact->tags ?? [];
        $contact->update($data);

        // Trigger tag_added sequences for newly added tags
        $newlyAdded = array_values(array_diff($contact->fresh()->tags ?? [], $oldTags));
        if (! empty($newlyAdded)) {
            app(\App\Services\Prospector\SequenceAutoEnrollmentService::class)
                ->handleTagAdded($contact, $newlyAdded);
        }
    } else {
```

- [ ] **Step 3: Run Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add "resources/views/pages/prospector/⚡contacts.blade.php"
git commit -m "feat(prospector): hook tag_added trigger into contacts UI"
```

---

### Task 7: UI — Settings Automation Tab

**Files:**
- Modify: `resources/views/pages/prospector/⚡settings.blade.php`

- [ ] **Step 1: Add component properties for automation tab**

In the PHP section of `⚡settings.blade.php`, add these properties after the existing properties (around line 63):

```php
    // Automation tab
    public ?int $automationCompanyId = null;
    public array $defaultSequences = [];
```

- [ ] **Step 2: Add methods for loading/saving automation settings**

Add these methods in the PHP section:

```php
    public function loadAutomationSettings(): void
    {
        if (! $this->automationCompanyId) {
            $this->defaultSequences = [];

            return;
        }

        $company = \App\Models\Prospector\Company::find($this->automationCompanyId);
        $this->defaultSequences = $company?->default_disposition_sequences ?? [];
    }

    public function saveDefaultSequences(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $company = \App\Models\Prospector\Company::findOrFail($this->automationCompanyId);
        $filtered = array_filter($this->defaultSequences, fn ($v) => ! empty($v));
        $company->update(['default_disposition_sequences' => $filtered ?: null]);
        session()->flash('automation-saved', __('Default sequences saved.'));
    }

    #[Computed]
    public function automationSequences(): \Illuminate\Support\Collection
    {
        if (! $this->automationCompanyId) {
            return collect();
        }

        return \App\Models\Prospector\Sequence::where('company_id', $this->automationCompanyId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get();
    }
```

- [ ] **Step 3: Add the Automation tab button**

In the tab navigation section (around line 530, after the AI Intelligence tab button), add:

```blade
    <button
        wire:click="$set('activeTab', 'automation')"
        @class([
            'px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors',
            'border-blue-500 text-blue-600 dark:text-blue-400' => $activeTab === 'automation',
            'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' => $activeTab !== 'automation',
        ])
    >
        {{ __('Automation') }}
    </button>
```

- [ ] **Step 4: Add the Automation tab content**

After the last `@endif` for the AI tab content (around line 1105), add:

```blade
@if ($activeTab === 'automation')
    <div class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Sequence Automation') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Map dispositions to sequences. When an agent clicks a disposition, the contact auto-enrolls in the mapped sequence. Individual campaigns can override these defaults.') }}</flux:text>
        </div>

        {{-- Company selector --}}
        <div class="max-w-xs">
            <flux:select wire:model="automationCompanyId" wire:change="loadAutomationSettings" :label="__('Company')">
                <flux:select.option value="">{{ __('Select company...') }}</flux:select.option>
                @foreach($this->companies as $company)
                    <flux:select.option :value="$company->id">{{ $company->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        @if($automationCompanyId)
            <div class="rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:heading size="sm" class="mb-4">{{ __('Default Disposition → Sequence Mapping') }}</flux:heading>

                <div class="space-y-3">
                    @foreach(config('prospector.dispositions', []) as $key => $config)
                        <div class="flex items-center gap-4" wire:key="auto-{{ $key }}">
                            <div class="w-40 text-sm font-medium">
                                {{ $config['label'] ?? ucfirst(str_replace('_', ' ', $key)) }}
                            </div>
                            <select
                                wire:model.live="defaultSequences.{{ $key }}"
                                class="w-64 rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                            >
                                <option value="">{{ __('None') }}</option>
                                @foreach($this->automationSequences as $seq)
                                    <option value="{{ $seq->id }}">{{ $seq->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                </div>

                <div class="mt-5 flex items-center gap-3">
                    <flux:button wire:click="saveDefaultSequences" variant="primary" size="sm">
                        {{ __('Save Defaults') }}
                    </flux:button>
                    @if(session('automation-saved'))
                        <flux:text class="text-green-600 dark:text-green-400">{{ session('automation-saved') }}</flux:text>
                    @endif
                </div>
            </div>
        @endif
    </div>
@endif
```

- [ ] **Step 5: Run Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add "resources/views/pages/prospector/⚡settings.blade.php"
git commit -m "feat(prospector): add Automation tab to settings with default sequence mappings"
```

---

### Task 8: UI — Campaign Detail Sequence Dropdown

**Files:**
- Modify: `resources/views/pages/prospector/⚡campaign-detail.blade.php`

- [ ] **Step 1: Update the updateDispositionRoute method**

In `⚡campaign-detail.blade.php`, replace the `updateDispositionRoute` method (lines 107-114) with:

```php
    public function updateDispositionRoute(string $disposition, string $action, ?int $targetCampaignId = null, ?int $targetSequenceId = null): void
    {
        $this->dispositionRoutes[$disposition] = array_filter([
            'action' => $action,
            'target_campaign_id' => $action === 'move' ? $targetCampaignId : null,
            'target_sequence_id' => $targetSequenceId,
        ]);
        $this->saveDispositionRoutes();
    }

    public function updateDispositionSequence(string $disposition, ?int $sequenceId): void
    {
        $route = $this->dispositionRoutes[$disposition] ?? ['action' => 'stay'];
        $route['target_sequence_id'] = $sequenceId ?: null;
        $this->dispositionRoutes[$disposition] = array_filter($route);
        $this->saveDispositionRoutes();
    }
```

- [ ] **Step 2: Add computed property for available sequences**

Add a computed property after the `otherCampaigns` computed (around line 152):

```php
    #[Computed]
    public function availableSequences(): \Illuminate\Support\Collection
    {
        $campaign = \App\Models\Prospector\Campaign::find($this->campaignId);
        if (! $campaign?->company_id) {
            return collect();
        }

        return \App\Models\Prospector\Sequence::where('company_id', $campaign->company_id)
            ->where('status', 'active')
            ->orderBy('name')
            ->get();
    }
```

- [ ] **Step 3: Add sequence dropdown to disposition routing UI**

In the disposition routing template section (around line 720), after the campaign `<select>` for `move` action and before the closing `</div>` of each disposition row, add the sequence dropdown. Replace the inner loop content:

Find the existing `@foreach` loop for disposition routes and replace the inner `<div>` (the one with `wire:key="route-{{ $key }}"`) with:

```blade
                <div class="flex flex-wrap items-center gap-3" wire:key="route-{{ $key }}">
                    <div class="w-36 text-sm font-medium">{{ $config['label'] ?? ucfirst(str_replace('_', ' ', $key)) }}</div>
                    <select
                        class="rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                        wire:change="updateDispositionRoute('{{ $key }}', $event.target.value)"
                    >
                        <option value="stay" @selected($action === 'stay')>Stay in campaign</option>
                        <option value="move" @selected($action === 'move')>Move to campaign</option>
                        <option value="remove" @selected($action === 'remove')>Remove (complete)</option>
                        <option value="dnc" @selected($action === 'dnc')>Do Not Call</option>
                    </select>
                    @if($action === 'move')
                        <select
                            class="rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                            wire:change="updateDispositionRoute('{{ $key }}', 'move', $event.target.value)"
                        >
                            <option value="">Select campaign...</option>
                            @foreach($this->otherCampaigns as $camp)
                                <option value="{{ $camp->id }}" @selected($targetId == $camp->id)>{{ $camp->name }}</option>
                            @endforeach
                        </select>
                    @endif
                    @php $seqId = $route['target_sequence_id'] ?? null; @endphp
                    <select
                        class="rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                        wire:change="updateDispositionSequence('{{ $key }}', $event.target.value)"
                    >
                        <option value="">{{ __('No sequence (use default)') }}</option>
                        @foreach($this->availableSequences as $seq)
                            <option value="{{ $seq->id }}" @selected($seqId == $seq->id)>{{ $seq->name }}</option>
                        @endforeach
                    </select>
                </div>
```

- [ ] **Step 4: Run Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add "resources/views/pages/prospector/⚡campaign-detail.blade.php"
git commit -m "feat(prospector): add sequence dropdown to campaign disposition routing"
```

---

### Task 9: UI — Sequence Builder Trigger Config

**Files:**
- Modify: `resources/views/pages/prospector/⚡sequences.blade.php`

- [ ] **Step 1: Add trigger config properties**

In the PHP section of `⚡sequences.blade.php`, find the `$triggerType` property (around line 37) and add below it:

```php
    public array $triggerSources = [];
    public string $triggerTagsInput = '';
```

- [ ] **Step 2: Update create/edit save to include trigger_config**

Find the Sequence creation code (around line 151) and update to include trigger_config:

```php
    $triggerConfig = match ($this->triggerType) {
        'new_contact' => ! empty($this->triggerSources) ? ['sources' => $this->triggerSources] : null,
        'tag_added' => ! empty($this->triggerTagsInput)
            ? ['tags' => array_map('trim', explode(',', $this->triggerTagsInput))]
            : null,
        default => null,
    };

    $data = array_merge($validated, ['trigger_config' => $triggerConfig]);
```

Use `$data` instead of `$validated` in the `Sequence::create()` or `->update()` call.

- [ ] **Step 3: Hydrate trigger config on edit**

In the edit method (where `$triggerType` is set from the sequence being edited), add:

```php
    $this->triggerSources = $sequence->trigger_config['sources'] ?? [];
    $this->triggerTagsInput = implode(', ', $sequence->trigger_config['tags'] ?? []);
```

- [ ] **Step 4: Add trigger config UI below trigger type selector**

In the template, after the trigger type `<flux:select>` (around line 436), add:

```blade
    @if($triggerType === 'new_contact')
        <div class="mt-3">
            <flux:text size="sm" class="mb-2 font-medium">{{ __('Source Filter (optional)') }}</flux:text>
            <flux:text size="sm" class="mb-2 text-zinc-500">{{ __('Only enroll contacts from these sources. Leave empty for all sources.') }}</flux:text>
            <div class="flex flex-wrap gap-3">
                @foreach(['webhook', 'manual', 'inbound_call', 'external'] as $src)
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="triggerSources" value="{{ $src }}" class="rounded border-zinc-300 text-blue-600">
                        {{ ucfirst(str_replace('_', ' ', $src)) }}
                    </label>
                @endforeach
            </div>
        </div>
    @endif

    @if($triggerType === 'tag_added')
        <div class="mt-3">
            <flux:input
                wire:model="triggerTagsInput"
                :label="__('Trigger Tags')"
                :description="__('Comma-separated tags. Enrollment triggers when ANY of these tags is added to a contact.')"
                placeholder="hot-lead, interested, callback"
            />
        </div>
    @endif

    @if($triggerType === 'disposition')
        <div class="mt-3">
            <flux:text size="sm" class="text-zinc-500">{{ __('Disposition triggers are configured in Campaign Settings and Prospector Settings → Automation tab.') }}</flux:text>
        </div>
    @endif
```

- [ ] **Step 5: Reset trigger config fields on modal close/reset**

In the reset method for the create/edit modal, add:

```php
    $this->triggerSources = [];
    $this->triggerTagsInput = '';
```

- [ ] **Step 6: Run Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add "resources/views/pages/prospector/⚡sequences.blade.php"
git commit -m "feat(prospector): add trigger config UI for new_contact and tag_added sequences"
```

---

### Task 10: Integration Tests

**Files:**
- Create or Modify: `tests/Feature/Prospector/SequenceAutoEnrollmentTest.php`

- [ ] **Step 1: Write integration test for full dialer disposition flow**

Append to the test file:

```php
it('disposition button in dialer triggers sequence enrollment end-to-end', function () {
    $company = Company::factory()->create([
        'default_disposition_sequences' => [],
    ]);
    $contact = Contact::factory()->create(['company_id' => $company->id]);
    $sequence = Sequence::factory()->create([
        'company_id' => $company->id,
        'status' => 'active',
    ]);
    SequenceStep::factory()->create([
        'sequence_id' => $sequence->id,
        'sort_order' => 0,
        'type' => 'sms',
        'config' => ['body' => 'Follow up'],
    ]);
    $campaign = Campaign::factory()->create([
        'company_id' => $company->id,
        'disposition_routes' => [
            'no_answer' => ['action' => 'stay', 'target_sequence_id' => $sequence->id],
        ],
    ]);

    $call = \App\Models\Prospector\Call::factory()->create([
        'company_id' => $company->id,
        'contact_id' => $contact->id,
        'campaign_id' => $campaign->id,
        'status' => 'in-progress',
    ]);

    // Create campaign contact entry
    \App\Models\Prospector\CampaignContact::create([
        'campaign_id' => $campaign->id,
        'contact_id' => $contact->id,
        'status' => 'calling',
        'entered_at' => now(),
    ]);

    $service = app(\App\Services\Prospector\DialerOrchestrationService::class);
    $service->disposition($call, 'no_answer');

    $enrollment = SequenceEnrollment::where('contact_id', $contact->id)
        ->where('sequence_id', $sequence->id)->first();

    expect($enrollment)->not->toBeNull()
        ->and($enrollment->enrolled_via)->toBe('disposition');
});
```

- [ ] **Step 2: Run the integration test**

```bash
php artisan test --compact --filter="disposition button in dialer"
```

Expected: Test passes.

- [ ] **Step 3: Run full test suite for the file**

```bash
php artisan test --compact --filter=SequenceAutoEnrollmentTest
```

Expected: All tests pass.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Prospector/SequenceAutoEnrollmentTest.php
git commit -m "test(prospector): add integration test for dialer disposition enrollment"
```

---

### Task 11: Package Sync to quick-claimstaker

**Files:**
- Multiple files in `/home/forge/quick-claimstaker/`

- [ ] **Step 1: Copy new service to package**

```bash
cp app/Services/Prospector/SequenceAutoEnrollmentService.php /home/forge/quick-claimstaker/src/Services/SequenceAutoEnrollmentService.php
```

Update the namespace from `App\Services\Prospector` to the package namespace (check existing files in `/home/forge/quick-claimstaker/src/Services/` for the correct namespace pattern — likely `Stanfomini\QuickClaimstaker\Services`).

Update all `App\Models\Prospector\*` references to the package namespace equivalent.

- [ ] **Step 2: Copy migration to package**

```bash
cp database/migrations/*add_sequence_automation_columns* /home/forge/quick-claimstaker/database/migrations/
```

Update table names from `prospector_*` to `claimstaker_*` in the migration.

- [ ] **Step 3: Copy test file to package**

```bash
cp tests/Feature/Prospector/SequenceAutoEnrollmentTest.php /home/forge/quick-claimstaker/tests/Feature/SequenceAutoEnrollmentTest.php
```

Update namespace references.

- [ ] **Step 4: Update modified package files**

For each modified file in the main app, apply the same changes to the package equivalent:
- `DialerOrchestrationService.php` → add auto-enrollment hook
- `ContactIntakeService.php` → add new_contact hook
- Contacts blade → add tag_added hooks
- Settings blade → add Automation tab
- Campaign detail blade → add sequence dropdown
- Sequences blade → add trigger config UI
- `Company.php` model → add `default_disposition_sequences` to fillable/casts
- `SequenceEnrollment.php` model → add `enrolled_via` to fillable

Use `grep -r 'prospector' /home/forge/quick-claimstaker/src/` to verify all namespace renames are correct.

- [ ] **Step 5: Push package changes**

```bash
cd /home/forge/quick-claimstaker
git add -A
git commit -m "feat: add sequence automation (disposition, new_contact, tag_added triggers)"
git push origin main
```

---

## Verification Checklist

After all tasks are complete, verify end-to-end:

1. [ ] Run full test suite: `php artisan test --compact --filter=SequenceAutoEnrollmentTest` — all 14+ tests pass
2. [ ] Run existing prospector tests: `php artisan test --compact --filter=Prospector` — no regressions
3. [ ] Visit `/prospector/settings` → Automation tab loads, company selector works, disposition dropdowns populate with active sequences, save persists
4. [ ] Visit a campaign detail page → Disposition routing section shows sequence dropdown per disposition, saves correctly
5. [ ] Visit sequences page → Create sequence with `new_contact` trigger type → source filter checkboxes appear
6. [ ] Visit sequences page → Create sequence with `tag_added` trigger type → tags input appears
7. [ ] Run `vendor/bin/pint --dirty --format agent` — no formatting issues
