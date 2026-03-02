# Chat Visibility Permissions Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Scope chat visibility in the communications hub by permissions, create User records for setter support chats, and add a `source` column to `chat_threads` for efficient filtering.

**Architecture:** Two new permissions (`chat.view_website_leads`, `chat.view_setter_support`) gate visibility of support threads in the communications hub. The `CommunicationsContactService` filters contacts based on all `chat.*` permissions. Setter support chat creates a `User` (type=null) for identity/notification continuity. A `source` column on `chat_threads` avoids JOINing `guest_chat_sessions` in every query.

**Tech Stack:** Laravel 12, Livewire 4 SFC, Spatie Permission, Pest 4

**Design Doc:** `docs/plans/2026-03-02-chat-visibility-permissions-design.md`

---

### Task 1: Migration — Add `source` column to `chat_threads`

**Files:**
- Create: `database/migrations/2026_03_02_100000_add_source_to_chat_threads_table.php`

**Step 1: Create the migration**

```bash
php artisan make:migration add_source_to_chat_threads_table --no-interaction
```

Then replace contents with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_threads', function (Blueprint $table) {
            $table->string('source')->nullable()->after('type');
            $table->index('source');
        });

        // Backfill existing support threads
        // Setter support threads: guest_chat_sessions with metadata->source = 'setter_support'
        $setterThreadIds = DB::table('guest_chat_sessions')
            ->whereNotNull('chat_thread_id')
            ->whereJsonContains('metadata->source', 'setter_support')
            ->pluck('chat_thread_id');

        if ($setterThreadIds->isNotEmpty()) {
            DB::table('chat_threads')
                ->whereIn('id', $setterThreadIds)
                ->update(['source' => 'setter_support']);
        }

        // Website lead threads: support type threads with a guest_chat_session but NOT setter_support
        $websiteThreadIds = DB::table('guest_chat_sessions')
            ->whereNotNull('chat_thread_id')
            ->where(function ($q) {
                $q->whereNull('metadata')
                    ->orWhere(function ($q2) {
                        $q2->whereJsonDoesntContain('metadata->source', 'setter_support');
                    });
            })
            ->pluck('chat_thread_id');

        if ($websiteThreadIds->isNotEmpty()) {
            DB::table('chat_threads')
                ->whereIn('id', $websiteThreadIds)
                ->where('type', 'support')
                ->update(['source' => 'website_lead']);
        }
    }

    public function down(): void
    {
        Schema::table('chat_threads', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn('source');
        });
    }
};
```

**Step 2: Run the migration**

```bash
php artisan migrate
```

Expected: Migration runs successfully, existing threads are backfilled.

**Step 3: Commit**

```bash
git add database/migrations/*add_source_to_chat_threads*
git commit -m "feat: add source column to chat_threads with backfill"
```

---

### Task 2: Migration — Add chat visibility permissions

**Files:**
- Create: `database/migrations/2026_03_02_100001_add_chat_visibility_permissions.php`

**Step 1: Create the migration**

```bash
php artisan make:migration add_chat_visibility_permissions --no-interaction
```

Replace contents with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'chat.view_website_leads',
            'chat.view_setter_support',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // Assign to owner, admin, manager roles
        foreach (['owner', 'admin', 'manager'] as $roleName) {
            $role = Role::findByName($roleName, 'web');
            if ($role) {
                $role->givePermissionTo($permissions);
            }
        }
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::where('name', 'chat.view_website_leads')->delete();
        Permission::where('name', 'chat.view_setter_support')->delete();
    }
};
```

**Step 2: Run the migration**

```bash
php artisan migrate
```

Expected: Two new permissions created, assigned to owner/admin/manager.

**Step 3: Update `RolesAndPermissionsSeeder`**

In `database/seeders/RolesAndPermissionsSeeder.php`, add the two new permissions:

1. Add to the permission creation list (around line 77-84, the Communication group):
```php
// Communication
'chat.access',
'chat.view_deal_chats',
'chat.view_website_leads',
'chat.view_setter_support',
'chat.with_customers',
'chat.with_agents',
'chat.with_crew',
'chat.with_internal',
```

2. Add to the owner role permissions array (around line 286):
```php
'chat.access', 'chat.view_deal_chats', 'chat.view_website_leads', 'chat.view_setter_support', 'chat.with_customers', 'chat.with_agents', 'chat.with_crew', 'chat.with_internal',
```

3. Add to admin role (same as owner — around line 374-378):
```php
'chat.access', 'chat.view_deal_chats', 'chat.view_website_leads', 'chat.view_setter_support', 'chat.with_customers', 'chat.with_agents', 'chat.with_crew', 'chat.with_internal',
```

4. Add to manager role (same as admin — around line 451):
```php
'chat.access', 'chat.view_deal_chats', 'chat.view_website_leads', 'chat.view_setter_support', 'chat.with_customers', 'chat.with_agents', 'chat.with_crew', 'chat.with_internal',
```

5. Do NOT add to estimator, hr_manager, door_to_door, or door_knocker roles.

**Step 4: Commit**

```bash
git add database/migrations/*add_chat_visibility_permissions* database/seeders/RolesAndPermissionsSeeder.php
git commit -m "feat: add chat.view_website_leads and chat.view_setter_support permissions"
```

---

### Task 3: Update `ChatThread` model — add source constants and fillable

**Files:**
- Modify: `app/Models/ChatThread.php`

**Step 1: Write the failing test**

Create `tests/Feature/Communications/ChatVisibilityPermissionsTest.php`:

```php
<?php

use App\Models\ChatThread;

it('has source constants on ChatThread', function () {
    expect(ChatThread::SOURCE_WEBSITE_LEAD)->toBe('website_lead');
    expect(ChatThread::SOURCE_SETTER_SUPPORT)->toBe('setter_support');
});

it('allows source in fillable', function () {
    $thread = ChatThread::factory()->support()->create(['source' => 'website_lead']);
    expect($thread->source)->toBe('website_lead');
});
```

**Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter="has source constants"
```

Expected: FAIL — constants not defined.

**Step 3: Implement**

In `app/Models/ChatThread.php`:

1. Add constants after the existing ones (after line 22):
```php
public const SOURCE_WEBSITE_LEAD = 'website_lead';
public const SOURCE_SETTER_SUPPORT = 'setter_support';
```

2. Add `'source'` to the `$fillable` array (after `'type'`):
```php
protected $fillable = [
    'deal_id',
    'type',
    'source',
    'channel',
    // ... rest unchanged
];
```

3. Update `scopeVisibleTo()` (around line 235) to also filter by source permissions:

```php
public function scopeVisibleTo(Builder $query, User $user): Builder
{
    if ($user->isInternal() && $user->roles->isNotEmpty()) {
        $query->where(function (Builder $q) use ($user) {
            // Always allow non-restricted thread types
            $q->where(function (Builder $inner) use ($user) {
                $conditions = [];

                if (! $user->can('chat.view_deal_chats')) {
                    $conditions[] = self::TYPE_DEAL;
                }

                // For support threads, we need source-level filtering
                $blockWebsite = ! $user->can('chat.view_website_leads');
                $blockSetter = ! $user->can('chat.view_setter_support');

                if ($blockWebsite && $blockSetter) {
                    // Block all support threads
                    $conditions[] = self::TYPE_SUPPORT;
                }

                if (! empty($conditions)) {
                    $inner->whereNotIn('type', $conditions);
                }

                // If only one support source is blocked, filter by source
                if ($blockWebsite && ! $blockSetter) {
                    $inner->where(function ($sq) {
                        $sq->where('type', '!=', self::TYPE_SUPPORT)
                            ->orWhere('source', '!=', self::SOURCE_WEBSITE_LEAD)
                            ->orWhereNull('source');
                    });
                } elseif ($blockSetter && ! $blockWebsite) {
                    $inner->where(function ($sq) {
                        $sq->where('type', '!=', self::TYPE_SUPPORT)
                            ->orWhere('source', '!=', self::SOURCE_SETTER_SUPPORT)
                            ->orWhereNull('source');
                    });
                }
            });
        });
    }

    return $query;
}
```

**Step 4: Run tests**

```bash
php artisan test --compact --filter="has source constants|allows source in fillable"
```

Expected: PASS

**Step 5: Update ChatThreadFactory**

In `database/factories/ChatThreadFactory.php`, add factory states:

```php
/**
 * Set source to website_lead (support thread from landing page).
 */
