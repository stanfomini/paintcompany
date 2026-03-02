# Chat Visibility Permissions Design

**Date**: 2026-03-02
**Status**: Approved

## Problem

1. **Setter support chats** create threads without a User, so they exist as "virtual contacts" in the communications hub — a fragile approach that bypasses the standard notification pipeline (push, email, SMS).
2. **The communications hub shows everything** to all internal users regardless of permissions. Direct chats between other employees are visible, and there's no way to scope who sees website lead chats vs setter support chats.
3. The `chat.with_*` permissions are enforced only at thread creation time, not when filtering the hub contact list.

## Solution

### 1. Setter User Creation

When a setter enters their email in the setter support chat widget, find-or-create a `User` record:

- `type = null` (not a customer, agent, internal, or crew)
- No `Customer` or `Deal` record created
- User is added as a thread participant
- `user_id` set on the `GuestChatSession`
- If the email matches an existing User, reuse it and resume prior chat history

This makes setters real participants so the entire notification pipeline (push, email, SMS, broadcast) works automatically.

### 2. Thread Source Column

Add a `source` column to `chat_threads` to distinguish support thread origins:

| Value | Meaning |
|---|---|
| `website_lead` | From landing page guest chat widget |
| `setter_support` | From appointment setter support chat |
| `null` | Deal threads, direct messages, other |

This avoids JOINing `guest_chat_sessions` in every query. Set at thread creation time. Backfill existing threads from `GuestChatSession.metadata.source`.

### 3. New Permissions

Add to the Communication permission group:

| Permission | Purpose |
|---|---|
| `chat.view_website_leads` | See support threads from landing page |
| `chat.view_setter_support` | See support threads from setter chat |

Default role assignments:

| Role | `view_website_leads` | `view_setter_support` |
|---|---|---|
| owner | yes | yes |
| admin | yes | yes |
| manager | yes | yes |
| estimator | no | no |
| hr_manager | no | no |
| door_to_door | no | no |
| door_knocker | no | no |

Configurable per-role in the existing permissions settings UI.

### 4. Hub Contact Filtering

`CommunicationsContactService::getContacts()` applies permission-based filtering after loading contacts:

- Lacks `chat.view_deal_chats` → hide contacts whose only shared threads are deal threads
- Lacks `chat.view_website_leads` → hide contacts whose only shared threads are website lead support threads
- Lacks `chat.view_setter_support` → hide setter support contacts
- Lacks `chat.with_internal` → hide internal user direct chats
- Lacks `chat.with_customers` → hide customer direct chats
- Same for `chat.with_agents`, `chat.with_crew`

A contact is visible if they have **at least one thread** the viewer has permission to see.

The `getSetterSupportContacts()` virtual contact method is removed — setters now show up as real User-based contacts.

### 5. Notification Gating

**Thread creation**: Only add internal users who have the relevant permission as participants (not all internal users).

**`NewSupportChatNotification`**: Only notify internal users with `chat.view_website_leads` (for guest chat) or `chat.view_setter_support` (for setter chat).

**`SendUnreadChatEmailJob` / `SendUnreadChatSmsJob`**: For support threads, verify the participant still has the relevant permission before sending.

### 6. Unread Count Scoping

`ChatService::getUnreadCountForUser()` extends existing pattern (already excludes deal threads for users without `chat.view_deal_chats`):

- Lacks `chat.view_website_leads` → exclude threads with `source = 'website_lead'`
- Lacks `chat.view_setter_support` → exclude threads with `source = 'setter_support'`

## Files Changed

| File | Change |
|---|---|
| `database/migrations/..._add_source_to_chat_threads.php` | Add `source` column, backfill existing data |
| `database/migrations/..._add_chat_visibility_permissions.php` | Create permissions, assign to roles |
| `app/Models/ChatThread.php` | Add `source` constants |
| `resources/views/components/public/⚡setter-support-chat.blade.php` | Create User, add as participant, set thread source |
| `resources/views/components/public/⚡guest-chat.blade.php` | Set `source = 'website_lead'` on thread |
| `app/Services/CommunicationsContactService.php` | Permission-based filtering, remove virtual contacts |
| `app/Services/ChatService.php` | Permission-gated unread counts, participant addition |
| `app/Notifications/NewSupportChatNotification.php` | Filter recipients by permission |
| `database/seeders/RolesAndPermissionsSeeder.php` | Add new permissions |
| Tests | Permission filtering, setter user creation, notification gating |
