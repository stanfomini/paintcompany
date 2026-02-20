# Vendor Management System Design

## Context

The Vendor model and database table exist with rich compliance fields (W9, insurance, workers comp), but there are **zero vendors** in the database, **no routes**, and **no UI**. Permissions (`vendors.view`, `vendors.manage`) are already seeded. The payments hub and AR deal page reference `Vendor::where('is_active', true)` for bill creation but the dropdown is empty.

## Goals

1. Full CRUD vendor management page under Accounting sidebar group
2. Quick-add vendor modal from bill creation flows (payments hub + AR deal page)
3. Tax compliance tracking (W9, insurance, workers comp) with expiration warnings
4. Default GL expense account per vendor for smart defaults on bills
5. 1099 Tracker enhancement to include vendor payables alongside crew payables
6. Vendor payables relationship on the Vendor model
7. Comprehensive test coverage

## Architecture

### Phase 1: Migration - Add `default_expense_account_id` to vendors

Add a nullable FK to `accounts` table so each vendor can have a default GL expense account. When creating a bill for this vendor, the debit account auto-fills.

### Phase 2: Update Vendor Model

- Add `default_expense_account_id` to fillable
- Add `defaultExpenseAccount(): BelongsTo` relationship
- Add `payables(): HasMany` relationship (JobPayable where vendor_id = this)
- Add `totalPaidInYear(int $year): float` helper
- Add `is1099Required(int $year): bool` helper (paid >= $600 in year)
- Add `complianceStatus(): array` helper returning compliance checklist
- Add `hasExpiredInsurance(): bool` and `hasExpiredWorkersComp(): bool`
- Add `scopeExpiringSoon(Builder $query, int $days = 30)` scope
- Add `fullAddress(): Attribute` accessor

### Phase 3: Vendor Management Page

New Livewire SFC at `resources/views/pages/settings/⚡vendors.blade.php`

Features:
- Table listing all vendors with search, category filter, status filter
- Columns: Name, Contact, Category, Budget Category, W9, Insurance, Active
- Compliance badges (green checkmark / red X / yellow warning for expiring)
- Add/Edit flyout modal with all fields organized in sections:
  - **Basic Info**: name, contact_name, email, phone
  - **Address**: address_line_1, address_line_2, city, state, zip
  - **Accounting**: category, budget_category_id, default_expense_account_id, payment_terms_days
  - **Tax Compliance**: w9_on_file, hold_harmless, certificate_of_insurance, insurance_expiration, additional_insured, workers_comp, workers_comp_expiration, rejection_form
  - **Notes**: notes textarea
- Toggle active/inactive
- Delete with confirmation (block if has payables)
- Compliance summary at top (X vendors missing W9, Y with expiring insurance)

### Phase 4: Route + Sidebar + Settings Nav

- Route: `settings/vendors` with `vendors.view` permission
- Add to sidebar under Accounting group
- Add to settings layout navigation

### Phase 5: Quick-Add Vendor Modal

Dispatch a Livewire event from the vendor management page when a vendor is created. Add a simplified inline "quick add" in both:
- `⚡payments-hub.blade.php` - in the Create Bill modal
- `⚡ar-deal.blade.php` - in the Add Payable modal (when type=vendor)

Quick-add creates a vendor with minimal required fields (name, category) and auto-selects it in the dropdown.

### Phase 6: 1099 Tracker Enhancement

Update `⚡payments-hub.blade.php` 1099 tab to include vendor payables:
- Current: only queries crew payables by `payee_user_id`
- New: also query vendor payables by `vendor_id`, merge into results
- Show vendor name, total paid YTD, W9 status, 1099 required flag
- Separate sections or merged with clear type indicator

### Phase 7: Tests

`tests/Feature/Settings/VendorManagementTest.php`:
- CRUD operations (create, read, update, delete)
- Permission enforcement (vendors.view, vendors.manage)
- Quick-add vendor from bill creation
- Compliance status helpers
- 1099 threshold calculation
- Vendor with payables cannot be deleted
- Default expense account populates on bill creation
- Filter by category, status, search
