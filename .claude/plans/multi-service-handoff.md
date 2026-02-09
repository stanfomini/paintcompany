# Multi-Service Crews - Handoff Prompt for Next Session

Copy everything below the line into a new Claude Code session.

---

## Context: What Was Built (Backend Complete, UI Incomplete)

A previous session built the **Multi-Service Crews** backend infrastructure. The database, models, services, and broadcast events are all in place. But the **admin UI to actually USE service categories** is largely missing. You can't invite a crew for a specific service, you can't see/edit a crew's service category on their profile page, and the estimate system doesn't price differently by service.

### What EXISTS (working backend):

**Database tables created:**
- `service_categories` - Dynamic service types (id, name, slug, description, icon, color, is_active, sort_order). Seeded with "Painting" and "Cleaning".
- `job_service_requirements` - Per-service requirements per job (job_id, service_category_id, required_crew_size, scheduled_date, status)
- `job_prep_approvals` - Per-service per-party approval (plan_id, service_category_id, party, approved, approved_by, approved_at)
- `job_prep_service_settings` - Per-service crew config (plan_id, service_category_id, crew_size, hours_per_day, total_days)

**Columns added to existing tables:**
- `crew_profiles.service_category_id` (FK, nullable) - what service this crew does
- `invitations.service_category_id` (FK, nullable) - assigned when inviting crew
- `job_crew` pivot: `service_category_id` (FK, nullable) - which service the crew is dispatched for
- `estimate_options.service_category_id` (FK, nullable) - what service this option represents
- `estimate_area_surfaces.description` (text, nullable) - surface descriptions for prep plan
- `job_prep_tasks.service_category_id` (FK, nullable) - tasks tagged by service

**Models:** `ServiceCategory`, `JobServiceRequirement`, `JobPrepApproval`, `JobPrepServiceSetting` - all with factories.

**Services refactored:**
- `JobDispatchService` - `getEligibleCrews()` filters by service_category_id on crew profiles. `dispatchToCrews()` tags pivot with service_category_id. `acceptJob()` scopes race condition to same service only. `replaceCrew()` revokes one crew and re-opens slot.
- `JobPrepService` - `createPlan()` accepts per-service configs. `approveSection()` works per-service per-party. `proposeChange()` resets other parties' approvals. `revokeServiceApprovals()` scoped to one service.
- `JobService` - `createFromEstimate()` auto-creates painting `JobServiceRequirement` + additional requirements from selected estimate options with service_category_id.
- `InvitationService` - `sendInvitation()` accepts optional `?int $serviceCategoryId`. `acceptInvitation()` creates CrewProfile with service_category_id from invitation.

**8 broadcast events:** JobStageChanged, JobDateUpdated, JobColorUpdated, CrewDispatched, PrepPlanUpdated, PrepApprovalChanged, PhotoUpdated, JobNotification. All under 1KB payloads. Channels: `job.{id}` (internal), `crew.job.{id}`, `customer.job.{id}`.

**UI that IS working:**
- Settings > Service Categories CRUD page (`resources/views/pages/settings/⚡service-categories.blade.php`)
- Stage-scheduling dispatch: shows per-service tabs, filters eligible crews by service category
- Stage-prep: per-service crew settings, approval matrix (service x party grid)
- Job show: crews grouped by service category, Echo listeners
- Crew portal: available jobs filtered by crew's service category, job detail shows service-specific tasks
- Customer portal: gated visibility (team+crew must approve before customer sees a service section)
- Photo components: real-time Echo listeners

**Tests:** 394 job-related tests passing + 102 new tests across 5 files.

---

## What's MISSING (UI Gaps That Make It Unusable)

### Priority 1: Crew Invitation + Profile (BLOCKING - can't assign service categories to crews)

**Problem:** When you invite a crew member, there's no way to select which service category they belong to. And after they're created, you can't edit it from their profile page.

**Files to modify:**

1. **`resources/views/components/invitations/⚡send-invitation.blade.php`**
   - Add a service category dropdown that appears ONLY when `invitedUserType === 'crew'`
   - Pass the selected `serviceCategoryId` to `InvitationService::sendInvitation()` (the service already accepts it as the 4th parameter)
   - Load categories from `ServiceCategory::active()->ordered()->get()`

2. **`resources/views/components/crews/⚡show.blade.php`** (crew detail page like `/crews/40`)
   - Display the crew's current service category in the overview section (with icon + color badge)
   - Add service category to the "Edit Profile" modal as a dropdown
   - Save it to `$crew->crewProfile->service_category_id`

3. **`resources/views/components/crews/⚡index.blade.php`** (crews listing)
   - Add a "Service Category" filter dropdown alongside existing status/compliance filters
   - Add a service category column to the table (show colored badge)
   - Filter query: `->whereHas('crewProfile', fn($q) => $q->where('service_category_id', $id))`

### Priority 2: Estimate Options Need Service Category UI

**Problem:** `EstimateOption` has `service_category_id` in its fillable, and `JobService::createFromEstimate()` uses it to auto-create service requirements. But the estimate builder UI doesn't let you SET it on options.

**Files to modify:**

4. **`resources/views/components/estimates/⚡template-builder.blade.php`** (main estimate builder)
   - When creating/editing estimate options (add-ons), add a "Service Category" dropdown
   - This is how cleaning, pressure washing, etc. get tagged so the job knows to create those service requirements
   - Consider: pre-populate service category when creating common option types