public function websiteLead(): static
{
    return $this->support()->state(fn (array $attributes) => [
        'source' => ChatThread::SOURCE_WEBSITE_LEAD,
    ]);
}

/**
 * Set source to setter_support.
 */
public function setterSupport(): static
{
    return $this->support()->state(fn (array $attributes) => [
        'source' => ChatThread::SOURCE_SETTER_SUPPORT,
    ]);
}
```

**Step 6: Commit**

```bash
git add app/Models/ChatThread.php database/factories/ChatThreadFactory.php tests/Feature/Communications/ChatVisibilityPermissionsTest.php
git commit -m "feat: add source constants and scopeVisibleTo filtering on ChatThread"
```

---

### Task 4: Update `CommunicationsContactService` — permission-based filtering

**Files:**
- Modify: `app/Services/CommunicationsContactService.php`
- Test: `tests/Feature/Communications/ChatVisibilityPermissionsTest.php`

**Step 1: Write failing tests**

Append to `tests/Feature/Communications/ChatVisibilityPermissionsTest.php`:

```php
use App\Models\ChatThread;
use App\Models\GuestChatSession;
use App\Models\User;
use App\Services\CommunicationsContactService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    (new RolesAndPermissionsSeeder)->run();
});

describe('Contact visibility permissions', function () {
    it('hides website lead contacts when user lacks chat.view_website_leads', function () {
        $viewer = User::factory()->internal()->create();
        $viewer->assignRole('estimator'); // estimator has chat.access but NOT view_website_leads

        $leadUser = User::factory()->customer()->create(['name' => 'Website Lead']);
        ChatThread::factory()->websiteLead()->betweenUsers($viewer, $leadUser)->create([
            'last_message_at' => now(),
        ]);

        $service = app(CommunicationsContactService::class);
        $contacts = $service->getContacts($viewer);

        expect($contacts->pluck('name')->toArray())->not->toContain('Website Lead');
    });

    it('shows website lead contacts when user has chat.view_website_leads', function () {
        $viewer = User::factory()->internal()->create();
        $viewer->assignRole('admin'); // admin has view_website_leads

        $leadUser = User::factory()->customer()->create(['name' => 'Website Lead']);
        ChatThread::factory()->websiteLead()->betweenUsers($viewer, $leadUser)->create([
            'last_message_at' => now(),
        ]);

        $service = app(CommunicationsContactService::class);
        $contacts = $service->getContacts($viewer);

        expect($contacts->pluck('name')->toArray())->toContain('Website Lead');
    });

    it('hides setter support contacts when user lacks chat.view_setter_support', function () {
        $viewer = User::factory()->internal()->create();
        $viewer->assignRole('estimator');

        $setterUser = User::factory()->create(['name' => 'Setter Person', 'type' => null]);
        ChatThread::factory()->setterSupport()->betweenUsers($viewer, $setterUser)->create([
            'last_message_at' => now(),
        ]);

        $service = app(CommunicationsContactService::class);
        $contacts = $service->getContacts($viewer);

        expect($contacts->pluck('name')->toArray())->not->toContain('Setter Person');
    });

    it('shows setter support contacts when user has chat.view_setter_support', function () {
        $viewer = User::factory()->internal()->create();
        $viewer->assignRole('admin');

        $setterUser = User::factory()->create(['name' => 'Setter Person', 'type' => null]);
        ChatThread::factory()->setterSupport()->betweenUsers($viewer, $setterUser)->create([
            'last_message_at' => now(),
        ]);

        $service = app(CommunicationsContactService::class);
        $contacts = $service->getContacts($viewer);

        expect($contacts->pluck('name')->toArray())->toContain('Setter Person');
    });

    it('hides internal contacts when user lacks chat.with_internal', function () {
        $viewer = User::factory()->internal()->create();
        $viewer->assignRole('door_to_door'); // has chat.access but NOT chat.with_internal... wait, door_to_door HAS chat.with_internal

        // Use a role without chat.with_internal — we need to test the permission specifically
        // Create a custom scenario: give user chat.access but revoke chat.with_internal
        $viewer = User::factory()->internal()->create();
        $viewer->assignRole('estimator');
        $viewer->revokePermissionTo('chat.with_internal');

        $internalContact = User::factory()->internal()->create(['name' => 'Team Member']);
        ChatThread::factory()->direct()->betweenUsers($viewer, $internalContact)->create([
            'last_message_at' => now(),
        ]);

        $service = app(CommunicationsContactService::class);
        $contacts = $service->getContacts($viewer);

        // Internal contacts should be hidden since all shared threads are direct with internal users
        expect($contacts->pluck('name')->toArray())->not->toContain('Team Member');
    });

    it('shows contact if they have at least one visible thread', function () {
        $viewer = User::factory()->internal()->create();
        $viewer->assignRole('admin');

        $contact = User::factory()->customer()->create(['name' => 'Multi Thread Customer']);

        // One website lead thread (visible) and one deal thread (also visible for admin)
        ChatThread::factory()->websiteLead()->betweenUsers($viewer, $contact)->create(['last_message_at' => now()]);
        ChatThread::factory()->forDeal()->betweenUsers($viewer, $contact)->create(['last_message_at' => now()]);

        $service = app(CommunicationsContactService::class);
        $contacts = $service->getContacts($viewer);

        expect($contacts->pluck('name')->toArray())->toContain('Multi Thread Customer');
    });
});
```

**Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/Communications/ChatVisibilityPermissionsTest.php
```

