# Vendor Contract System — Design Spec

**Date:** 2026-03-21
**Status:** Approved
**Approach:** Standalone system (Approach B) — new models/service/components, borrowing proven patterns from EmployeeContract and EstimateSignature

## Overview

A contract management system for vendors that allows creating, editing, signing, and managing contracts. Contracts are created from reusable templates, edited with a rich text editor, signed by configurable internal users and the vendor, and stored as compliance documents upon full execution.

## Data Model

### `contract_templates` (existing table — add `type` column)

The existing `contract_templates` table is reused. A new `type` column distinguishes employee vs vendor templates.

| Column | Type | Notes |
|--------|------|-------|
| type | string, default 'employee' | `employee` or `vendor` — filters which templates appear in each context |

Vendor-specific merge tags are supported: `{vendor_name}`, `{vendor_company}`, `{vendor_email}`, `{contract_date}`, `{expiration_date}`. Tags are auto-populated when a contract is created from a template.

### `vendor_contracts`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| vendor_id | FK → vendors | |
| template_id | FK → contract_templates, nullable | Source template |
| title | string | Contract name |
| content | longText | Editable rich HTML (copied from template, then customized) |
| status | enum | `draft`, `pending_internal`, `pending_vendor`, `fully_executed`, `expired`, `invalidated` |
| is_valid | boolean, default true | Manual valid/invalid flag |
| notes | text, nullable | Internal annotations (not shown in contract) |
| expires_at | timestamp, nullable | When the contract expires (null = evergreen) |
| expiry_warning_sent_at | timestamp, nullable | Tracks when 30-day warning was sent (prevents re-sending) |
| renewed_from_id | FK → vendor_contracts, nullable | Points to the original contract this was renewed from |
| public_token | string(64), nullable, unique | For vendor signing URL |
| public_token_expires_at | timestamp, nullable | Token expiry |
| executed_pdf_path | string, nullable | Path to final signed PDF |
| created_by | FK → users | Who created it |
| timestamps | | |
| softDeletes | | Draft contracts can be soft-deleted |

**`renewed_by_id` is NOT stored** — derive via `VendorContract::where('renewed_from_id', $this->id)->first()` to avoid forward-reference integrity issues.

**Status flow:**
```
draft ⇄ pending_internal → pending_vendor → fully_executed
  ↓          ↓                   ↓                ↓
delete   invalidated         invalidated      expired → (renew) → new draft
                                                  ↓
                                              invalidated
```

- `draft` — being edited, not yet ready for signatures. Can be soft-deleted or sent for signing.
- `pending_internal` — sent for internal signing. Can be **recalled to draft** (clears partial signatures) or invalidated.
- `pending_vendor` — all internal signatures collected, vendor link sent. Can be invalidated.
- `fully_executed` — both sides signed. Can expire or be manually invalidated.
- `expired` — past expiration date (auto-set by scheduler). Can trigger renewal.
- `invalidated` — manually marked invalid. Terminal state.

### `vendor_contract_signers`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| vendor_contract_id | FK → vendor_contracts | |
| user_id | FK → users | Internal user who must sign |
| order | tinyint | Signing order (for display) |
| signed_at | timestamp, nullable | When they signed |
| signature_data | text, nullable | Base64 PNG or typed text |
| signature_type | enum: drawn, typed, nullable | |
| signer_ip | string(45), nullable | IP at signing |
| signer_user_agent | text, nullable | Browser info |
| timestamps | | |

### `vendor_contract_vendor_signatures`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| vendor_contract_id | FK → vendor_contracts, unique | One vendor signature per contract |
| signer_name | string | Vendor rep name |
| signer_email | string | Vendor rep email |
| signature_data | text | Base64 PNG or typed text |
| signature_type | enum: drawn, typed | |
| signer_ip | string(45), nullable | |
| signer_user_agent | text, nullable | |
| signed_at | timestamp | |
| timestamps | | |

### `vendor_documents` (existing table — add FK)

| Column | Type | Notes |
|--------|------|-------|
| vendor_contract_id | FK → vendor_contracts, nullable | Links auto-created contract documents back to their source contract |

Add `vendorContract()` relationship on `VendorDocument` and inverse `vendorDocument()` on `VendorContract`.

### Model Relationships

