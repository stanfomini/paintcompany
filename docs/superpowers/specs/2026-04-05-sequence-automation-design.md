# Sequence Automation: Disposition Routing, Contact Triggers & DNC Cascade

**Date:** 2026-04-05
**Status:** Draft

## Context

The Prospector module has a fully built sequence engine (6 step types: SMS, email, RVM, call, wait, condition) that runs every minute via `prospector:execute-sequences`. However, contacts must be manually enrolled. Five trigger types are defined in the schema (`manual`, `new_contact`, `tag_added`, `disposition`, `webhook`) but only `manual` is implemented.

This spec adds automatic enrollment triggered by:
1. **Call dispositions** — agent clicks disposition button in dialer, contact auto-enrolls in mapped sequence
2. **New contact creation** — contact enters system via any entry point, auto-enrolls in matching sequences
3. **Tag added** — tag assigned to contact, auto-enrolls in sequences configured for that tag
4. **DNC cascade** — DNC disposition exits contact from ALL active sequences

## Requirements

- Two-level disposition→sequence mapping: global company defaults + per-campaign overrides
- Campaign override takes priority over company default
- When a new disposition triggers enrollment, exit the contact's current active enrollment first (one active auto-triggered sequence at a time)
- DNC exits ALL active sequence enrollments immediately
- `new_contact` and `tag_added` triggers use the sequence's `trigger_type` and `trigger_config` fields
- Settings UI for global defaults, campaign-level overrides, and trigger configuration

## Architecture

### New Service: `SequenceAutoEnrollmentService`

**File:** `app/Services/Prospector/SequenceAutoEnrollmentService.php`

Single service handling all auto-enrollment logic with consistent pattern: find target → exit previous → enroll.

#### Methods

```php
class SequenceAutoEnrollmentService
{
    public function __construct(
        private SequenceEngine $engine,
    ) {}

    /**
     * Handle disposition-triggered enrollment.
     * Check campaign override first, then company default.
     * Exit any active enrollment before enrolling in new sequence.
     */
    public function handleDisposition(Contact $contact, string $disposition, ?Campaign $campaign = null): void;

    /**
     * Handle new contact creation.
     * Find active sequences with trigger_type='new_contact' for the company.
     * Filter by trigger_config.sources if set.
     */
    public function handleNewContact(Contact $contact): void;

    /**
     * Handle tag(s) added to contact.
     * Find active sequences with trigger_type='tag_added' where trigger_config.tags
     * intersects with the newly added tags.
     */
    public function handleTagAdded(Contact $contact, array $newTags): void;

    /**
     * Handle DNC — exit ALL active sequence enrollments.
     */
    public function handleDnc(Contact $contact): void;

    /**
     * Exit current active enrollment for a contact (if any).
     * Used before enrolling in a new disposition-triggered sequence.
     */
    private function exitActiveEnrollments(Contact $contact, string $reason): void;

    /**
     * Resolve which sequence to enroll for a disposition.
     * Campaign override → company default → null.
     */
    private function resolveDispositionSequence(Contact $contact, string $disposition, ?Campaign $campaign): ?Sequence;
}
```

### Data Changes

#### Migration 1: Add `default_disposition_sequences` to companies

**Table:** `prospector_companies`
**Column:** `default_disposition_sequences` — JSON, nullable

Format:
```json
{
  "no_answer": 5,
  "voicemail": 3,
  "not_interested": 7
}
```

Maps disposition key → sequence ID. Null entries or missing keys mean "no auto-enrollment for this disposition."

#### Campaign `disposition_routes` JSON Extension

Existing format:
```json
{"no_answer": {"action": "stay"}}
```

Extended format (backward compatible):
```json
{"no_answer": {"action": "stay", "target_sequence_id": 5}}
```

No migration needed — JSON is schemaless. The `target_sequence_id` key is simply optional.

### Trigger Config Schemas

#### `trigger_type = 'new_contact'`

```json
{
  "sources": ["webhook", "manual", "inbound_call"]
}
```

- `sources`: Optional array. If set, only enroll contacts from these sources. If null/empty, enroll all new contacts.
- Source values come from the `source` field on `prospector_contacts`.

#### `trigger_type = 'tag_added'`

```json
{
  "tags": ["interested", "hot-lead", "callback"]
}
```

- `tags`: Required array. Enrollment triggers when ANY of these tags is added to a contact.

#### `trigger_type = 'disposition'`

Not used for the mapping approach. Disposition→sequence mapping is stored on company/campaign, not on the sequence itself. The `disposition` trigger type remains in the schema for potential future use but is not implemented as a sequence-level trigger.

## Integration Points

### 1. Disposition Handler