Expected: Visibility tests fail (contacts not being filtered by permission yet).

**Step 3: Implement permission-based filtering in `CommunicationsContactService`**

Rewrite `getContacts()` in `app/Services/CommunicationsContactService.php`. The key change is after building the contacts collection (around line 141-210), add a permission filtering step before Step 5.

Add a new private method `filterContactsByPermission()`:

```php
/**
 * Filter contacts based on viewer's chat permissions.
 * A contact is visible if they share at least one thread the viewer can see.
 */
private function filterContactsByPermissions(
    Collection $contacts,
    User $viewer,
    Collection $threadsByContact
): Collection {
    // Only apply granular filtering to internal users with roles
    if (! $viewer->isInternal() || $viewer->roles->isEmpty()) {
        return $contacts;
    }

    $canViewDeals = $viewer->can('chat.view_deal_chats');
    $canViewWebsiteLeads = $viewer->can('chat.view_website_leads');
    $canViewSetterSupport = $viewer->can('chat.view_setter_support');
    $canChatInternal = $viewer->can('chat.with_internal');
    $canChatCustomers = $viewer->can('chat.with_customers');
    $canChatAgents = $viewer->can('chat.with_agents');
    $canChatCrew = $viewer->can('chat.with_crew');

    return $contacts->filter(function (array $contact) use (
        $threadsByContact, $canViewDeals, $canViewWebsiteLeads, $canViewSetterSupport,
        $canChatInternal, $canChatCustomers, $canChatAgents, $canChatCrew
    ) {
        $contactId = $contact['id'];
        $contactType = $contact['type'];

        // Check type-level permission first
        $typeAllowed = match ($contactType) {
            'internal' => $canChatInternal,
            'customer' => $canChatCustomers,
            'agent' => $canChatAgents,
            'crew' => $canChatCrew,
            null => true, // null-type users (setters) — check thread-level permissions instead
            default => true,
        };

        $threads = $threadsByContact->get($contactId, collect());

        // If no threads, the contact shouldn't show regardless
        if ($threads->isEmpty()) {
            return false;
        }

        // For typed contacts, check if they have at least one visible thread
        // beyond just the type-level permission
        $hasVisibleThread = $threads->contains(function (ChatThread $thread) use (
            $canViewDeals, $canViewWebsiteLeads, $canViewSetterSupport, $typeAllowed
        ) {
            // Type-level gate
            if (! $typeAllowed) {
                return false;
            }

            return match ($thread->type) {
                ChatThread::TYPE_DEAL => $canViewDeals,
                ChatThread::TYPE_SUPPORT => match ($thread->source) {
                    ChatThread::SOURCE_WEBSITE_LEAD => $canViewWebsiteLeads,
                    ChatThread::SOURCE_SETTER_SUPPORT => $canViewSetterSupport,
                    default => true, // Legacy support threads without source
                },
                ChatThread::TYPE_DIRECT => true, // Direct chats are gated by type-level permission above
                default => true,
            };
        });

        return $hasVisibleThread;
    })->values();
}
```

