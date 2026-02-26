# Footer Action Bar Design

**Date**: 2026-02-26
**Status**: Approved

## Overview

Replace the floating FAB buttons (notification bell, camera recorder, chat dock) with a fixed footer bar at the bottom of every internal user page. Three action buttons: Notifications, Record, Messages. Provides quick access to the Communications Hub and recording, while also acting as a content barrier so nothing bleeds off the bottom of the screen on mobile.

## Layout

```
┌─────────────────────────────────────────────────────────┐
│                    Page Content                         │
│                  (pb-14 to clear bar)                   │
│                                                         │
├──────────────┬──────────────────┬───────────────────────┤
│  🔔 Notifs (3)│   📹 Record     │   💬 Messages (12)   │
└──────────────┴──────────────────┴───────────────────────┘
```

- Fixed `bottom-0`, full width, ~48px tall
- 3 equal-width buttons in a flex row
- Dark zinc background matching sidebar aesthetic
- `lg:pl-64` offset on desktop to clear the sidebar
- `safe-area-inset-bottom` padding for notch devices (iOS)

## Button Behaviors

| Button | Icon | Badge | Click Action |
|--------|------|-------|-------------|
| Notifications | Bell | Red unread count (chat + email) | `wire:navigate` to `/communications` |
| Record | Camera | None | Triggers quick-recorder fullscreen overlay |
| Messages | Chat bubble | Red unread count (chat + email) | `wire:navigate` to `/communications` |

## What Gets Removed

- Floating bottom-left button bar (notification bell + camera FABs)
- Floating chat dock (multi-window FAB + thread picker + chat windows)
- `@persist('chat-dock')` block in layout

## Component Structure

- **New**: `resources/views/components/layout/⚡footer-bar.blade.php` — Livewire SFC with real-time unread counting
- **Kept**: `resources/views/components/media/⚡quick-recorder.blade.php` — rendered inside footer bar, still does fullscreen overlay
- **Removed from layout**: Direct references to `notifications.notification-center` and `chat.chat-dock`

## Permissions

- Entire bar: internal users only (`auth()->user()->isInternal()`)
- Record button: gated by `@can('media.record')`
- Badge counts: `ChatService::getUnreadCountForUser()` + `EmailMessage` inbox unread

## Content Padding

Add `pb-14` (56px) to the main content wrapper so page content isn't hidden behind the fixed bar.

## Mobile

Same bar, no sidebar offset. Acts as familiar bottom navigation on small screens. The footer also prevents content from bleeding to the very bottom edge of the screen.