**File:** `app/Services/Prospector/DialerOrchestrationService.php`
**Method:** `disposition()` (line ~409)

After the existing switch block (stay/move/remove/dnc/archive), add:

```php
// Auto-enroll in sequence based on disposition
app(SequenceAutoEnrollmentService::class)
    ->handleDisposition($call->contact, $disposition, $call->campaign);
```

For DNC specifically, the `handleDisposition` method will detect the DNC disposition and delegate to `handleDnc()` which exits ALL enrollments. Alternatively, add explicit DNC handling in the existing `case 'dnc':` block.

### 2. Contact Creation

**File:** `app/Services/Prospector/ContactIntakeService.php`
**Method:** `findOrCreate()` (line ~38)

After creating a NEW contact (not finding existing):

```php
if ($isNew) {
    app(SequenceAutoEnrollmentService::class)->handleNewContact($contact);
}
```

The `findOrCreate()` method already returns the contact — we need to track whether it was newly created. Currently it uses `firstOrCreate` pattern; we'll check the `wasRecentlyCreated` flag or similar.

### 3. Tag Assignment

**File:** `resources/views/pages/prospector/⚡contacts.blade.php`

Two integration points:

a) **`bulkAddTag()` method** (line ~708) — after updating tags:
```php
$addedTags = array_diff($merged, $existing);
if ($addedTags) {
    app(SequenceAutoEnrollmentService::class)->handleTagAdded($contact, $addedTags);
}
```

b) **`saveContact()` method** (line ~237) — when editing a contact's tags, compare old vs new:
```php
$oldTags = $contact->tags ?? [];
// ... save contact ...
$newTags = array_diff($contact->fresh()->tags ?? [], $oldTags);
if ($newTags) {
    app(SequenceAutoEnrollmentService::class)->handleTagAdded($contact, $newTags);
}
```

c) **`ContactIntakeService::findOrCreate()`** — if initial contact has tags matching a `tag_added` sequence, this is handled by `handleNewContact()` which fires first. We do NOT also fire `handleTagAdded()` for initial tags to avoid double-enrollment.

### 4. Webhook Intake

**File:** `app/Services/Prospector/WebhookIntakeService.php`

No direct changes needed — `ContactIntakeService::findOrCreate()` handles `new_contact` trigger. Tags from webhooks are set during creation, handled by `handleNewContact()`.

## UI Changes

### Settings Page — New "Automation" Tab

**File:** `resources/views/pages/prospector/⚡settings.blade.php`

Add new tab "Automation" alongside existing tabs (Accounts, A2P, AI).

**Content:**
- Company selector dropdown (same pattern as existing tabs)
- Section: "Default Disposition Sequences"
  - For each disposition from `config('prospector.dispositions')`:
    - Row: `[Disposition Label]` — `[Sequence Dropdown ▼]`
    - Dropdown options: "None" + all active sequences for selected company
  - Save button updates `prospector_companies.default_disposition_sequences`
- Help text: "These are default sequences triggered when an agent dispositions a call. Individual campaigns can override these mappings."

**Component Properties:**
```php
public ?int $automationCompanyId = null;
public array $defaultSequences = [];  // disposition_key => sequence_id

public function loadAutomationSettings(): void;
public function saveDefaultSequences(): void;
```

### Campaign Detail — Sequence Column in Disposition Routes

**File:** `resources/views/pages/prospector/⚡campaign-detail.blade.php`

Extend the existing disposition routing section (line ~690):

Current row layout:
```
[Disposition Label] [Action ▼] [Campaign ▼ if move]
```

New row layout:
```
[Disposition Label] [Action ▼] [Campaign ▼ if move] → Then enroll: [Sequence ▼]
```

- Sequence dropdown shows "None (use default)" + active sequences for campaign's company
- "None (use default)" means fall through to company default
- Explicit "No sequence" option to override company default and skip enrollment

**Updated method:**
```php
public function updateDispositionRoute(
    string $disposition,
    string $action,
    ?int $targetCampaignId = null,
    ?int $targetSequenceId = null
): void;
```

### Sequence Builder — Trigger Config UI

**File:** `resources/views/pages/prospector/⚡sequence-builder.blade.php`

When `trigger_type` is set on the sequence, show trigger-specific configuration:

- **`new_contact`**: Checkbox group for source filter (webhook, manual, inbound_call, external). Empty = all sources.
- **`tag_added`**: Tag input field (comma-separated or chips). Required — must specify at least one tag.
- **`manual`**: No config needed (current behavior).
- **`disposition`**: Info text: "Disposition triggers are configured in Campaign Settings and Prospector Settings."

## Enrollment Source Tracking

Add `enrolled_via` column to `prospector_sequence_enrollments` (varchar, default: `'manual'`).

