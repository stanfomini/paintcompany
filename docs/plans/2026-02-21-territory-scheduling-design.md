# Territory-Aware Scheduling System Design

**Date**: 2026-02-21
**Branch**: `feature/territory-hierarchy-session4`
**Status**: Approved

## Problem

1. **Map layer visibility**: Users can't independently toggle hierarchy levels (region/zone/block) on/off. The filter is exclusive (one level at a time or all).
2. **Map focus on load**: Map GPS-centers on physical location, which is useless when a manager's assigned territories are in a different state.
3. **Block scheduling is single-day only**: The dispatch system sends one-off offers per day. Zone managers need to plan weeks/months of territory assignments for their teams.
4. **No meet-up coordination**: No way to set a "be at this address at this time" pin that shows on employee calendars with navigation.
5. **No calendar integration**: Territory assignments don't appear in employee planners or calendars.

## Design

### Part 1: Map Layer Toggles

Replace exclusive filter buttons (All/Regions/Zones/Blocks) with independent checkboxes. Default all ON. Each toggle dispatches a Livewire event to filter `territoryPolygons` by the active set of levels.

**Data model change**: `filterLevel` string → `visibleLevels` array `['region', 'zone', 'block']`.

### Part 2: Smart Map Focus on Load

On `mount()`, determine the user's assigned territories via `PermissionScopeService::getVisibleTerritoryIds()`. If territories with boundaries exist, pass their combined bounds to the Alpine map component as `initialBounds` instead of a center point. The map fits to those bounds on init. Fall back to GPS/US-center only if no territories have boundaries.

### Part 3: Extend Schedule Model

Add territory-specific fields to the existing `Schedule` model (migration):

```
schedule_type   enum('job', 'territory') default 'job'
territory_id    nullable FK → sales_territories
meet_address    nullable string(500)
meet_lat        nullable decimal(10,7)
meet_lng        nullable decimal(10,7)
```

Existing job scheduling untouched (`schedule_type = 'job'`). Territory assignments use `schedule_type = 'territory'`.

**Relationships added to Schedule**:
- `territory()` → BelongsTo SalesTerritory

**Relationships added to SalesTerritory**:
- `schedules()` → HasMany Schedule

### Part 4: Zone Manager Scheduling UI

New **"Schedule"** tab in Territory Manager. Visible to users with `territories.manage_zones` or `territories.manage_blocks` permission.

**Layout**: Weekly calendar grid.
- **Columns**: Days of the week (Mon–Sun), navigable by week
- **Rows**: Team members assigned to the zone manager's territories
- **Block sidebar**: Draggable/selectable list of blocks the zone manager controls
- **Interaction**: Click a cell → modal to assign a block with:
  - Block selection (pre-filled if dragged)
  - Start time (default 8:00 AM)
  - Meet-up address (text input)
  - Meet-up map pin (optional click-to-place on mini map)
  - Notes
  - Multi-employee toggle: assign same block+time to multiple team members
- **Bulk actions**: "Copy week" to duplicate a week's schedule forward
- **Cell display**: Block name badge + start time, colored by block color

### Part 5: Calendar Integration

Extend `UnifiedCalendarService` to include territory schedules:

```php
// New source added alongside activities, jobs, life commitments
Schedule::where('schedule_type', 'territory')
    ->where('employee_profile_id', $user->employeeProfile->id)
    ->whereBetween('date', [$start, $end])
    ->with('territory')
```

Returns normalized events with:
- **title**: Block name (e.g. "Maple Heights Block 3")
- **subtitle**: Meet-up address
- **start/end**: From schedule times
- **color**: Territory color
- **meta**: `{ meet_lat, meet_lng, meet_address, territory_id }`
- **url**: Navigation link (`geo:lat,lng` or Google Maps URL)

Employee's planner (`my-day.blade.php`) renders territory assignments with a clickable address that opens device navigation.

### Part 6: Notifications

When a zone manager creates/updates territory schedules, each assigned employee receives a notification:
- Type: `TerritoryScheduleAssigned`
- Contains: block name, date, start time, meet-up address
- Channels: database + broadcast (real-time via Reverb)

## Data Flow

```
Zone Manager creates schedule
  → Schedule records created (schedule_type='territory')
  → TerritoryScheduleAssigned notification to each employee
  → Employee opens Planner → UnifiedCalendarService includes territory schedules
  → Employee sees block assignment with meet-up address
  → Taps address → native navigation opens
```

## Files to Create/Modify

| Action | File |
|--------|------|
| Migration | `add_territory_fields_to_schedules_table` |
| Modify | `app/Models/Schedule.php` - add territory relationship, schedule_type cast |
| Modify | `app/Models/SalesTerritory.php` - add schedules relationship |
| Create | `app/Notifications/TerritoryScheduleAssigned.php` |
| Modify | `app/Services/UnifiedCalendarService.php` - add territory schedule source |
| Modify | `resources/views/components/sales/⚡territory-manager.blade.php` - layer toggles, smart focus, schedule tab |
| Create | `resources/views/components/sales/⚡territory-schedule.blade.php` - scheduling calendar component |
| Modify | `resources/js/app.js` - layer toggle events, mini map for meet-up pin |
| Modify | `resources/views/components/planner/⚡my-day.blade.php` - render territory events with nav links |
| Tests | Territory scheduling tests, calendar integration tests, notification tests |
