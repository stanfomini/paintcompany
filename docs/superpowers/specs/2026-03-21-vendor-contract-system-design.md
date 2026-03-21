# Vendor Contract System — Design Spec

**Date:** 2026-03-21
**Status:** Approved
**Approach:** Standalone system (Approach B) — new models/service/components, borrowing proven patterns from EmployeeContract and EstimateSignature

## Overview

A contract management system for vendors that allows creating, editing, signing, and managing contracts. Contracts are created from reusable templates, edited with a rich text editor, signed by configurable internal users and the vendor, and stored as compliance documents upon full execution.

## Data Model

### `vendor_contract_templates`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| name | string | e.g., "Subcontractor Agreement" |
| description | text, nullable | What this template is for |
| content | longText | Rich HTML content |
| is_active | boolean, default true | Can be selected when creating contracts |
| timestamps | | |

### `vendor_contracts`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| vendor_id | FK → vendors | |
| template_id | FK → vendor_contract_templates, nullable | Source template |
| title | string | Contract name |
| content | longText | Editable rich HTML (copied from template, then customized) |
| status | enum | `draft`, `pending_internal`, `pending_vendor`, `fully_executed`, `expired`, `invalidated` |
| is_valid | boolean, default true | Manual valid/invalid flag |
| expires_at | timestamp, nullable | When the contract expires |
| renewed_by_id | FK → vendor_contracts, nullable | Points to the renewal contract |
| renewed_from_id | FK → vendor_contracts, nullable | Points to the original contract |
| public_token | string(64), nullable, unique | For vendor signing URL |
| public_token_expires_at | timestamp, nullable | Token expiry |
| executed_pdf_path | string, nullable | Path to final signed PDF |
| created_by | FK → users | Who created it |
| timestamps | | |

**Status flow:**
```
draft → pending_internal → pending_vendor → fully_executed
                                          ↘ expired → (renew) → new draft
                                          ↘ invalidated
```

- `draft` — being edited, not yet ready for signatures
- `pending_internal` — sent for internal signing, all assigned signers must sign
- `pending_vendor` — all internal signatures collected, vendor link sent
- `fully_executed` — both sides signed
- `expired` — past expiration date (auto-set by scheduler)
- `invalidated` — manually marked invalid

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

## Service Layer

### `VendorContractService`

**Creation & Editing:**
- `createFromTemplate($vendor, $template, $signerUserIds[], $createdBy)` — copies template content into a new draft contract, assigns internal signers
- `updateContent($contract, $htmlContent)` — updates the rich text content (only while in `draft` status)
- `updateSigners($contract, $signerUserIds[])` — add/remove required internal signers

**Internal Signing Flow:**
- `sendForInternalSigning($contract)` — transitions `draft` → `pending_internal`, notifies assigned signers
- `signAsInternal($contract, $user, $signatureData, $signatureType)` — records signature for one internal signer; when all have signed, auto-transitions to `pending_vendor`

**Vendor Signing Flow:**
- `sendToVendor($contract)` — generates public token, sends email with signing link
- `signAsVendor($contract, $name, $email, $signatureData, $signatureType, $request)` — records vendor signature, transitions to `fully_executed`, triggers post-execution

**Post-Execution (on `fully_executed`):**
- Generate final PDF with all signatures via DomPDF
- Create `VendorDocument` record (type: `contract`, status: `approved`) linked to the PDF
- Send email to vendor with PDF download link
- Notify all internal signers that the contract is fully executed

**Validity & Expiration:**
- `invalidate($contract)` — manually marks `is_valid = false`, sets status to `invalidated`
- `renew($contract)` — creates a new draft contract cloned from the expired/expiring one, links via `renewed_from_id` / `renewed_by_id`, returns new contract for editing

## Scheduled Commands

**`vendor-contracts:check-expiration`** — runs daily via Laravel scheduler

- **30-day warning:** Finds contracts where `status = fully_executed` and `expires_at` is within 30 days. Sends `VendorContractExpiringSoon` notification to the contract creator and all internal signers. (Only sends once — track via a flag or check if already notified.)
- **Auto-expiry:** Finds contracts where `status = fully_executed` and `expires_at <= now()`. Transitions status to `expired`, sets `is_valid = false`. Sends `VendorContractExpired` notification.