Values: `manual`, `disposition`, `new_contact`, `tag_added`

This column enables the "exit previous" logic to only exit disposition-triggered enrollments without touching manual or other auto-triggered ones.

**Migration:** Include in a new migration alongside the companies column change.

## Enrollment Behavior Rules

1. **Disposition enrollment**: One active disposition-triggered sequence at a time per contact. New disposition exits enrollments where `enrolled_via = 'disposition'` (reason: `disposition_change`), then enrolls in new. Other enrollment types (manual, new_contact, tag_added) are NOT exited.

2. **DNC**: Exits ALL active enrollments regardless of `enrolled_via` (reason: `do_not_call`). No new enrollment occurs.

3. **new_contact**: Can enroll in multiple sequences if multiple match. These are additive.

4. **tag_added**: Can enroll in multiple sequences if multiple tags match different sequences. Skips if already enrolled in that specific sequence.

5. **Manual enrollment** (existing): Unaffected by disposition auto-enrollment. Only DNC exits manual enrollments.

6. **Duplicate prevention**: `SequenceEnrollment` has a unique constraint on `(sequence_id, contact_id)`. Skip enrollment if already exists.

## Files to Create

| File | Purpose |
|------|---------|
| `app/Services/Prospector/SequenceAutoEnrollmentService.php` | Core auto-enrollment service |
| Migration: `add_sequence_automation_columns` | `default_disposition_sequences` JSON on companies + `enrolled_via` varchar on enrollments |

## Files to Modify

| File | Change |
|------|--------|
| `app/Services/Prospector/DialerOrchestrationService.php` | Call `handleDisposition()` after routing, `handleDnc()` on DNC |
| `app/Services/Prospector/ContactIntakeService.php` | Call `handleNewContact()` on new contact creation |
| `resources/views/pages/prospector/⚡contacts.blade.php` | Call `handleTagAdded()` in `bulkAddTag()` and `saveContact()` |
| `resources/views/pages/prospector/⚡settings.blade.php` | Add "Automation" tab with default sequence mappings |
| `resources/views/pages/prospector/⚡campaign-detail.blade.php` | Add sequence dropdown to disposition routing section |
| `resources/views/pages/prospector/⚡sequence-builder.blade.php` | Add trigger config UI for new_contact and tag_added |
| `app/Models/Prospector/Campaign.php` | Handle `target_sequence_id` in `getDispositionRoute()` |
| `app/Models/Prospector/Company.php` | Add `default_disposition_sequences` cast, accessor |

## Testing

### Unit Tests: `SequenceAutoEnrollmentService`

1. **Disposition enrollment**: disposition triggers correct sequence enrollment
2. **Two-level fallback**: campaign override → company default → no enrollment
3. **Exit previous**: new disposition exits active enrollment, enrolls in new
4. **DNC cascade**: DNC exits all active enrollments
5. **new_contact trigger**: new contact enrolls in matching sequences
6. **new_contact source filter**: only enrolls if source matches trigger_config
7. **tag_added trigger**: tag addition enrolls in matching sequences
8. **tag_added no match**: non-matching tags don't trigger enrollment
9. **Duplicate prevention**: already-enrolled contact skips re-enrollment
10. **Manual enrollment unaffected**: disposition change doesn't exit manual enrollments

### Integration Tests

11. **Full dialer flow**: disposition button → routing action + sequence enrollment
12. **Webhook intake**: new contact via webhook → new_contact trigger fires
13. **Bulk tag**: bulk tag operation → tag_added triggers for each contact
14. **DNC full cascade**: DNC → complete in campaign + exit all sequences

### UI Tests

15. **Settings automation tab**: save/load default disposition sequences
16. **Campaign sequence dropdown**: save/load target_sequence_id per disposition
17. **Sequence builder trigger config**: save/load trigger_config for new_contact and tag_added

## Verification

1. Create a test sequence with SMS step, set as active
2. Map "No Answer" disposition → test sequence in company defaults
3. Make a call in dialer, click "No Answer"
4. Verify contact is enrolled in sequence via database or sequence builder UI
5. Wait for sequence engine to execute (1 minute) → verify SMS sent
6. Make another call to same contact, click "Voicemail" with different sequence mapped
7. Verify previous enrollment exited, new enrollment created
8. Click "DNC" on a contact with active enrollment → verify enrollment exited
9. Create new contact → verify new_contact sequences fire
10. Add tag to contact → verify tag_added sequences fire

## Package Sync

After implementation, sync all changes to the `quick-claimstaker` package at `/home/forge/quick-claimstaker/`:
- Rename `prospector` → `claimstaker` in all new files (namespaces, tables, routes, config keys)
- Push to `stanfomini/quick-claimstaker` on GitHub