**`Vendor`** — add `contracts(): HasMany` relationship
**`VendorContract`** — `vendor()`, `template()`, `signers()`, `vendorSignature()`, `vendorDocument()`, `renewedFrom()`, `renewal()` (derived), `creator()`
**`VendorDocument`** — add `vendorContract(): BelongsTo`

## Service Layer

### `VendorContractService`

**Creation & Editing:**
- `createFromTemplate($vendor, $template, $signerUserIds[], $createdBy)` — copies template content into a new draft, auto-populates merge tags, assigns internal signers
- `updateContent($contract, $htmlContent)` — updates rich text content (only while `draft`)
- `updateSigners($contract, $signerUserIds[])` — add/remove required internal signers (only while `draft`)

**Internal Signing Flow:**
- `sendForInternalSigning($contract)` — transitions `draft` → `pending_internal`, notifies assigned signers
- `recallToDraft($contract)` — transitions `pending_internal` → `draft`, clears all partial signatures (signed_at, signature_data, etc.)
- `signAsInternal($contract, $user, $signatureData, $signatureType)` — records signature for one internal signer; when all have signed, auto-transitions to `pending_vendor`

**Vendor Signing Flow:**
- `sendToVendor($contract)` — generates public token, sends email with signing link via on-demand notification (`Notification::route('mail', $vendor->email)`)
- `signAsVendor($contract, $name, $email, $signatureData, $signatureType, $request)` — records vendor signature, transitions to `fully_executed`, triggers post-execution. Public token is kept alive so the vendor can still access the PDF download via their original link. The signing form is hidden based on contract status (not token presence).

**Post-Execution (on `fully_executed`):**
- Generate final PDF with all signatures via DomPDF
- Create `VendorDocument` record (type: `contract`, status: `approved`, `vendor_contract_id` set) linked to the PDF
- Send email to vendor with PDF download link
- Notify all internal signers that the contract is fully executed

**Validity & Expiration:**
- `invalidate($contract)` — allowed from `pending_internal`, `pending_vendor`, or `fully_executed`. Sets `is_valid = false`, status to `invalidated`. Nullifies public token if set.
- `renew($contract)` — guarded: only if no existing non-invalidated renewal exists (`VendorContract::where('renewed_from_id', $contract->id)->whereNotIn('status', ['invalidated'])->doesntExist()`). Creates a new draft cloned from the contract, links via `renewed_from_id`.

## Scheduled Commands

**`vendor-contracts:check-expiration`** — runs daily via Laravel scheduler

Both queries filter `whereNotNull('expires_at')` to skip evergreen contracts.

- **30-day warning:** Finds contracts where `status = fully_executed`, `expires_at` is within 30 days, and `expiry_warning_sent_at` is null. Sends `VendorContractExpiringSoon` notification to creator, internal signers, and vendor. Sets `expiry_warning_sent_at` to now.
- **Auto-expiry:** Finds contracts where `status = fully_executed` and `expires_at <= now()`. Transitions status to `expired`, sets `is_valid = false`. Sends `VendorContractExpired` notification to creator, internal signers, and vendor.

## Notifications

| Notification | Recipients | Delivery | Trigger |
|-------------|-----------|----------|---------|
| `VendorContractInternalSigningRequest` | Each assigned internal signer | Database + Mail | Contract sent for internal signing |
| `VendorContractSentToVendor` | Vendor | On-demand Mail (`Notification::route`) | Public signing link generated |
| `VendorContractFullyExecuted` | All internal signers + vendor | Database + Mail (internal), On-demand Mail (vendor) | Both sides signed |
| `VendorContractExpiringSoon` | Creator + internal signers + vendor | Database + Mail (internal), On-demand Mail (vendor) | 30 days before expiration |
| `VendorContractExpired` | Creator + internal signers + vendor | Database + Mail (internal), On-demand Mail (vendor) | Contract expires |

## UI Components & Pages

### Integrated into Existing Vendor Settings

**Inside `⚡vendors.blade.php`:**
- New "Contracts" button on each vendor row (alongside existing "Documents" button)
- Opens a **contracts flyout modal** listing all contracts for that vendor
- Contract list shows: title, status badge, expiry date, validity badge
- Actions per contract (context-dependent on status):
  - `draft`: Edit, Delete
  - `pending_internal`: Sign (if current user is an unsigned signer), Recall to Draft, Invalidate
  - `pending_vendor`: Send to Vendor (if not yet sent), Invalidate
  - `fully_executed`: View PDF, Download PDF, Invalidate, Renew
  - `expired`: View PDF, Download PDF, Renew