Then call it in `getContacts()` after building the contacts collection (before Step 5), around line 210:

```php
// Step 4b: Filter contacts by viewer's permissions
$contacts = $this->filterContactsByPermissions($contacts, $viewer, $threadsByContact);
```

Also remove the virtual setter contacts section (Step 5, lines 213-227) — setter contacts now have real User records and appear naturally in the contact list.

**Step 4: Run tests**

```bash
php artisan test --compact tests/Feature/Communications/ChatVisibilityPermissionsTest.php
```

Expected: All PASS

**Step 5: Run existing communications tests to verify no regression**

```bash
php artisan test --compact tests/Feature/Communications/CommunicationsHubTest.php
```

Expected: All existing tests still pass.

**Step 6: Commit**

```bash
git add app/Services/CommunicationsContactService.php tests/Feature/Communications/ChatVisibilityPermissionsTest.php
git commit -m "feat: filter communications hub contacts by chat permissions"
```

---

### Task 5: Update unread count scoping in `ChatService`

**Files:**
- Modify: `app/Services/ChatService.php:456-481`
- Test: `tests/Feature/Communications/ChatVisibilityPermissionsTest.php`

**Step 1: Write failing test**

Append to `tests/Feature/Communications/ChatVisibilityPermissionsTest.php`:

```php
use App\Services\ChatService;

describe('Unread count scoping', function () {
    it('excludes website lead thread messages from unread count when permission missing', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('estimator'); // no chat.view_website_leads

        $leadUser = User::factory()->customer()->create();
        $thread = ChatThread::factory()->websiteLead()->betweenUsers($user, $leadUser)->create([
            'last_message_at' => now(),
        ]);

        // Add unread message from the lead user
        $thread->messages()->create([
            'user_id' => $leadUser->id,
            'content' => 'Hello from website',
            'type' => 'text',
        ]);

        $count = app(ChatService::class)->getUnreadCountForUser($user);
        expect($count)->toBe(0);
    });

    it('includes website lead thread messages when permission granted', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('admin'); // has chat.view_website_leads

        $leadUser = User::factory()->customer()->create();
        $thread = ChatThread::factory()->websiteLead()->betweenUsers($user, $leadUser)->create([
            'last_message_at' => now(),
        ]);

        $thread->messages()->create([
            'user_id' => $leadUser->id,
            'content' => 'Hello from website',
            'type' => 'text',
        ]);

        $count = app(ChatService::class)->getUnreadCountForUser($user);
        expect($count)->toBe(1);
    });

    it('excludes setter support thread messages from unread count when permission missing', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('estimator');

        $setterUser = User::factory()->create(['type' => null]);
        $thread = ChatThread::factory()->setterSupport()->betweenUsers($user, $setterUser)->create([
            'last_message_at' => now(),
        ]);

        $thread->messages()->create([
            'user_id' => $setterUser->id,
            'content' => 'Setter needs help',
            'type' => 'text',
        ]);

        $count = app(ChatService::class)->getUnreadCountForUser($user);
        expect($count)->toBe(0);
    });
});
```