5. **`resources/views/components/estimates/⚡package-builder.blade.php`** (package builder)
   - Same as template-builder - options need service category selection

6. **Public proposal views** (`resources/views/components/public/⚡estimate-proposal.blade.php`, `⚡proposal-packages.blade.php`)
   - Group selected options by service category in the proposal display
   - Show service category badges next to option names

### Priority 3: Deeper Estimate/Pricing Integration (Future Phase)

**Current state:** The pricing engine (`EstimateCostBreakdownService`) only knows about `scope_type` (interior/exterior). It does NOT know about service categories. Production rates are organized by scope + surface_category, not by service.

**This means:**
- All areas get the same wage rate regardless of service
- A "cleaning" area would be priced using painting production rates
- There's no way to have different margins for different services

**Decisions needed before implementing:**

a. **Should estimate AREAS have service categories?**
   - Currently areas only have `scope_type` (interior/exterior)
   - Adding `service_category_id` to `estimate_areas` would let you tag "Kitchen - Cleaning" vs "Kitchen - Painting"
   - OR: Keep areas as painting-only and use estimate OPTIONS for other services (simpler)
   - Recommendation: Keep areas painting-only for now. Other services use options with fixed pricing.

b. **Should production rates map to service categories?**
   - Currently organized by scope (interior/exterior) + surface_category (walls, ceiling, trim)
   - Could add `service_category_id` to `production_rates` table
   - OR: Different services might not use production rates at all (cleaning = flat rate per sqft, not surface-specific)
   - Recommendation: For now, production rates stay painting-specific. Other services use simpler pricing (flat per sqft or fixed price on options).

c. **Should CompanySetting have per-service wages/margins?**
   - Currently has `interior_hourly_wage`, `exterior_hourly_wage`, `interior_margin_percent`, etc.
   - Could extend to `cleaning_hourly_wage`, `cleaning_margin_percent`
   - OR: Each service category could have its own pricing config table
   - Recommendation: Don't over-engineer. Start with estimate options having fixed/per-sqft pricing for non-painting services. Only add service-specific wages if demand warrants it.

### Priority 4: Dispatch Flow Refinements

**Current state:** The dispatch UI works for multi-service. But some UX improvements are needed:

7. **Stage-scheduling initial state:** When a job is first created, it always has a painting service requirement. But if the estimate had cleaning options selected, the cleaning requirement might have `required_crew_size = 1` (hardcoded default). The user should be able to set the required crew size per service from the scheduling stage.
   - This actually IS implemented in the UI already (per-service crew size inputs) but review that it works end-to-end.

8. **Crew available jobs view:** Currently shows a service category badge. But if a crew has `service_category_id = null` (legacy crews), they won't see any jobs. Need a backfill strategy or a "Set your service category" prompt for crews with null categories.

---

## Key Files Reference

| File | Purpose |
|------|---------|
| `app/Models/ServiceCategory.php` | Service category model with scopes, isPainting()/isCleaning() |
| `app/Models/JobServiceRequirement.php` | Per-service job requirements |
| `app/Models/JobPrepApproval.php` | Per-service per-party approvals |
| `app/Models/JobPrepServiceSetting.php` | Per-service crew settings |
| `app/Services/JobDispatchService.php` | Multi-service dispatch with scoped race conditions |
| `app/Services/JobPrepService.php` | Per-service prep plan, approval, negotiation |
| `app/Services/JobService.php` | Auto-creates service requirements from estimate |
| `app/Services/InvitationService.php` | Accepts service_category_id for crew invites |
| `resources/views/components/invitations/⚡send-invitation.blade.php` | Invitation modal (NEEDS service category dropdown) |
| `resources/views/components/crews/⚡show.blade.php` | Crew detail page (NEEDS service category display/edit) |
| `resources/views/components/crews/⚡index.blade.php` | Crews list (NEEDS service category filter/column) |
| `resources/views/components/estimates/⚡template-builder.blade.php` | Estimate builder (NEEDS service category on options) |
| `resources/views/components/jobs/⚡stage-scheduling.blade.php` | Dispatch UI (WORKING - multi-service tabs) |
| `resources/views/components/jobs/⚡stage-prep.blade.php` | Prep plan (WORKING - per-service settings) |
| `resources/views/pages/settings/⚡service-categories.blade.php` | Settings CRUD (WORKING) |
| `database/seeders/ServiceCategorySeeder.php` | Seeds Painting + Cleaning |

## Architecture Notes

- All Livewire components use SFC (single-file) pattern with `⚡` prefix
- Flux UI free edition for components
- Pest v4 for testing
- Laravel Echo/Reverb for real-time
- The `painting_jobs` table (not `jobs` - that's Laravel's queue table)
- `CrewProfile` is auto-created when a crew user is created (model boot event), so you update it after creation, not during

## Suggested Implementation Order

1. **Send Invitation modal** - Add service category dropdown for crew type (30 min)
2. **Crew show page** - Display + edit service category (30 min)
3. **Crews index** - Filter + column for service category (30 min)
4. **Estimate options** - Service category dropdown in template-builder (45 min)
5. **Backfill existing crews** - Add a prompt or migration to set existing crews to "Painting" (15 min)
6. **Test everything end-to-end** - Invite crew with service category, verify dispatch filters correctly (30 min)

Total: ~3 hours for Priority 1-2. Priority 3-4 are design decisions to make later.