## Notifications

| Notification | Recipients | Trigger |
|-------------|-----------|---------|
| `VendorContractInternalSigningRequest` | Each assigned internal signer | Contract sent for internal signing |
| `VendorContractSentToVendor` | Vendor (via email) | Public signing link generated |
| `VendorContractFullyExecuted` | All internal signers + vendor | Both sides signed |
| `VendorContractExpiringSoon` | Creator + internal signers | 30 days before expiration |
| `VendorContractExpired` | Creator + internal signers | Contract expires |

## UI Components & Pages

### Integrated into Existing Vendor Settings

**Inside `⚡vendors.blade.php`:**
- New "Contracts" button on each vendor row (alongside existing "Documents" button)
- Opens a **contracts flyout modal** listing all contracts for that vendor
- Contract list shows: title, status badge, expiry date, validity badge
- Actions per contract: Edit, Sign, Send to Vendor, View PDF, Download PDF, Invalidate, Renew
- "New Contract" button → template picker → creates draft → redirects to edit page

### Full Pages

**`⚡vendor-contract-editor.blade.php`** — Edit page (`/settings/vendors/{vendor}/contracts/{contract}/edit`)
- Rich text editor (WYSIWYG) for contract body
- Header/sidebar: title, expiration date, signer assignment (multi-select of internal users)
- Preview mode to see what the vendor will see
- Actions: Save Draft, Send for Internal Signing
- Only editable while in `draft` status — read-only after signing begins
- Navigation back to vendor settings

**`⚡vendor-contract-sign-internal.blade.php`** — Internal signing page (`/settings/vendors/{vendor}/contracts/{contract}/sign`)
- Displays contract content (read-only)
- Shows signing progress: who has signed, who hasn't
- Signature capture for current user (drawn or typed — reuse existing signature modal pattern)
- After all internal signatures: "Send to Vendor" button

**`⚡vendor-contract-sign.blade.php`** — Public vendor signing page (`/vendor/contract/{token}`)
- Same layout pattern as `⚡estimate-proposal.blade.php`
- Displays contract content (read-only)
- Shows internal signatures (names + dates, not actual images)
- Signature capture: name, email, drawn/typed signature, terms acceptance checkbox
- Confirmation message after signing + "Your copy will be emailed to you"

### Routes

**Internal (require auth + `vendors.manage`):**
- `/settings/vendors/{vendor}/contracts/{contract}/edit` — contract editor
- `/settings/vendors/{vendor}/contracts/{contract}/sign` — internal signing
- `/settings/vendors/{vendor}/contracts/{contract}/pdf` — download executed PDF

**Public (no auth, token-based):**
- `/vendor/contract/{token}` — vendor signing page
- `/vendor/contract/{token}/pdf` — download executed PDF

## PDF Generation

On `fully_executed`:
1. Render contract HTML content to PDF via DomPDF
2. Append signature page showing all internal signer names/signatures/dates and vendor signer name/signature/date, plus contract title and execution/expiration dates
3. Store at `vendor-contracts/{vendor_id}/{contract_id}/executed-contract.pdf`
4. Create `VendorDocument` record (type: `contract`, status: `approved`) linked to the PDF
5. Email vendor with PDF attached + download link

## Renewal Flow

1. User clicks "Renew" on an expired or about-to-expire contract
2. System clones the contract: same content, same signer assignments, new `draft` status
3. Links old → new via `renewed_by_id` and new → old via `renewed_from_id`
4. Redirects to edit page for term adjustments
5. Old contract stays as historical record

## Existing Patterns Reused

- **Public token system:** Same 64-char random token + expiry pattern as `Vendor` and `Estimate` models
- **Signature capture UI:** Reuse existing signature modal partial (SignaturePad.js for drawn, text input for typed)
- **Flyout modal pattern:** Same as existing documents modal in `⚡vendors.blade.php`
- **VendorDocument integration:** Reuse existing compliance document model and approval workflow
- **Notification pattern:** Follow existing `EstimateProposalSent` / `EstimateAccepted` patterns
- **PDF generation:** Follow existing estimate PDF generation via DomPDF