**Step 2: Run tests to verify they fail**

```bash
php artisan test --compact --filter="excludes website lead thread|excludes setter support thread"
```

Expected: FAIL — unread counts include support threads regardless of permission.

**Step 3: Implement**

In `app/Services/ChatService.php`, modify `getUnreadCountForUser()` (line 456-481).

The current code already JOINs `chat_threads` when `chat.view_deal_chats` is missing. Extend this to also handle the new permissions. Since we now may always need the threads JOIN, restructure:

```php
public function getUnreadCountForUser(User $user): int
{
    $query = DB::table('chat_messages')
        ->join('chat_participants', function ($join) use ($user) {
            $join->on('chat_messages.thread_id', '=', 'chat_participants.thread_id')
                ->where('chat_participants.user_id', '=', $user->id)
                ->whereNull('chat_participants.left_at')
                ->whereNull('chat_participants.archived_at');
        })
        ->where('chat_messages.user_id', '!=', $user->id)
        ->whereNull('chat_messages.deleted_at')
        ->whereColumn('chat_messages.created_at', '>', DB::raw('COALESCE(chat_participants.last_read_at, \'1970-01-01\')'));

    // Non-internal users can't see internal messages
    if (! $user->isInternal()) {
        $query->where('chat_messages.is_internal', false);
    }

    // Permission-based thread filtering for internal users with roles
    if ($user->isInternal() && $user->roles->isNotEmpty()) {
        $needsThreadJoin = ! $user->can('chat.view_deal_chats')
            || ! $user->can('chat.view_website_leads')
            || ! $user->can('chat.view_setter_support');

        if ($needsThreadJoin) {
            $query->join('chat_threads', 'chat_messages.thread_id', '=', 'chat_threads.id');

            if (! $user->can('chat.view_deal_chats')) {
                $query->where('chat_threads.type', '!=', ChatThread::TYPE_DEAL);
            }

            if (! $user->can('chat.view_website_leads')) {
                $query->where(function ($q) {
                    $q->where('chat_threads.type', '!=', ChatThread::TYPE_SUPPORT)
                        ->orWhere('chat_threads.source', '!=', ChatThread::SOURCE_WEBSITE_LEAD)
                        ->orWhereNull('chat_threads.source');
                });
            }

            if (! $user->can('chat.view_setter_support')) {
                $query->where(function ($q) {
                    $q->where('chat_threads.type', '!=', ChatThread::TYPE_SUPPORT)
                        ->orWhere('chat_threads.source', '!=', ChatThread::SOURCE_SETTER_SUPPORT)
                        ->orWhereNull('chat_threads.source');
                });
            }
        }
    }

    return (int) $query->count();
}
```

**Step 4: Run tests**

```bash
php artisan test --compact tests/Feature/Communications/ChatVisibilityPermissionsTest.php
```

Expected: All PASS

**Step 5: Commit**

```bash
git add app/Services/ChatService.php tests/Feature/Communications/ChatVisibilityPermissionsTest.php
git commit -m "feat: scope unread counts by chat visibility permissions"
```

---

### Task 6: Update setter support chat — create User, set source

**Files:**
- Modify: `resources/views/components/public/⚡setter-support-chat.blade.php`
- Test: `tests/Feature/Communications/ChatVisibilityPermissionsTest.php`

**Step 1: Write failing test**

Append to the test file:

```php
use App\Models\GuestChatSession;
use Livewire\Livewire;

describe('Setter support chat creates user', function () {
    it('creates a user with null type when setter starts chat', function () {
        // Create internal users with proper permissions so they get added as participants
        $internal = User::factory()->internal()->create();
        $internal->assignRole('admin');

        Livewire::test('public.setter-support-chat')
            ->set('email', 'setter@example.com')
            ->set('name', 'Jane Setter')
            ->call('startChat');

        $user = User::where('email', 'setter@example.com')->first();
        expect($user)->not->toBeNull();
        expect($user->type)->toBeNull();
        expect($user->name)->toBe('Jane Setter');

        // Verify the user is a participant in the thread
        $session = GuestChatSession::where('email', 'setter@example.com')->first();
        expect($session->user_id)->toBe($user->id);

        $thread = $session->thread;
        expect($thread->source)->toBe('setter_support');
        expect($thread->hasParticipant($user))->toBeTrue();
    });

    it('reuses existing user when setter starts another chat', function () {
        $existing = User::factory()->create([
            'email' => 'returning@setter.com',
            'name' => 'Returning Setter',
            'type' => null,
        ]);

        $internal = User::factory()->internal()->create();
        $internal->assignRole('admin');

        Livewire::test('public.setter-support-chat')
            ->set('email', 'returning@setter.com')
            ->set('name', 'Returning Setter')
            ->call('startChat');

        // Should not create a duplicate user
        expect(User::where('email', 'returning@setter.com')->count())->toBe(1);

        $session = GuestChatSession::where('email', 'returning@setter.com')->first();
        expect($session->user_id)->toBe($existing->id);
    });

    it('only adds permitted internal users as participants', function () {
        $admin = User::factory()->internal()->create();
        $admin->assignRole('admin'); // has chat.view_setter_support

        $estimator = User::factory()->internal()->create();
        $estimator->assignRole('estimator'); // does NOT have chat.view_setter_support

        Livewire::test('public.setter-support-chat')
            ->set('email', 'setter2@example.com')
            ->set('name', 'Test Setter')
            ->call('startChat');

        $session = GuestChatSession::where('email', 'setter2@example.com')->first();
        $thread = $session->thread;

        expect($thread->hasParticipant($admin))->toBeTrue();
        expect($thread->hasParticipant($estimator))->toBeFalse();
    });
});
```