- "New Contract" button → template picker → creates draft → redirects to edit page

### Full Pages

**`⚡vendor-contract-editor.blade.php`** — Edit page (`/settings/vendors/{vendor}/contracts/{contract}/edit`)
- Rich text editor (WYSIWYG) for contract body
- Header/sidebar: title, expiration date, notes, signer assignment (multi-select of internal users)
- Preview mode to see what the vendor will see
- Actions: Save Draft, Send for Internal Signing
- Only editable while in `draft` status — read-only after signing begins
- Navigation back to vendor settings

**`⚡vendor-contract-sign-internal.blade.php`** — Internal signing page (`/settings/vendors/{vendor}/contracts/{contract}/sign`)
- Displays contract content (read-only)
- Shows signing progress: who has signed, who hasn't
- Signature capture for current user (drawn or typed — reuse existing signature modal pattern)
- After all internal signatures: "Send to Vendor" button
- Recall to Draft option (clears all signatures, returns to editing)

**`⚡vendor-contract-sign.blade.php`** — Public vendor signing page (`/vendor/contract/{token}`)
- Same layout pattern as `⚡estimate-proposal.blade.php`
- Displays contract content (read-only)
- Shows internal signatures (names + dates, not actual images)
- Signature capture: name, email, drawn/typed signature, terms acceptance checkbox
- After signing: shows read-only confirmation state ("Contract signed. Your copy will be emailed to you.") — signing form is hidden
- If already signed: shows confirmation state immediately (no re-signing)

### Routes

**Internal (require auth + `vendors.manage`):**
- `/settings/vendors/{vendor}/contracts/{contract}/edit` — contract editor
- `/settings/vendors/{vendor}/contracts/{contract}/sign` — internal signing
- `/settings/vendors/{vendor}/contracts/{contract}/pdf` — download executed PDF (only when `fully_executed` and `executed_pdf_path` exists, else 404)

**Public (no auth, token-based, `throttle:60,1` middleware):**
- `/vendor/contract/{token}` — vendor signing page
- `/vendor/contract/{token}/pdf` — download executed PDF (only when `fully_executed` and `executed_pdf_path` exists, else 404)

## PDF Generation

On `fully_executed`:
1. Render contract HTML content to PDF via DomPDF
2. Append signature page showing all internal signer names/signatures/dates and vendor signer name/signature/date, plus contract title and execution/expiration dates
3. Store at `vendor-contracts/{vendor_id}/{contract_id}/executed-contract.pdf`
4. Create `VendorDocument` record (type: `contract`, status: `approved`, `vendor_contract_id` set) linked to the PDF
5. Email vendor with PDF attached + download link

## Renewal Flow

1. User clicks "Renew" on an expired or about-to-expire contract
2. Guard: only allowed if no existing non-invalidated renewal already exists for this contract
3. System clones the contract: same content, same signer assignments, new `draft` status
4. Links new → old via `renewed_from_id`
5. Redirects to edit page for term adjustments
6. Old contract stays as historical record

## Security

- Public routes use `throttle:60,1` middleware
- Public token is kept alive after vendor signs so vendor can still download the executed PDF via their original link
- The signing form is hidden based on contract status (fully_executed), not token presence — prevents re-signing while allowing PDF access
- Public PDF route returns 404 unless contract is `fully_executed` with a valid `executed_pdf_path`
- After vendor signs, public page shows read-only confirmation (not the signing form)
- All internal routes require `vendors.manage` permission
- Signature audit trail: IP + user agent captured for both internal and vendor signatures

## Existing Patterns Reused

- **Public token system:** Same 64-char random token + expiry pattern as `Vendor` and `Estimate` models
- **Contract templates:** Reuse existing `contract_templates` table with new `type` column for vendor templates
- **Signature capture UI:** Reuse existing signature modal partial (SignaturePad.js for drawn, text input for typed)
- **Flyout modal pattern:** Same as existing documents modal in `⚡vendors.blade.php`
- **VendorDocument integration:** Reuse existing compliance document model with new FK back to contracts
- **Notification pattern:** Follow existing `EstimateProposalSent` / `EstimateAccepted` patterns; vendor notifications via on-demand routing
- **PDF generation:** Follow existing estimate PDF generation via DomPDF
- **SoftDeletes:** Draft contracts can be soft-deleted for cleanup without losing audit trail