**Step 2: Run tests to verify they fail**

```bash
php artisan test --compact --filter="creates a user with null type|reuses existing user|only adds permitted internal"
```

Expected: FAIL — setter chat currently doesn't create a User.

**Step 3: Implement**

Replace the `startChat()` method in `resources/views/components/public/⚡setter-support-chat.blade.php`:

```php
public function startChat(): void
{
    $this->validate([
        'email' => ['required', 'email', 'max:255'],
        'name' => ['nullable', 'string', 'max:255'],
    ]);

    // Find or create a User for this setter (type = null, not a customer)
    $setterUser = User::where('email', $this->email)->first();

    if (! $setterUser) {
        $setterUser = new User([
            'name' => $this->name ?: explode('@', $this->email)[0],
            'email' => $this->email,
            'password' => bcrypt(\Illuminate\Support\Str::random(32)),
        ]);
        $setterUser->type = null;
        $setterUser->save();
    }

    // Create a support thread with setter_support source
    $thread = ChatThread::create([
        'type' => ChatThread::TYPE_SUPPORT,
        'source' => ChatThread::SOURCE_SETTER_SUPPORT,
        'subject' => 'Setter Support: ' . ($this->name ?: $this->email),
        'last_message_at' => now(),
    ]);

    // Add setter as participant
    $thread->addParticipant($setterUser);

    // Only add internal users who have permission to view setter support chats
    $internalUsers = User::where('type', 'internal')->get()->filter(function (User $user) {
        // Users without roles get all chats (backwards compat)
        if ($user->roles->isEmpty()) {
            return true;
        }
        return $user->can('chat.view_setter_support');
    });

    foreach ($internalUsers as $user) {
        $thread->addParticipant($user);
    }

    // Send a welcome greeting
    $greeting = MessengerSetting::getInstance()->widget_greeting_logged_out;
    if ($greeting) {
        $greetingUser = $internalUsers->first();
        if ($greetingUser) {
            app(ChatService::class)->sendMessage($thread, $greetingUser, $greeting);
        }
    }

    $uuid = Str::uuid()->toString();
    $session = GuestChatSession::create([
        'uuid' => $uuid,
        'email' => $this->email,
        'name' => $this->name ?: null,
        'ip_address' => request()->ip(),
        'city' => null,
        'user_agent' => request()->userAgent(),
        'chat_thread_id' => $thread->id,
        'user_id' => $setterUser->id,
        'status' => 'active',
        'started_at' => now(),
        'metadata' => [
            'page_url' => request()->header('Referer'),
            'referrer' => request()->header('Referer'),
            'source' => 'setter_support',
        ],
    ]);

    $this->sessionId = $session->id;

    cookie()->queue('setter_chat_uuid', $uuid, 60 * 24 * 30);

    try {
        broadcast(new NewThreadNotification($thread));
    } catch (\Throwable) {
    }

    // Only notify internal users with permission
    foreach ($internalUsers as $user) {
        $user->notify(new NewSupportChatNotification($session));
    }
}
```

**Step 4: Run tests**

```bash
php artisan test --compact --filter="creates a user with null type|reuses existing user|only adds permitted internal"
```

Expected: All PASS

**Step 5: Commit**

```bash
git add "resources/views/components/public/⚡setter-support-chat.blade.php" tests/Feature/Communications/ChatVisibilityPermissionsTest.php
git commit -m "feat: setter support chat creates User and sets thread source"
```

---

### Task 7: Update guest support chat — set source on thread

**Files:**
- Modify: `resources/views/components/public/⚡guest-chat.blade.php`
- Test: `tests/Feature/Communications/ChatVisibilityPermissionsTest.php`

**Step 1: Write failing test**

Append:

```php
describe('Guest chat sets website_lead source', function () {
    it('creates thread with website_lead source', function () {
        $internal = User::factory()->internal()->create();
        $internal->assignRole('admin');

        Livewire::test('public.guest-chat')
            ->set('email', 'visitor@example.com')
            ->set('name', 'Web Visitor')
            ->call('startChat');

        $session = GuestChatSession::where('email', 'visitor@example.com')->first();
        $thread = $session->thread;

        expect($thread->source)->toBe('website_lead');
        expect($thread->type)->toBe('support');
    });

    it('only adds permitted internal users as participants for website leads', function () {
        $admin = User::factory()->internal()->create();
        $admin->assignRole('admin'); // has chat.view_website_leads

        $hrManager = User::factory()->internal()->create();
        $hrManager->assignRole('hr_manager'); // does NOT have chat.view_website_leads

        Livewire::test('public.guest-chat')
            ->set('email', 'lead@example.com')
            ->set('name', 'New Lead')
            ->call('startChat');

        $session = GuestChatSession::where('email', 'lead@example.com')->first();
        $thread = $session->thread;

        expect($thread->hasParticipant($admin))->toBeTrue();
        expect($thread->hasParticipant($hrManager))->toBeFalse();
    });
});
```

**Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter="creates thread with website_lead source"
```

Expected: FAIL — source not set.

**Step 3: Implement**

In `resources/views/components/public/⚡guest-chat.blade.php`, around line 90 where the thread is created:

Change:
```php
$thread = ChatThread::create([
    'type' => ChatThread::TYPE_SUPPORT,
    'subject' => 'Support: '.($this->name ?: $this->email),
    'customer_id' => $customer->id,
    'last_message_at' => now(),
]);
```

To:
```php
$thread = ChatThread::create([
    'type' => ChatThread::TYPE_SUPPORT,
    'source' => ChatThread::SOURCE_WEBSITE_LEAD,
    'subject' => 'Support: '.($this->name ?: $this->email),
    'customer_id' => $customer->id,
    'last_message_at' => now(),
]);
```

Also update the internal user participant addition (around line 100-103):

Change:
```php
$internalUsers = User::where('type', 'internal')->get();
foreach ($internalUsers as $user) {
    $thread->addParticipant($user);
}
```

To:
```php
$internalUsers = User::where('type', 'internal')->get()->filter(function (User $user) {
    if ($user->roles->isEmpty()) {
        return true;
    }
    return $user->can('chat.view_website_leads');
});
foreach ($internalUsers as $user) {
    $thread->addParticipant($user);
}
```

And update the notification dispatch (around line 139-144) to only notify permitted users:

Change:
```php
$internalUsers = User::where('type', 'internal')->get();
foreach ($internalUsers as $user) {
    $user->notify(new NewSupportChatNotification($session));
}
```

To:
```php
foreach ($internalUsers as $user) {
    $user->notify(new NewSupportChatNotification($session));
}
```

(The `$internalUsers` collection is already filtered from the participant addition step above — just make sure it's the same variable.)

**Step 4: Run tests**

```bash
php artisan test --compact --filter="creates thread with website_lead source|only adds permitted internal users as participants for website leads"
```

Expected: PASS

**Step 5: Commit**

```bash
git add "resources/views/components/public/⚡guest-chat.blade.php" tests/Feature/Communications/ChatVisibilityPermissionsTest.php
git commit -m "feat: guest chat sets website_lead source, gates participants by permission"
```

---

### Task 8: Remove virtual setter contacts from `CommunicationsContactService`

**Files:**
- Modify: `app/Services/CommunicationsContactService.php`
- Test: `tests/Feature/Communications/CommunicationsHubTest.php`

**Step 1: Remove the `getSetterSupportContacts()` method and its call**

In `getContacts()`, remove lines 213-227 (Step 5 block that appends setter virtual contacts):

```php
// Step 5: Add setter support sessions as virtual contacts
// These are appointment setters who chat without user accounts
// Only include when type filter is 'all' (setter contacts are not regular user types)
if (! $typeFilter || $typeFilter === 'all') {
    $setterContacts = $this->getSetterSupportContacts($viewer);
    // ...
    $contacts = $contacts->merge($setterContacts);
}
```

Delete entirely. Also delete the `getSetterSupportContacts()` private method (lines 239-281).

Also update the type filter check (line 54) — remove the `setter_support` special case:

Change:
```php
if ($typeFilter && $typeFilter !== 'all' && $typeFilter !== 'setter_support') {
    $usersQuery->where('type', $typeFilter);
}
```

To:
```php
if ($typeFilter && $typeFilter !== 'all') {
    $usersQuery->where('type', $typeFilter);
}
```

**Step 2: Run existing tests to verify no regression**

```bash
php artisan test --compact tests/Feature/Communications/CommunicationsHubTest.php tests/Feature/Communications/ChatVisibilityPermissionsTest.php
```

Expected: All PASS (setter contacts now appear as real User-based contacts, not virtual contacts).

**Step 3: Commit**

```bash
git add app/Services/CommunicationsContactService.php
git commit -m "refactor: remove virtual setter contacts, setters are now real User participants"
```

---

### Task 9: Gate notification dispatch by permission

**Files:**
- Modify: `app/Services/ChatService.php:600-614` (dispatchGuestUnreadEmails)
- Test: `tests/Feature/Communications/ChatVisibilityPermissionsTest.php`

**Step 1: Write failing test**

Append:

```php
use App\Jobs\SendUnreadChatEmailJob;
use Illuminate\Support\Facades\Queue;

describe('Notification permission gating', function () {
    it('does not dispatch unread email to users without support thread permission', function () {
        Queue::fake();

        $admin = User::factory()->internal()->create();
        $admin->assignRole('admin'); // has chat.view_setter_support

        $estimator = User::factory()->internal()->create();
        $estimator->assignRole('estimator'); // does NOT have chat.view_setter_support

        $setterUser = User::factory()->create(['type' => null]);
        $thread = ChatThread::factory()->setterSupport()->betweenUsers($admin, $setterUser)->create([
            'last_message_at' => now(),
        ]);
        // Also add estimator as participant (simulating legacy behavior)
        $thread->addParticipant($estimator);

        // Send a guest message
        app(ChatService::class)->sendGuestMessage($thread, 'Need help!', [
            'guest_email' => 'setter@test.com',
            'source' => 'setter_support',
        ]);

        // Should dispatch for admin but NOT for estimator
        Queue::assertPushed(SendUnreadChatEmailJob::class, function ($job) use ($admin) {
            return $job->user->id === $admin->id;
        });

        Queue::assertNotPushed(SendUnreadChatEmailJob::class, function ($job) use ($estimator) {
            return $job->user->id === $estimator->id;
        });
    });
});
```

**Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter="does not dispatch unread email to users"
```

Expected: FAIL — both users get notifications.

**Step 3: Implement**

In `app/Services/ChatService.php`, modify `dispatchGuestUnreadEmails()` (line 600-614):

```php
private function dispatchGuestUnreadEmails(ChatThread $thread, ChatMessage $message): void
{
    $participants = $thread->activeParticipants()->with('user')->get();

    foreach ($participants as $participant) {
        $user = $participant->user;

        // Skip if user lacks permission to view this support thread type
        if (! $this->canViewSupportThread($user, $thread)) {
            continue;
        }

        SendUnreadChatEmailJob::dispatch($message, $user)
            ->delay(now()->addMinutes(5));

        if ($user->wantsSms('chat')) {
            SendUnreadChatSmsJob::dispatch($message, $user)
                ->delay(now()->addMinutes(5));
        }
    }
}
```

Add a helper method:

```php
/**
 * Check if a user has permission to view a support thread based on its source.
 */
private function canViewSupportThread(User $user, ChatThread $thread): bool
{
    // Non-internal users or users without roles: no restriction
    if (! $user->isInternal() || $user->roles->isEmpty()) {
        return true;
    }

    // Only apply to support threads
    if ($thread->type !== ChatThread::TYPE_SUPPORT) {
        return true;
    }

    return match ($thread->source) {
        ChatThread::SOURCE_WEBSITE_LEAD => $user->can('chat.view_website_leads'),
        ChatThread::SOURCE_SETTER_SUPPORT => $user->can('chat.view_setter_support'),
        default => true, // Legacy support threads without source
    };
}
```

**Step 4: Run tests**

```bash
php artisan test --compact tests/Feature/Communications/ChatVisibilityPermissionsTest.php
```

Expected: All PASS

**Step 5: Commit**

```bash
git add app/Services/ChatService.php tests/Feature/Communications/ChatVisibilityPermissionsTest.php
git commit -m "feat: gate unread notification dispatch by support thread permissions"
```

---

### Task 10: Run full test suite and fix regressions

**Step 1: Run the full communications test suite**

```bash
php artisan test --compact tests/Feature/Communications/
```

Expected: All PASS

**Step 2: Run related test files**

```bash
php artisan test --compact tests/Feature/Sms/ tests/Feature/Channels/
```

Expected: All PASS

**Step 3: Run pint**

```bash
vendor/bin/pint --dirty
```

**Step 4: Final commit**

```bash
git add -A
git commit -m "chore: lint fixes from pint"
```

**Step 5: Ask user if they want to run the full test suite**

---

### Task 11: Update the communications hub badge component (optional)

**Files:**
- Modify: `resources/views/components/communications/⚡unread-badge.blade.php`

The unread badge calls `CommunicationsContactService::getUnreadTotalForUser()` which calls `ChatService::getUnreadCountForUser()` — this was already updated in Task 5 to respect permissions. Verify it works:

**Step 1: Run existing badge tests**

```bash
php artisan test --compact --filter="Unread Badge"
```

Expected: All PASS (badge already delegates to the permission-scoped service).

**Step 2: If needed, fix any issues. Otherwise, this task is done.**
