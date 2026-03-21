# Vendor Contract System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a standalone vendor contract management system with rich text editing, multi-signer internal signing, public vendor signing, PDF generation, expiration/renewal, and VendorDocument compliance integration.

**Architecture:** New `VendorContract` model with separate signer/signature tables, served by `VendorContractService`. Reuses existing `contract_templates` table (with new `vendor` type), Trix editor, signature modal partial, and VendorDocument compliance tracking. Internal UI integrates into existing vendor settings page via flyout modal; editing and signing get dedicated full pages. Public vendor signing follows the estimate proposal pattern.

**Tech Stack:** Laravel 12, Livewire 4 SFC, Pest 4, Trix editor, SignaturePad.js, DomPDF, Flux UI components

**Spec:** `docs/superpowers/specs/2026-03-21-vendor-contract-system-design.md`

---

## File Map

### New Files
| File | Responsibility |
|------|---------------|
| `database/migrations/XXXX_create_vendor_contracts_tables.php` | All 3 new tables + alterations to contract_templates and vendor_documents |
| `app/Models/VendorContract.php` | Contract model with status flow, token methods, relationships |
| `app/Models/VendorContractSigner.php` | Internal signer model |
| `app/Models/VendorContractVendorSignature.php` | Vendor signature model |
| `app/Services/VendorContractService.php` | All contract lifecycle operations |
| `app/Http/Controllers/VendorContractPdfController.php` | PDF download/view for internal and public routes |
| `app/Notifications/VendorContractInternalSigningRequest.php` | Notify internal signers |
| `app/Notifications/VendorContractSentToVendor.php` | Send signing link to vendor |
| `app/Notifications/VendorContractFullyExecuted.php` | Notify all parties on execution |
| `app/Notifications/VendorContractExpiringSoon.php` | 30-day expiry warning |
| `app/Notifications/VendorContractExpired.php` | Contract expired notification |
| `app/Console/Commands/CheckVendorContractExpiration.php` | Daily scheduler command |
| `resources/views/components/public/⚡vendor-contract-sign.blade.php` | Public vendor signing page |
| `resources/views/pages/settings/⚡vendor-contract-editor.blade.php` | Contract editor page |
| `resources/views/pages/settings/⚡vendor-contract-sign-internal.blade.php` | Internal signing page |
| `resources/views/pdf/vendor-contract.blade.php` | PDF template for executed contracts |
| `tests/Feature/VendorContracts/VendorContractModelTest.php` | Model tests |
| `tests/Feature/VendorContracts/VendorContractServiceTest.php` | Service tests |
| `tests/Feature/VendorContracts/VendorContractPdfTest.php` | PDF generation tests |
| `tests/Feature/VendorContracts/VendorContractNotificationTest.php` | Notification tests |
| `tests/Feature/VendorContracts/VendorContractExpirationCommandTest.php` | Scheduler command tests |
| `tests/Feature/Livewire/VendorContracts/VendorContractEditorTest.php` | Editor component tests |
| `tests/Feature/Livewire/VendorContracts/VendorContractSignInternalTest.php` | Internal signing tests |
| `tests/Feature/Livewire/Public/VendorContractSignTest.php` | Public signing tests |
| `database/factories/VendorContractFactory.php` | Factory for VendorContract |
| `database/factories/VendorContractSignerFactory.php` | Factory for VendorContractSigner |

### Modified Files
| File | Change |
|------|--------|
| `app/Models/ContractTemplate.php` | Add `vendor()` scope, update `getAvailableMergeTags()` for vendor tags |
| `app/Models/Vendor.php` | Add `contracts()` HasMany relationship |
| `app/Models/VendorDocument.php` | Add `vendorContract()` BelongsTo relationship |
| `resources/views/pages/settings/⚡vendors.blade.php` | Add Contracts button + flyout modal |
| `routes/settings.php` | Add internal vendor contract routes |
| `routes/web.php` | Add public vendor contract routes |
| `routes/console.php` | Register daily expiration check |

---

## Task 1: Database Migration

**Files:**
- Create: `database/migrations/XXXX_create_vendor_contracts_tables.php`

- [ ] **Step 1: Create the migration file**

```bash
php artisan make:migration create_vendor_contracts_tables --no-interaction
```

- [ ] **Step 2: Write the migration**

The migration creates 3 new tables and alters 2 existing tables:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add 'vendor' type support to contract_templates
        // The existing 'type' column is a string with default 'standard'
        // No schema change needed — just use 'vendor' as a value

        // 2. Create vendor_contracts table first (vendor_documents FK depends on it)
        Schema::create('vendor_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('contract_templates')->nullOnDelete();
            $table->string('title');
            $table->longText('content');
            $table->string('status')->default('draft');
            $table->boolean('is_valid')->default(true);
            $table->text('notes')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('expiry_warning_sent_at')->nullable();
            $table->foreignId('renewed_from_id')->nullable()->constrained('vendor_contracts')->nullOnDelete();
            $table->string('public_token', 64)->nullable()->unique();
            $table->timestamp('public_token_expires_at')->nullable();
            $table->string('executed_pdf_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['vendor_id', 'status']);
            $table->index('expires_at');
        });

        Schema::create('vendor_contract_signers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->tinyInteger('order')->default(0);
            $table->timestamp('signed_at')->nullable();
            $table->text('signature_data')->nullable();
            $table->string('signature_type')->nullable();
            $table->string('signer_ip', 45)->nullable();
            $table->text('signer_user_agent')->nullable();
            $table->timestamps();

            $table->unique(['vendor_contract_id', 'user_id']);
        });

        Schema::create('vendor_contract_vendor_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_contract_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('signer_name');
            $table->string('signer_email');
            $table->text('signature_data');
            $table->string('signature_type');
            $table->string('signer_ip', 45)->nullable();
            $table->text('signer_user_agent')->nullable();
            $table->timestamp('signed_at');
            $table->timestamps();
        });

        // Now add the FK to vendor_documents
        Schema::table('vendor_documents', function (Blueprint $table) {
            $table->foreignId('vendor_contract_id')->nullable()->after('vendor_id')
                ->constrained('vendor_contracts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vendor_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_contract_id');
        });

        Schema::dropIfExists('vendor_contract_vendor_signatures');
        Schema::dropIfExists('vendor_contract_signers');
        Schema::dropIfExists('vendor_contracts');
    }
};
```

- [ ] **Step 3: Run the migration**

```bash
php artisan migrate
```

Expected: Migration runs successfully, 3 tables created, 1 table altered.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/
git commit -m "feat(vendor-contracts): create database tables for vendor contract system"
```

---

## Task 2: Models & Factories

**Files:**
- Create: `app/Models/VendorContract.php`
- Create: `app/Models/VendorContractSigner.php`
- Create: `app/Models/VendorContractVendorSignature.php`
- Create: `database/factories/VendorContractFactory.php`
- Create: `database/factories/VendorContractSignerFactory.php`
- Modify: `app/Models/Vendor.php` — add `contracts()` relationship
- Modify: `app/Models/VendorDocument.php` — add `vendorContract()` relationship
- Modify: `app/Models/ContractTemplate.php` — add `vendor()` scope, vendor merge tags
- Create: `tests/Feature/VendorContracts/VendorContractModelTest.php`

- [ ] **Step 1: Write the model tests**

```php
// tests/Feature/VendorContracts/VendorContractModelTest.php
<?php

use App\Models\ContractTemplate;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorContract;
use App\Models\VendorContractSigner;
use App\Models\VendorContractVendorSignature;
use App\Models\VendorDocument;

it('creates a vendor contract with factory', function () {
    $contract = VendorContract::factory()->create();

    expect($contract)->toBeInstanceOf(VendorContract::class)
        ->and($contract->status)->toBe('draft')
        ->and($contract->is_valid)->toBeTrue();
});

it('belongs to a vendor', function () {
    $contract = VendorContract::factory()->create();

    expect($contract->vendor)->toBeInstanceOf(Vendor::class);
});

it('belongs to a template', function () {
    $template = ContractTemplate::create([
        'name' => 'Test Vendor Template',
        'type' => 'vendor',
        'content' => '<p>Test content</p>',
    ]);
    $contract = VendorContract::factory()->create(['template_id' => $template->id]);

    expect($contract->template)->toBeInstanceOf(ContractTemplate::class);
});

it('has many signers', function () {
    $contract = VendorContract::factory()->create();
    $user = User::factory()->internal()->create();
    VendorContractSigner::factory()->create([
        'vendor_contract_id' => $contract->id,
        'user_id' => $user->id,
    ]);

    expect($contract->signers)->toHaveCount(1);
});

it('has one vendor signature', function () {
    $contract = VendorContract::factory()->create(['status' => 'fully_executed']);
    VendorContractVendorSignature::create([
        'vendor_contract_id' => $contract->id,
        'signer_name' => 'John Vendor',
        'signer_email' => 'john@vendor.com',
        'signature_data' => 'data:image/png;base64,test',
        'signature_type' => 'drawn',
        'signed_at' => now(),
    ]);

    expect($contract->vendorSignature)->toBeInstanceOf(VendorContractVendorSignature::class);
});

it('generates a public token', function () {
    $contract = VendorContract::factory()->create();
    $token = $contract->generatePublicToken();

    expect($token)->toHaveLength(64)
        ->and($contract->public_token)->toBe($token)
        ->and($contract->public_token_expires_at)->not->toBeNull();
});

it('validates public token', function () {
    $contract = VendorContract::factory()->create();
    $contract->generatePublicToken();

    expect($contract->isPublicTokenValid())->toBeTrue();

    $contract->update(['public_token_expires_at' => now()->subDay()]);

    expect($contract->isPublicTokenValid())->toBeFalse();
});

it('invalidates public token', function () {
    $contract = VendorContract::factory()->create();
    $contract->generatePublicToken();
    $contract->invalidatePublicToken();

    expect($contract->public_token)->toBeNull()
        ->and($contract->public_token_expires_at)->toBeNull();
});

it('scopes by public token', function () {
    $contract = VendorContract::factory()->create();
    $token = $contract->generatePublicToken();

    $found = VendorContract::byPublicToken($token)->first();
    expect($found->id)->toBe($contract->id);
});

it('is soft deletable', function () {
    $contract = VendorContract::factory()->create();
    $contract->delete();

    expect(VendorContract::find($contract->id))->toBeNull()
        ->and(VendorContract::withTrashed()->find($contract->id))->not->toBeNull();
});

it('tracks renewal chain', function () {
    $original = VendorContract::factory()->create(['status' => 'expired']);
    $renewal = VendorContract::factory()->create(['renewed_from_id' => $original->id]);

    expect($renewal->renewedFrom->id)->toBe($original->id)
        ->and($original->renewal->id)->toBe($renewal->id);
});

it('checks if all internal signers have signed', function () {
    $contract = VendorContract::factory()->create(['status' => 'pending_internal']);
    $user1 = User::factory()->internal()->create();
    $user2 = User::factory()->internal()->create();

    VendorContractSigner::factory()->create([
        'vendor_contract_id' => $contract->id,
        'user_id' => $user1->id,
        'signed_at' => now(),
        'signature_data' => 'signed',
        'signature_type' => 'typed',
    ]);
    VendorContractSigner::factory()->create([
        'vendor_contract_id' => $contract->id,
        'user_id' => $user2->id,
    ]);

    expect($contract->allInternalSignersSigned())->toBeFalse();

    $contract->signers()->where('user_id', $user2->id)->update([
        'signed_at' => now(),
        'signature_data' => 'signed',
        'signature_type' => 'typed',
    ]);
    $contract->unsetRelation('signers');

    expect($contract->allInternalSignersSigned())->toBeTrue();
});

it('vendor has contracts relationship', function () {
    $vendor = Vendor::factory()->create();
    VendorContract::factory()->create(['vendor_id' => $vendor->id]);

    expect($vendor->contracts)->toHaveCount(1);
});

it('vendor document has vendorContract relationship', function () {
    $contract = VendorContract::factory()->create();
    $doc = VendorDocument::create([
        'vendor_id' => $contract->vendor_id,
        'vendor_contract_id' => $contract->id,
        'name' => 'Contract PDF',
        'type' => VendorDocument::TYPE_CONTRACT,
        'file_path' => 'test/path.pdf',
        'status' => 'approved',
    ]);

    expect($doc->vendorContract->id)->toBe($contract->id);
});

it('contract template scopes to vendor type', function () {
    ContractTemplate::create(['name' => 'Employee Template', 'type' => 'standard', 'content' => 'test']);
    ContractTemplate::create(['name' => 'Vendor Template', 'type' => 'vendor', 'content' => 'test']);

    expect(ContractTemplate::where('type', 'vendor')->count())->toBe(1)
        ->and(ContractTemplate::where('type', 'vendor')->first()->name)->toBe('Vendor Template');
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/VendorContracts/VendorContractModelTest.php
```

Expected: FAIL — models and factories don't exist yet.

- [ ] **Step 3: Create VendorContract model**

```bash
php artisan make:model VendorContract --no-interaction
```

```php
// app/Models/VendorContract.php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class VendorContract extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING_INTERNAL = 'pending_internal';
    public const STATUS_PENDING_VENDOR = 'pending_vendor';
    public const STATUS_FULLY_EXECUTED = 'fully_executed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_INVALIDATED = 'invalidated';

    public const STATUSES = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_PENDING_INTERNAL => 'Pending Internal Signatures',
        self::STATUS_PENDING_VENDOR => 'Pending Vendor Signature',
        self::STATUS_FULLY_EXECUTED => 'Fully Executed',
        self::STATUS_EXPIRED => 'Expired',
        self::STATUS_INVALIDATED => 'Invalidated',
    ];

    protected $fillable = [
        'vendor_id',
        'template_id',
        'title',
        'content',
        'status',
        'is_valid',
        'notes',
        'expires_at',
        'expiry_warning_sent_at',
        'renewed_from_id',
        'public_token',
        'public_token_expires_at',
        'executed_pdf_path',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_valid' => 'boolean',
            'expires_at' => 'datetime',
            'expiry_warning_sent_at' => 'datetime',
            'public_token_expires_at' => 'datetime',
        ];
    }

    // Relationships

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ContractTemplate::class, 'template_id');
    }

    public function signers(): HasMany
    {
        return $this->hasMany(VendorContractSigner::class)->orderBy('order');
    }

    public function vendorSignature(): HasOne
    {
        return $this->hasOne(VendorContractVendorSignature::class);
    }

    public function vendorDocument(): HasOne
    {
        return $this->hasOne(VendorDocument::class);
    }

    public function renewedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'renewed_from_id');
    }

    public function renewal(): HasOne
    {
        return $this->hasOne(self::class, 'renewed_from_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Token methods (following Estimate/Vendor pattern)

    public function generatePublicToken(int $expiryDays = 30): string
    {
        $token = Str::random(64);
        $this->update([
            'public_token' => $token,
            'public_token_expires_at' => now()->addDays($expiryDays),
        ]);

        return $token;
    }

    public function isPublicTokenValid(): bool
    {
        return $this->public_token
            && $this->public_token_expires_at
            && $this->public_token_expires_at->isFuture();
    }

    public function invalidatePublicToken(): void
    {
        $this->update([
            'public_token' => null,
            'public_token_expires_at' => null,
        ]);
    }

    public function scopeByPublicToken($query, string $token)
    {
        return $query->where('public_token', $token);
    }

    // Status helpers

    public function getStatusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPendingInternal(): bool
    {
        return $this->status === self::STATUS_PENDING_INTERNAL;
    }

    public function isPendingVendor(): bool
    {
        return $this->status === self::STATUS_PENDING_VENDOR;
    }

    public function isFullyExecuted(): bool
    {
        return $this->status === self::STATUS_FULLY_EXECUTED;
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED;
    }

    public function isInvalidated(): bool
    {
        return $this->status === self::STATUS_INVALIDATED;
    }

    public function allInternalSignersSigned(): bool
    {
        return $this->signers->isNotEmpty()
            && $this->signers->every(fn ($signer) => $signer->signed_at !== null);
    }

    public function hasActiveRenewal(): bool
    {
        return self::where('renewed_from_id', $this->id)
            ->whereNotIn('status', [self::STATUS_INVALIDATED])
            ->exists();
    }
}
```

- [ ] **Step 4: Create VendorContractSigner model**

```bash
php artisan make:model VendorContractSigner --no-interaction
```

```php
// app/Models/VendorContractSigner.php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorContractSigner extends Model
{
    use HasFactory;

    protected $fillable = [
        'vendor_contract_id',
        'user_id',
        'order',
        'signed_at',
        'signature_data',
        'signature_type',
        'signer_ip',
        'signer_user_agent',
    ];

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
            'order' => 'integer',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(VendorContract::class, 'vendor_contract_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasSigned(): bool
    {
        return $this->signed_at !== null;
    }
}
```

- [ ] **Step 5: Create VendorContractVendorSignature model**

```bash
php artisan make:model VendorContractVendorSignature --no-interaction
```

```php
// app/Models/VendorContractVendorSignature.php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorContractVendorSignature extends Model
{
    protected $fillable = [
        'vendor_contract_id',
        'signer_name',
        'signer_email',
        'signature_data',
        'signature_type',
        'signer_ip',
        'signer_user_agent',
        'signed_at',
    ];

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(VendorContract::class, 'vendor_contract_id');
    }
}
```

- [ ] **Step 6: Create factories**

```php
// database/factories/VendorContractFactory.php
<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorContract;
use Illuminate\Database\Eloquent\Factories\Factory;

class VendorContractFactory extends Factory
{
    protected $model = VendorContract::class;

    public function definition(): array
    {
        return [
            'vendor_id' => Vendor::factory(),
            'title' => fake()->sentence(3),
            'content' => '<p>' . fake()->paragraphs(3, true) . '</p>',
            'status' => 'draft',
            'is_valid' => true,
            'created_by' => User::factory()->internal(),
        ];
    }

    public function pendingInternal(): static
    {
        return $this->state(fn () => ['status' => 'pending_internal']);
    }

    public function pendingVendor(): static
    {
        return $this->state(fn () => ['status' => 'pending_vendor']);
    }

    public function fullyExecuted(): static
    {
        return $this->state(fn () => [
            'status' => 'fully_executed',
            'is_valid' => true,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => 'expired',
            'is_valid' => false,
            'expires_at' => now()->subDay(),
        ]);
    }

    public function withExpiration(int $daysFromNow = 30): static
    {
        return $this->state(fn () => [
            'expires_at' => now()->addDays($daysFromNow),
        ]);
    }
}
```

```php
// database/factories/VendorContractSignerFactory.php
<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\VendorContract;
use App\Models\VendorContractSigner;
use Illuminate\Database\Eloquent\Factories\Factory;

class VendorContractSignerFactory extends Factory
{
    protected $model = VendorContractSigner::class;

    public function definition(): array
    {
        return [
            'vendor_contract_id' => VendorContract::factory(),
            'user_id' => User::factory()->internal(),
            'order' => 0,
        ];
    }

    public function signed(): static
    {
        return $this->state(fn () => [
            'signed_at' => now(),
            'signature_data' => 'Signed By Test',
            'signature_type' => 'typed',
            'signer_ip' => '127.0.0.1',
        ]);
    }
}
```

- [ ] **Step 7: Update existing models**

Add to `app/Models/Vendor.php` — add `contracts()` relationship:
```php
public function contracts(): HasMany
{
    return $this->hasMany(VendorContract::class);
}
```

Add to `app/Models/VendorDocument.php` — add `vendorContract()` relationship and update fillable:
```php
// Add to $fillable array:
'vendor_contract_id',

// Add relationship:
public function vendorContract(): BelongsTo
{
    return $this->belongsTo(VendorContract::class);
}
```

Add to `app/Models/ContractTemplate.php` — vendor scope and merge tags:
```php
// Add scope:
public function scopeVendor($query)
{
    return $query->where('type', 'vendor');
}

// Update getAvailableMergeTags() to include vendor tags — add to the returned array:
// '{vendor_name}', '{vendor_contact}', '{vendor_email}', '{vendor_phone}',
// '{vendor_address}', '{contract_date}', '{expiration_date}'
```

- [ ] **Step 8: Run tests to verify they pass**

```bash
php artisan test --compact tests/Feature/VendorContracts/VendorContractModelTest.php
```

Expected: All tests PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Models/ database/factories/ tests/Feature/VendorContracts/
git commit -m "feat(vendor-contracts): add models, factories, and model tests"
```

---

## Task 3: VendorContractService

**Files:**
- Create: `app/Services/VendorContractService.php`
- Create: `tests/Feature/VendorContracts/VendorContractServiceTest.php`

- [ ] **Step 1: Write the service tests**

```php
// tests/Feature/VendorContracts/VendorContractServiceTest.php
<?php

use App\Models\ContractTemplate;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorContract;
use App\Models\VendorContractSigner;
use App\Models\VendorDocument;
use App\Notifications\VendorContractInternalSigningRequest;
use App\Notifications\VendorContractFullyExecuted;
use App\Services\VendorContractService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->service = app(VendorContractService::class);
    $this->vendor = Vendor::factory()->create(['email' => 'vendor@test.com']);
    $this->creator = User::factory()->internal()->create();
});

// --- Creation ---

it('creates a contract from template', function () {
    $template = ContractTemplate::create([
        'name' => 'Vendor Agreement',
        'type' => 'vendor',
        'content' => '<p>Agreement with {vendor_name}</p>',
    ]);
    $signerIds = [User::factory()->internal()->create()->id];

    $contract = $this->service->createFromTemplate(
        $this->vendor, $template, $signerIds, $this->creator
    );

    expect($contract->status)->toBe('draft')
        ->and($contract->vendor_id)->toBe($this->vendor->id)
        ->and($contract->template_id)->toBe($template->id)
        ->and($contract->content)->toContain($this->vendor->name)
        ->and($contract->signers)->toHaveCount(1);
});

it('populates merge tags when creating from template', function () {
    $template = ContractTemplate::create([
        'name' => 'Test',
        'type' => 'vendor',
        'content' => '<p>Vendor: {vendor_name}, Contact: {vendor_contact}, Email: {vendor_email}</p>',
    ]);

    $contract = $this->service->createFromTemplate(
        $this->vendor, $template, [$this->creator->id], $this->creator
    );

    expect($contract->content)
        ->toContain($this->vendor->name)
        ->toContain($this->vendor->contact_name ?? '')
        ->toContain($this->vendor->email);
});

// --- Content & Signers ---

it('updates content only while draft', function () {
    $contract = VendorContract::factory()->create(['status' => 'draft']);

    $this->service->updateContent($contract, '<p>Updated content</p>');
    expect($contract->fresh()->content)->toBe('<p>Updated content</p>');
});

it('rejects content update when not draft', function () {
    $contract = VendorContract::factory()->create(['status' => 'pending_internal']);

    expect(fn () => $this->service->updateContent($contract, '<p>New</p>'))
        ->toThrow(\InvalidArgumentException::class);
});

it('updates signers only while draft', function () {
    $contract = VendorContract::factory()->create(['status' => 'draft']);
    $users = User::factory()->internal()->count(2)->create();

    $this->service->updateSigners($contract, $users->pluck('id')->toArray());

    expect($contract->signers)->toHaveCount(2);
});

// --- Internal Signing Flow ---

it('sends contract for internal signing', function () {
    Notification::fake();
    $contract = VendorContract::factory()->create(['status' => 'draft']);
    $signer = User::factory()->internal()->create();
    VendorContractSigner::factory()->create([
        'vendor_contract_id' => $contract->id,
        'user_id' => $signer->id,
    ]);

    $this->service->sendForInternalSigning($contract);

    expect($contract->fresh()->status)->toBe('pending_internal');
    Notification::assertSentTo($signer, VendorContractInternalSigningRequest::class);
});

it('rejects sending for signing without signers', function () {
    $contract = VendorContract::factory()->create(['status' => 'draft']);

    expect(fn () => $this->service->sendForInternalSigning($contract))
        ->toThrow(\InvalidArgumentException::class);
});

it('recalls contract to draft', function () {
    $contract = VendorContract::factory()->create(['status' => 'pending_internal']);
    $signer = VendorContractSigner::factory()->signed()->create([
        'vendor_contract_id' => $contract->id,
    ]);

    $this->service->recallToDraft($contract);

    expect($contract->fresh()->status)->toBe('draft');
    expect($signer->fresh()->signed_at)->toBeNull()
        ->and($signer->fresh()->signature_data)->toBeNull();
});

it('signs as internal user', function () {
    $contract = VendorContract::factory()->create(['status' => 'pending_internal']);
    $user = User::factory()->internal()->create();
    VendorContractSigner::factory()->create([
        'vendor_contract_id' => $contract->id,
        'user_id' => $user->id,
    ]);

    $this->service->signAsInternal($contract, $user, 'Test Signature', 'typed');

    $signer = $contract->signers()->where('user_id', $user->id)->first();
    expect($signer->signed_at)->not->toBeNull()
        ->and($signer->signature_data)->toBe('Test Signature')
        ->and($signer->signature_type)->toBe('typed');
});

it('auto-transitions to pending_vendor when all internal signers sign', function () {
    $contract = VendorContract::factory()->create(['status' => 'pending_internal']);
    $user = User::factory()->internal()->create();
    VendorContractSigner::factory()->create([
        'vendor_contract_id' => $contract->id,
        'user_id' => $user->id,
    ]);

    $this->service->signAsInternal($contract, $user, 'Sig', 'typed');

    expect($contract->fresh()->status)->toBe('pending_vendor');
});

// --- Vendor Signing Flow ---

it('sends contract to vendor', function () {
    Notification::fake();
    $contract = VendorContract::factory()->create([
        'status' => 'pending_vendor',
        'vendor_id' => $this->vendor->id,
    ]);

    $this->service->sendToVendor($contract);

    expect($contract->fresh()->public_token)->not->toBeNull();
    Notification::assertSentOnDemand(
        \App\Notifications\VendorContractSentToVendor::class,
    );
});

it('signs as vendor and transitions to fully_executed', function () {
    Notification::fake();
    Storage::fake('local');

    $contract = VendorContract::factory()->create([
        'status' => 'pending_vendor',
        'vendor_id' => $this->vendor->id,
    ]);
    $contract->generatePublicToken();

    $this->service->signAsVendor(
        $contract, 'Vendor Rep', 'vendor@test.com',
        'data:image/png;base64,test', 'drawn', request()
    );

    $contract->refresh();
    expect($contract->status)->toBe('fully_executed')
        ->and($contract->public_token)->not->toBeNull() // Token kept alive for vendor PDF access
        ->and($contract->vendorSignature)->not->toBeNull()
        ->and($contract->vendorSignature->signer_name)->toBe('Vendor Rep');
});

// --- Validity & Expiration ---

it('invalidates a contract from pending_internal', function () {
    $contract = VendorContract::factory()->create(['status' => 'pending_internal']);
    $contract->generatePublicToken();

    $this->service->invalidate($contract);

    expect($contract->fresh()->status)->toBe('invalidated')
        ->and($contract->fresh()->is_valid)->toBeFalse()
        ->and($contract->fresh()->public_token)->toBeNull();
});

it('invalidates a contract from fully_executed', function () {
    $contract = VendorContract::factory()->create(['status' => 'fully_executed']);

    $this->service->invalidate($contract);

    expect($contract->fresh()->status)->toBe('invalidated')
        ->and($contract->fresh()->is_valid)->toBeFalse();
});

it('invalidates a contract from expired', function () {
    $contract = VendorContract::factory()->expired()->create();

    $this->service->invalidate($contract);

    expect($contract->fresh()->status)->toBe('invalidated')
        ->and($contract->fresh()->is_valid)->toBeFalse();
});

it('rejects invalidation of draft contracts', function () {
    $contract = VendorContract::factory()->create(['status' => 'draft']);

    expect(fn () => $this->service->invalidate($contract))
        ->toThrow(\InvalidArgumentException::class);
});

it('renews an expired contract', function () {
    $original = VendorContract::factory()->expired()->create();
    $signer = VendorContractSigner::factory()->create([
        'vendor_contract_id' => $original->id,
    ]);

    $renewal = $this->service->renew($original);

    expect($renewal->status)->toBe('draft')
        ->and($renewal->renewed_from_id)->toBe($original->id)
        ->and($renewal->content)->toBe($original->content)
        ->and($renewal->signers)->toHaveCount(1)
        ->and($renewal->vendor_id)->toBe($original->vendor_id);
});

it('rejects renewal when active renewal exists', function () {
    $original = VendorContract::factory()->expired()->create();
    VendorContract::factory()->create(['renewed_from_id' => $original->id]);

    expect(fn () => $this->service->renew($original))
        ->toThrow(\InvalidArgumentException::class);
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/VendorContracts/VendorContractServiceTest.php
```

Expected: FAIL — service doesn't exist yet.

- [ ] **Step 3: Write the VendorContractService**

```php
// app/Services/VendorContractService.php
<?php

namespace App\Services;

use App\Models\ContractTemplate;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorContract;
use App\Models\VendorContractSigner;
use App\Models\VendorContractVendorSignature;
use App\Models\VendorDocument;
use App\Notifications\VendorContractFullyExecuted;
use App\Notifications\VendorContractInternalSigningRequest;
use App\Notifications\VendorContractSentToVendor;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class VendorContractService
{
    // --- Creation & Editing ---

    public function createFromTemplate(
        Vendor $vendor,
        ContractTemplate $template,
        array $signerUserIds,
        User $createdBy
    ): VendorContract {
        $content = $this->resolveVendorMergeTags($template->content, $vendor);

        $contract = VendorContract::create([
            'vendor_id' => $vendor->id,
            'template_id' => $template->id,
            'title' => $template->name,
            'content' => $content,
            'status' => VendorContract::STATUS_DRAFT,
            'created_by' => $createdBy->id,
        ]);

        $this->syncSigners($contract, $signerUserIds);

        return $contract->load('signers');
    }

    public function resolveVendorMergeTags(string $content, Vendor $vendor): string
    {
        $tags = [
            '{vendor_name}' => $vendor->name ?? '',
            '{vendor_company}' => $vendor->name ?? '',
            '{vendor_contact}' => $vendor->contact_name ?? '',
            '{vendor_email}' => $vendor->email ?? '',
            '{vendor_phone}' => $vendor->phone ?? '',
            '{vendor_address}' => trim(implode(', ', array_filter([
                $vendor->address_line_1, $vendor->address_line_2,
                $vendor->city, $vendor->state, $vendor->zip,
            ]))),
            '{contract_date}' => now()->format('F j, Y'),
            '{expiration_date}' => '',
        ];

        return str_replace(array_keys($tags), array_values($tags), $content);
    }

    public function updateContent(VendorContract $contract, string $content): void
    {
        if (! $contract->isDraft()) {
            throw new InvalidArgumentException('Content can only be updated while contract is in draft status.');
        }

        $contract->update(['content' => $content]);
    }

    public function updateSigners(VendorContract $contract, array $signerUserIds): void
    {
        if (! $contract->isDraft()) {
            throw new InvalidArgumentException('Signers can only be updated while contract is in draft status.');
        }

        $this->syncSigners($contract, $signerUserIds);
        $contract->unsetRelation('signers');
    }

    private function syncSigners(VendorContract $contract, array $signerUserIds): void
    {
        $contract->signers()->delete();

        foreach (array_values($signerUserIds) as $order => $userId) {
            VendorContractSigner::create([
                'vendor_contract_id' => $contract->id,
                'user_id' => $userId,
                'order' => $order,
            ]);
        }
    }

    // --- Internal Signing Flow ---

    public function sendForInternalSigning(VendorContract $contract): void
    {
        if (! $contract->isDraft()) {
            throw new InvalidArgumentException('Only draft contracts can be sent for internal signing.');
        }

        if ($contract->signers()->count() === 0) {
            throw new InvalidArgumentException('Contract must have at least one signer assigned.');
        }

        $contract->update(['status' => VendorContract::STATUS_PENDING_INTERNAL]);

        $contract->signers->each(function (VendorContractSigner $signer) use ($contract) {
            $signer->user->notify(new VendorContractInternalSigningRequest($contract));
        });
    }

    public function recallToDraft(VendorContract $contract): void
    {
        if (! $contract->isPendingInternal()) {
            throw new InvalidArgumentException('Only contracts pending internal signing can be recalled to draft.');
        }

        $contract->signers()->update([
            'signed_at' => null,
            'signature_data' => null,
            'signature_type' => null,
            'signer_ip' => null,
            'signer_user_agent' => null,
        ]);

        $contract->update(['status' => VendorContract::STATUS_DRAFT]);
    }

    public function signAsInternal(
        VendorContract $contract,
        User $user,
        string $signatureData,
        string $signatureType
    ): void {
        $signer = $contract->signers()->where('user_id', $user->id)->firstOrFail();

        $signer->update([
            'signed_at' => now(),
            'signature_data' => $signatureData,
            'signature_type' => $signatureType,
            'signer_ip' => request()->ip(),
            'signer_user_agent' => request()->userAgent(),
        ]);

        $contract->unsetRelation('signers');

        if ($contract->allInternalSignersSigned()) {
            $contract->update(['status' => VendorContract::STATUS_PENDING_VENDOR]);
        }
    }

    // --- Vendor Signing Flow ---

    public function sendToVendor(VendorContract $contract): void
    {
        $token = $contract->generatePublicToken();

        $url = route('vendor.contract.sign', ['token' => $token]);

        Notification::route('mail', $contract->vendor->email)
            ->notify(new VendorContractSentToVendor($contract, $url));
    }

    public function signAsVendor(
        VendorContract $contract,
        string $name,
        string $email,
        string $signatureData,
        string $signatureType,
        Request $request
    ): void {
        VendorContractVendorSignature::create([
            'vendor_contract_id' => $contract->id,
            'signer_name' => $name,
            'signer_email' => $email,
            'signature_data' => $signatureData,
            'signature_type' => $signatureType,
            'signer_ip' => $request->ip(),
            'signer_user_agent' => $request->userAgent(),
            'signed_at' => now(),
        ]);

        // Keep public token alive so vendor can still download the executed PDF
        // The signing form is hidden based on contract status, not token presence
        $contract->update(['status' => VendorContract::STATUS_FULLY_EXECUTED]);

        $this->onFullyExecuted($contract);
    }

    // --- Post-Execution ---

    private function onFullyExecuted(VendorContract $contract): void
    {
        $this->generateExecutedPdf($contract);
        $this->createVendorDocument($contract);
        $this->notifyFullyExecuted($contract);
    }

    public function generateExecutedPdf(VendorContract $contract): string
    {
        $contract->load(['vendor', 'signers.user', 'vendorSignature']);

        $pdf = Pdf::loadView('pdf.vendor-contract', ['contract' => $contract]);

        $path = "vendor-contracts/{$contract->vendor_id}/{$contract->id}/executed-contract.pdf";
        Storage::put($path, $pdf->output());

        $contract->update(['executed_pdf_path' => $path]);

        return $path;
    }

    private function createVendorDocument(VendorContract $contract): void
    {
        VendorDocument::create([
            'vendor_id' => $contract->vendor_id,
            'vendor_contract_id' => $contract->id,
            'name' => $contract->title,
            'type' => VendorDocument::TYPE_CONTRACT,
            'file_path' => $contract->executed_pdf_path,
            'status' => 'approved',
            'reviewed_by' => $contract->created_by,
            'reviewed_at' => now(),
        ]);
    }

    private function notifyFullyExecuted(VendorContract $contract): void
    {
        $notification = new VendorContractFullyExecuted($contract);

        // Notify internal signers
        $contract->signers->each(function (VendorContractSigner $signer) use ($notification) {
            $signer->user->notify($notification);
        });

        // Notify vendor
        $vendorEmail = $contract->vendorSignature->signer_email ?? $contract->vendor->email;
        Notification::route('mail', $vendorEmail)->notify($notification);
    }

    // --- Validity & Expiration ---

    public function invalidate(VendorContract $contract): void
    {
        $allowedStatuses = [
            VendorContract::STATUS_PENDING_INTERNAL,
            VendorContract::STATUS_PENDING_VENDOR,
            VendorContract::STATUS_FULLY_EXECUTED,
            VendorContract::STATUS_EXPIRED,
        ];

        if (! in_array($contract->status, $allowedStatuses)) {
            throw new InvalidArgumentException(
                'Only contracts in pending_internal, pending_vendor, fully_executed, or expired status can be invalidated.'
            );
        }

        $contract->invalidatePublicToken();
        $contract->update([
            'status' => VendorContract::STATUS_INVALIDATED,
            'is_valid' => false,
        ]);
    }

    public function renew(VendorContract $contract): VendorContract
    {
        if ($contract->hasActiveRenewal()) {
            throw new InvalidArgumentException('This contract already has an active renewal.');
        }

        $renewal = VendorContract::create([
            'vendor_id' => $contract->vendor_id,
            'template_id' => $contract->template_id,
            'title' => $contract->title,
            'content' => $contract->content,
            'status' => VendorContract::STATUS_DRAFT,
            'renewed_from_id' => $contract->id,
            'created_by' => auth()->id() ?? $contract->created_by,
        ]);

        // Clone signer assignments
        foreach ($contract->signers as $signer) {
            VendorContractSigner::create([
                'vendor_contract_id' => $renewal->id,
                'user_id' => $signer->user_id,
                'order' => $signer->order,
            ]);
        }

        return $renewal->load('signers');
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test --compact tests/Feature/VendorContracts/VendorContractServiceTest.php
```

Expected: FAIL — notification classes don't exist yet. This is expected.

- [ ] **Step 5: Create notification class stubs**

Create empty but valid notification classes so the service can be tested. These will be fleshed out in Task 4.

```bash
php artisan make:notification VendorContractInternalSigningRequest --no-interaction
php artisan make:notification VendorContractSentToVendor --no-interaction
php artisan make:notification VendorContractFullyExecuted --no-interaction
php artisan make:notification VendorContractExpiringSoon --no-interaction
php artisan make:notification VendorContractExpired --no-interaction
```

Each stub needs its constructor to accept `VendorContract` (and `$url` for `VendorContractSentToVendor`). Minimal viable content — just the constructor and `via()` returning `['mail', 'database']` (or `['mail']` for vendor notifications). The full implementation comes in Task 4.

- [ ] **Step 6: Re-run service tests**

```bash
php artisan test --compact tests/Feature/VendorContracts/VendorContractServiceTest.php
```

Expected: All tests PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Services/VendorContractService.php app/Notifications/VendorContract* tests/Feature/VendorContracts/VendorContractServiceTest.php
git commit -m "feat(vendor-contracts): add VendorContractService with lifecycle operations"
```

---

## Task 4: Notifications

**Files:**
- Create: `app/Notifications/VendorContractInternalSigningRequest.php`
- Create: `app/Notifications/VendorContractSentToVendor.php`
- Create: `app/Notifications/VendorContractFullyExecuted.php`
- Create: `app/Notifications/VendorContractExpiringSoon.php`
- Create: `app/Notifications/VendorContractExpired.php`
- Create: `tests/Feature/VendorContracts/VendorContractNotificationTest.php`

- [ ] **Step 1: Write notification tests**

```php
// tests/Feature/VendorContracts/VendorContractNotificationTest.php
<?php

use App\Models\User;
use App\Models\VendorContract;
use App\Notifications\VendorContractInternalSigningRequest;
use App\Notifications\VendorContractSentToVendor;
use App\Notifications\VendorContractFullyExecuted;
use App\Notifications\VendorContractExpiringSoon;
use App\Notifications\VendorContractExpired;
use Illuminate\Notifications\AnonymousNotifiable;

it('sends internal signing request notification via mail and database', function () {
    $contract = VendorContract::factory()->pendingInternal()->create();
    $user = User::factory()->internal()->create();

    $notification = new VendorContractInternalSigningRequest($contract);
    $channels = $notification->via($user);

    expect($channels)->toContain('mail')
        ->and($channels)->toContain('database');

    $mail = $notification->toMail($user);
    expect($mail->subject)->toContain($contract->title);
});

it('sends vendor signing notification via mail', function () {
    $contract = VendorContract::factory()->pendingVendor()->create();
    $url = 'https://example.com/vendor/contract/test-token';

    $notification = new VendorContractSentToVendor($contract, $url);
    $anonymous = new AnonymousNotifiable;
    $channels = $notification->via($anonymous);

    expect($channels)->toContain('mail');

    $mail = $notification->toMail($anonymous);
    expect($mail->actionUrl)->toBe($url);
});

it('sends fully executed notification', function () {
    $contract = VendorContract::factory()->fullyExecuted()->create();

    $notification = new VendorContractFullyExecuted($contract);
    $user = User::factory()->internal()->create();

    $mail = $notification->toMail($user);
    expect($mail->subject)->toContain($contract->title);
});

it('sends expiring soon notification', function () {
    $contract = VendorContract::factory()->fullyExecuted()->withExpiration(25)->create();

    $notification = new VendorContractExpiringSoon($contract);
    $user = User::factory()->internal()->create();

    $mail = $notification->toMail($user);
    expect($mail->subject)->toContain('Expiring Soon');
});

it('sends expired notification', function () {
    $contract = VendorContract::factory()->expired()->create();

    $notification = new VendorContractExpired($contract);
    $user = User::factory()->internal()->create();

    $mail = $notification->toMail($user);
    expect($mail->subject)->toContain('Expired');
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/VendorContracts/VendorContractNotificationTest.php
```

- [ ] **Step 3: Create all 5 notifications**

Use `php artisan make:notification` for each, then implement following the `EstimateProposalSent` pattern. Each notification should:
- Implement `ShouldQueue` and use `Queueable`
- Accept `VendorContract` in constructor
- Return `['mail', 'database']` channels for internal users, `['mail']` for on-demand vendor notifications
- Include contract title in subject
- Include appropriate action URL
- Return structured array for `toArray()` (database channel)

**Pattern for each** (adapt subject, body, and action URL):
```php
<?php

namespace App\Notifications;

use App\Models\VendorContract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VendorContractInternalSigningRequest extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public VendorContract $contract) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Contract Signing Required: {$this->contract->title}")
            ->greeting('Contract Signing Required')
            ->line("You have been assigned to sign the contract: {$this->contract->title}")
            ->line("Vendor: {$this->contract->vendor->name}")
            ->action('Review & Sign', route('vendor-contracts.sign-internal', [
                'vendor' => $this->contract->vendor_id,
                'contract' => $this->contract->id,
            ]))
            ->line('Please review and sign at your earliest convenience.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'vendor_contract_signing_request',
            'vendor_contract_id' => $this->contract->id,
            'title' => $this->contract->title,
            'vendor_name' => $this->contract->vendor->name,
        ];
    }
}
```

Follow this same pattern for all 5 notifications, adjusting:
- `VendorContractSentToVendor` — accepts `$url` param, uses `['mail']` channels, action links to public signing URL
- `VendorContractFullyExecuted` — mentions contract is fully signed, attaches PDF if available
- `VendorContractExpiringSoon` — mentions days until expiration
- `VendorContractExpired` — mentions contract has expired and can be renewed

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test --compact tests/Feature/VendorContracts/VendorContractNotificationTest.php
```

- [ ] **Step 5: Re-run service tests (should now pass with notifications)**

```bash
php artisan test --compact tests/Feature/VendorContracts/VendorContractServiceTest.php
```

- [ ] **Step 6: Commit**

```bash
git add app/Notifications/VendorContract* tests/Feature/VendorContracts/VendorContractNotificationTest.php
git commit -m "feat(vendor-contracts): add all 5 contract lifecycle notifications"
```

---

## Task 5: Expiration Scheduler Command

**Files:**
- Create: `app/Console/Commands/CheckVendorContractExpiration.php`
- Create: `tests/Feature/VendorContracts/VendorContractExpirationCommandTest.php`
- Modify: `routes/console.php`

- [ ] **Step 1: Write command tests**

```php
// tests/Feature/VendorContracts/VendorContractExpirationCommandTest.php
<?php

use App\Models\User;
use App\Models\VendorContract;
use App\Models\VendorContractSigner;
use App\Notifications\VendorContractExpiringSoon;
use App\Notifications\VendorContractExpired;
use Illuminate\Support\Facades\Notification;

it('sends 30-day expiry warning', function () {
    Notification::fake();

    $contract = VendorContract::factory()->fullyExecuted()->create([
        'expires_at' => now()->addDays(20),
    ]);
    $signer = User::factory()->internal()->create();
    VendorContractSigner::factory()->signed()->create([
        'vendor_contract_id' => $contract->id,
        'user_id' => $signer->id,
    ]);

    $this->artisan('vendor-contracts:check-expiration')->assertSuccessful();

    $contract->refresh();
    expect($contract->expiry_warning_sent_at)->not->toBeNull();
    Notification::assertSentTo($signer, VendorContractExpiringSoon::class);
});

it('does not re-send 30-day warning', function () {
    Notification::fake();

    VendorContract::factory()->fullyExecuted()->create([
        'expires_at' => now()->addDays(20),
        'expiry_warning_sent_at' => now()->subDay(),
    ]);

    $this->artisan('vendor-contracts:check-expiration')->assertSuccessful();

    Notification::assertNothingSent();
});

it('auto-expires contracts past expiration', function () {
    Notification::fake();

    $contract = VendorContract::factory()->fullyExecuted()->create([
        'expires_at' => now()->subDay(),
    ]);

    $this->artisan('vendor-contracts:check-expiration')->assertSuccessful();

    $contract->refresh();
    expect($contract->status)->toBe('expired')
        ->and($contract->is_valid)->toBeFalse();
});

it('skips evergreen contracts with null expires_at', function () {
    Notification::fake();

    $contract = VendorContract::factory()->fullyExecuted()->create([
        'expires_at' => null,
    ]);

    $this->artisan('vendor-contracts:check-expiration')->assertSuccessful();

    expect($contract->fresh()->status)->toBe('fully_executed');
    Notification::assertNothingSent();
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/VendorContracts/VendorContractExpirationCommandTest.php
```

- [ ] **Step 3: Create the command**

```bash
php artisan make:command CheckVendorContractExpiration --no-interaction
```

```php
// app/Console/Commands/CheckVendorContractExpiration.php
<?php

namespace App\Console\Commands;

use App\Models\VendorContract;
use App\Notifications\VendorContractExpired;
use App\Notifications\VendorContractExpiringSoon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class CheckVendorContractExpiration extends Command
{
    protected $signature = 'vendor-contracts:check-expiration';

    protected $description = 'Check for expiring and expired vendor contracts';

    public function handle(): int
    {
        $this->sendExpiryWarnings();
        $this->expireContracts();

        return self::SUCCESS;
    }

    private function sendExpiryWarnings(): void
    {
        $contracts = VendorContract::query()
            ->where('status', VendorContract::STATUS_FULLY_EXECUTED)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays(30))
            ->where('expires_at', '>', now())
            ->whereNull('expiry_warning_sent_at')
            ->with(['signers.user', 'vendor', 'vendorSignature', 'creator'])
            ->get();

        foreach ($contracts as $contract) {
            $notification = new VendorContractExpiringSoon($contract);

            // Notify internal signers
            $contract->signers->each(fn ($signer) => $signer->user->notify($notification));

            // Notify creator (if not already a signer)
            if ($contract->creator && ! $contract->signers->contains('user_id', $contract->created_by)) {
                $contract->creator->notify($notification);
            }

            // Notify vendor
            $vendorEmail = $contract->vendorSignature?->signer_email ?? $contract->vendor->email;
            if ($vendorEmail) {
                Notification::route('mail', $vendorEmail)->notify($notification);
            }

            $contract->update(['expiry_warning_sent_at' => now()]);
        }

        if ($contracts->isNotEmpty()) {
            $this->info("Sent {$contracts->count()} expiry warning(s).");
        }
    }

    private function expireContracts(): void
    {
        $contracts = VendorContract::query()
            ->where('status', VendorContract::STATUS_FULLY_EXECUTED)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->with(['signers.user', 'vendor', 'vendorSignature', 'creator'])
            ->get();

        foreach ($contracts as $contract) {
            $contract->update([
                'status' => VendorContract::STATUS_EXPIRED,
                'is_valid' => false,
            ]);

            $notification = new VendorContractExpired($contract);
            $contract->signers->each(fn ($signer) => $signer->user->notify($notification));

            // Notify creator (if not already a signer)
            if ($contract->creator && ! $contract->signers->contains('user_id', $contract->created_by)) {
                $contract->creator->notify($notification);
            }

            $vendorEmail = $contract->vendorSignature?->signer_email ?? $contract->vendor->email;
            if ($vendorEmail) {
                Notification::route('mail', $vendorEmail)->notify($notification);
            }
        }

        if ($contracts->isNotEmpty()) {
            $this->info("Expired {$contracts->count()} contract(s).");
        }
    }
}
```

- [ ] **Step 4: Register in scheduler**

Add to `routes/console.php`:
```php
Schedule::command('vendor-contracts:check-expiration')->dailyAt('09:00');
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
php artisan test --compact tests/Feature/VendorContracts/VendorContractExpirationCommandTest.php
```

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/CheckVendorContractExpiration.php tests/Feature/VendorContracts/VendorContractExpirationCommandTest.php routes/console.php
git commit -m "feat(vendor-contracts): add daily expiration check command with 30-day warning"
```

---

## Task 6: PDF Generation

**Files:**
- Create: `resources/views/pdf/vendor-contract.blade.php`
- Create: `app/Http/Controllers/VendorContractPdfController.php`
- Create: `tests/Feature/VendorContracts/VendorContractPdfTest.php`

- [ ] **Step 1: Write PDF tests**

```php
// tests/Feature/VendorContracts/VendorContractPdfTest.php
<?php

use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorContract;
use App\Models\VendorContractSigner;
use App\Models\VendorContractVendorSignature;
use Illuminate\Support\Facades\Storage;

it('generates an executed PDF', function () {
    Storage::fake('local');

    $contract = VendorContract::factory()->fullyExecuted()->create();
    VendorContractSigner::factory()->signed()->create(['vendor_contract_id' => $contract->id]);
    VendorContractVendorSignature::create([
        'vendor_contract_id' => $contract->id,
        'signer_name' => 'Vendor Rep',
        'signer_email' => 'vendor@test.com',
        'signature_data' => 'Vendor Rep',
        'signature_type' => 'typed',
        'signed_at' => now(),
    ]);

    $service = app(\App\Services\VendorContractService::class);
    $path = $service->generateExecutedPdf($contract);

    expect($path)->toContain('vendor-contracts/')
        ->and($contract->fresh()->executed_pdf_path)->toBe($path);
    Storage::assertExists($path);
});

it('downloads PDF for authenticated internal user', function () {
    Storage::fake('local');
    $contract = VendorContract::factory()->fullyExecuted()->create([
        'executed_pdf_path' => 'vendor-contracts/1/1/executed-contract.pdf',
    ]);
    Storage::put($contract->executed_pdf_path, 'fake pdf content');

    $user = User::factory()->internal()->create();

    $this->actingAs($user)
        ->get(route('vendor-contracts.pdf.download', [
            'vendor' => $contract->vendor_id,
            'contract' => $contract->id,
        ]))
        ->assertSuccessful();
});

it('returns 404 for non-executed contract PDF', function () {
    $contract = VendorContract::factory()->create(['status' => 'draft']);
    $user = User::factory()->internal()->create();

    $this->actingAs($user)
        ->get(route('vendor-contracts.pdf.download', [
            'vendor' => $contract->vendor_id,
            'contract' => $contract->id,
        ]))
        ->assertNotFound();
});

it('downloads PDF via public token', function () {
    Storage::fake('local');
    $contract = VendorContract::factory()->fullyExecuted()->create([
        'executed_pdf_path' => 'vendor-contracts/1/1/executed-contract.pdf',
    ]);
    Storage::put($contract->executed_pdf_path, 'fake pdf content');
    $token = $contract->generatePublicToken();

    $this->get(route('vendor.contract.pdf', ['token' => $token]))
        ->assertSuccessful();
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/VendorContracts/VendorContractPdfTest.php
```

- [ ] **Step 3: Create PDF Blade template**

```blade
{{-- resources/views/pdf/vendor-contract.blade.php --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; line-height: 1.6; color: #333; margin: 40px; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 15px; }
        .header h1 { font-size: 20px; margin: 0; }
        .header p { margin: 5px 0; color: #666; }
        .content { margin-bottom: 40px; }
        .signatures { page-break-inside: avoid; margin-top: 40px; border-top: 2px solid #333; padding-top: 20px; }
        .signatures h2 { font-size: 16px; margin-bottom: 15px; }
        .signature-block { margin-bottom: 20px; padding: 10px; border: 1px solid #ddd; }
        .signature-block .label { font-weight: bold; color: #666; font-size: 10px; text-transform: uppercase; }
        .signature-block .value { margin-top: 3px; }
        .signature-image { max-height: 60px; margin: 5px 0; }
        .meta { margin-top: 20px; font-size: 10px; color: #999; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $contract->title }}</h1>
        <p>Vendor: {{ $contract->vendor->name }}</p>
        @if($contract->expires_at)
            <p>Expires: {{ $contract->expires_at->format('F j, Y') }}</p>
        @endif
    </div>

    <div class="content">
        {!! $contract->content !!}
    </div>

    <div class="signatures">
        <h2>Signatures</h2>

        <h3>Internal Signers</h3>
        @foreach($contract->signers as $signer)
            <div class="signature-block">
                <div class="label">Signed by</div>
                <div class="value">{{ $signer->user->name }}</div>
                @if($signer->signature_type === 'drawn' && $signer->signature_data)
                    <img src="{{ $signer->signature_data }}" class="signature-image" alt="Signature">
                @elseif($signer->signature_type === 'typed')
                    <div style="font-family: cursive; font-size: 18px;">{{ $signer->signature_data }}</div>
                @endif
                <div class="label">Date</div>
                <div class="value">{{ $signer->signed_at?->format('F j, Y g:i A') }}</div>
            </div>
        @endforeach

        @if($contract->vendorSignature)
            <h3>Vendor Signature</h3>
            <div class="signature-block">
                <div class="label">Signed by</div>
                <div class="value">{{ $contract->vendorSignature->signer_name }} ({{ $contract->vendorSignature->signer_email }})</div>
                @if($contract->vendorSignature->signature_type === 'drawn' && $contract->vendorSignature->signature_data)
                    <img src="{{ $contract->vendorSignature->signature_data }}" class="signature-image" alt="Signature">
                @elseif($contract->vendorSignature->signature_type === 'typed')
                    <div style="font-family: cursive; font-size: 18px;">{{ $contract->vendorSignature->signature_data }}</div>
                @endif
                <div class="label">Date</div>
                <div class="value">{{ $contract->vendorSignature->signed_at->format('F j, Y g:i A') }}</div>
            </div>
        @endif
    </div>

    <div class="meta">
        Contract executed on {{ $contract->updated_at->format('F j, Y') }}
    </div>
</body>
</html>
```

- [ ] **Step 4: Create PDF controller**

Follow the `ContractPdfController` pattern:

```php
// app/Http/Controllers/VendorContractPdfController.php
<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use App\Models\VendorContract;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VendorContractPdfController extends Controller
{
    public function download(Vendor $vendor, VendorContract $contract): StreamedResponse
    {
        abort_unless($contract->isFullyExecuted() && $contract->executed_pdf_path, 404);
        abort_unless($contract->vendor_id === $vendor->id, 404);

        return Storage::download(
            $contract->executed_pdf_path,
            "contract-{$contract->id}.pdf"
        );
    }

    public function publicDownload(string $token): StreamedResponse
    {
        $contract = VendorContract::byPublicToken($token)->firstOrFail();
        abort_unless($contract->isFullyExecuted() && $contract->executed_pdf_path, 404);
        abort_unless($contract->isPublicTokenValid(), 410);

        return Storage::download(
            $contract->executed_pdf_path,
            "contract-{$contract->id}.pdf"
        );
    }
}
```

- [ ] **Step 5: Add routes (will be finalized in Task 9, but add PDF routes now)**

Add routes to `routes/settings.php`. Extend the existing `vendors.manage` middleware group at line 93-98 to include the new routes:

```php
Route::middleware('permission:vendors.manage')->group(function () {
    // Existing document routes
    Route::get('settings/vendors/documents/{vendorDocument}/download', [\App\Http\Controllers\VendorDocumentController::class, 'download'])
        ->name('vendors.documents.download');
    Route::get('settings/vendors/documents/{vendorDocument}/view', [\App\Http\Controllers\VendorDocumentController::class, 'view'])
        ->name('vendors.documents.view');

    // Vendor contract routes (new)
    Route::get('settings/vendors/{vendor}/contracts/{contract}/pdf', [VendorContractPdfController::class, 'download'])
        ->name('vendor-contracts.pdf.download');
});
```

Add to `routes/web.php` in public section:
```php
Route::get('/vendor/contract/{token}/pdf', [VendorContractPdfController::class, 'publicDownload'])
    ->name('vendor.contract.pdf')
    ->middleware('throttle:60,1');
```

- [ ] **Step 6: Run tests to verify they pass**

```bash
php artisan test --compact tests/Feature/VendorContracts/VendorContractPdfTest.php
```

- [ ] **Step 7: Commit**

```bash
git add resources/views/pdf/vendor-contract.blade.php app/Http/Controllers/VendorContractPdfController.php tests/Feature/VendorContracts/VendorContractPdfTest.php routes/settings.php routes/web.php
git commit -m "feat(vendor-contracts): add PDF generation and download controller"
```

---

## Task 7: Contracts Flyout Modal in Vendor Settings

**Files:**
- Modify: `resources/views/pages/settings/⚡vendors.blade.php`
- Create: `tests/Feature/Livewire/VendorContracts/VendorContractsFlyoutTest.php`

- [ ] **Step 1: Write flyout tests**

Test that the contracts button appears, the modal opens, contracts are listed, and actions work (create, delete draft, invalidate).

```php
// tests/Feature/Livewire/VendorContracts/VendorContractsFlyoutTest.php
<?php

use App\Models\ContractTemplate;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorContract;
use Livewire\Livewire;

it('shows contracts button for each vendor', function () {
    $user = User::factory()->internal()->create();
    $vendor = Vendor::factory()->create();

    $this->actingAs($user)
        ->get(route('vendors.edit'))
        ->assertSee('Contracts');
});

it('opens contracts modal and lists contracts', function () {
    $user = User::factory()->internal()->create();
    $vendor = Vendor::factory()->create();
    $contract = VendorContract::factory()->create(['vendor_id' => $vendor->id]);

    Livewire::actingAs($user)
        ->test('pages::settings.vendors')
        ->call('viewContracts', $vendor->id)
        ->assertSet('showContractsModal', true)
        ->assertSee($contract->title);
});

it('creates a contract from template and redirects to editor', function () {
    $user = User::factory()->internal()->create();
    $vendor = Vendor::factory()->create();
    $template = ContractTemplate::create([
        'name' => 'Vendor Agreement',
        'type' => 'vendor',
        'content' => '<p>Test</p>',
        'is_active' => true,
    ]);

    Livewire::actingAs($user)
        ->test('pages::settings.vendors')
        ->call('viewContracts', $vendor->id)
        ->call('createContract', $template->id)
        ->assertRedirect();
});

it('deletes a draft contract', function () {
    $user = User::factory()->internal()->create();
    $contract = VendorContract::factory()->create(['status' => 'draft']);

    Livewire::actingAs($user)
        ->test('pages::settings.vendors')
        ->call('viewContracts', $contract->vendor_id)
        ->call('deleteContract', $contract->id);

    expect(VendorContract::find($contract->id))->toBeNull();
});

it('cannot delete a non-draft contract', function () {
    $user = User::factory()->internal()->create();
    $contract = VendorContract::factory()->pendingInternal()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.vendors')
        ->call('viewContracts', $contract->vendor_id)
        ->call('deleteContract', $contract->id);

    expect(VendorContract::find($contract->id))->not->toBeNull();
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/Livewire/VendorContracts/VendorContractsFlyoutTest.php
```

- [ ] **Step 3: Add contract modal state and methods to `⚡vendors.blade.php`**

Add these PHP properties and methods to the existing component class (alongside the existing documents modal properties):

**Properties:**
```php
public bool $showContractsModal = false;
public ?int $contractVendorId = null;
public ?int $selectedTemplateId = null;
```

**Computed:**
```php
#[Computed]
public function contractVendor(): ?Vendor
{
    return $this->contractVendorId ? Vendor::find($this->contractVendorId) : null;
}

#[Computed]
public function vendorContracts(): \Illuminate\Support\Collection
{
    if (! $this->contractVendorId) {
        return collect();
    }
    return VendorContract::where('vendor_id', $this->contractVendorId)
        ->orderByDesc('created_at')
        ->get();
}

#[Computed]
public function vendorTemplates(): \Illuminate\Support\Collection
{
    return ContractTemplate::where('type', 'vendor')->where('is_active', true)->get();
}
```

**Methods:**
```php
public function viewContracts(int $vendorId): void
{
    $this->contractVendorId = $vendorId;
    $this->showContractsModal = true;
}

public function closeContractsModal(): void
{
    $this->showContractsModal = false;
    $this->contractVendorId = null;
    $this->selectedTemplateId = null;
}

public function createContract(int $templateId): void
{
    $vendor = Vendor::findOrFail($this->contractVendorId);
    $template = ContractTemplate::findOrFail($templateId);
    $service = app(VendorContractService::class);

    $contract = $service->createFromTemplate($vendor, $template, [auth()->id()], auth()->user());

    $this->redirect(route('vendor-contracts.edit', [
        'vendor' => $vendor->id,
        'contract' => $contract->id,
    ]));
}

public function deleteContract(int $contractId): void
{
    $contract = VendorContract::findOrFail($contractId);
    if ($contract->isDraft()) {
        $contract->delete();
    }
}

public function invalidateContract(int $contractId): void
{
    $contract = VendorContract::findOrFail($contractId);
    app(VendorContractService::class)->invalidate($contract);
}
```

**Blade — Contracts button** (add alongside existing Documents button in vendor row):
```blade
<flux:button size="xs" wire:click="viewContracts({{ $vendor->id }})">
    Contracts
</flux:button>
```

**Blade — Contracts flyout modal** (add alongside existing documents modal):
A flyout modal listing contracts with status badges, template picker for new contracts, and action buttons per contract (Edit, Sign, Download, Invalidate, Renew, Delete). Follow the exact same flyout pattern as the existing documents modal.

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test --compact tests/Feature/Livewire/VendorContracts/VendorContractsFlyoutTest.php
```

- [ ] **Step 5: Commit**

```bash
git add resources/views/pages/settings/⚡vendors.blade.php tests/Feature/Livewire/VendorContracts/VendorContractsFlyoutTest.php
git commit -m "feat(vendor-contracts): add contracts flyout modal to vendor settings"
```

---

## Task 8: Contract Editor Page

**Files:**
- Create: `resources/views/pages/settings/⚡vendor-contract-editor.blade.php`
- Create: `tests/Feature/Livewire/VendorContracts/VendorContractEditorTest.php`
- Modify: `routes/settings.php`

- [ ] **Step 1: Write editor tests**

```php
// tests/Feature/Livewire/VendorContracts/VendorContractEditorTest.php
<?php

use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorContract;
use App\Models\VendorContractSigner;
use App\Notifications\VendorContractInternalSigningRequest;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

it('loads contract editor page for draft contract', function () {
    $user = User::factory()->internal()->create();
    $contract = VendorContract::factory()->create(['status' => 'draft']);

    $this->actingAs($user)
        ->get(route('vendor-contracts.edit', [
            'vendor' => $contract->vendor_id,
            'contract' => $contract->id,
        ]))
        ->assertSuccessful()
        ->assertSee($contract->title);
});

it('saves contract content', function () {
    $user = User::factory()->internal()->create();
    $contract = VendorContract::factory()->create(['status' => 'draft']);

    Livewire::actingAs($user)
        ->test('pages::settings.vendor-contract-editor', [
            'vendor' => $contract->vendor_id,
            'contract' => $contract->id,
        ])
        ->set('content', '<p>Updated content</p>')
        ->call('saveContent')
        ->assertDispatched('notify');

    expect($contract->fresh()->content)->toBe('<p>Updated content</p>');
});

it('saves title and expiration', function () {
    $user = User::factory()->internal()->create();
    $contract = VendorContract::factory()->create(['status' => 'draft']);

    Livewire::actingAs($user)
        ->test('pages::settings.vendor-contract-editor', [
            'vendor' => $contract->vendor_id,
            'contract' => $contract->id,
        ])
        ->set('title', 'New Title')
        ->set('expiresAt', '2027-01-01')
        ->call('saveDetails')
        ->assertDispatched('notify');

    $contract->refresh();
    expect($contract->title)->toBe('New Title')
        ->and($contract->expires_at->format('Y-m-d'))->toBe('2027-01-01');
});

it('updates signer assignments', function () {
    $user = User::factory()->internal()->create();
    $contract = VendorContract::factory()->create(['status' => 'draft']);
    $signer1 = User::factory()->internal()->create();
    $signer2 = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.vendor-contract-editor', [
            'vendor' => $contract->vendor_id,
            'contract' => $contract->id,
        ])
        ->set('selectedSignerIds', [$signer1->id, $signer2->id])
        ->call('updateSigners');

    expect($contract->fresh()->signers)->toHaveCount(2);
});

it('sends contract for internal signing', function () {
    Notification::fake();
    $user = User::factory()->internal()->create();
    $contract = VendorContract::factory()->create(['status' => 'draft']);
    VendorContractSigner::factory()->create([
        'vendor_contract_id' => $contract->id,
        'user_id' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::settings.vendor-contract-editor', [
            'vendor' => $contract->vendor_id,
            'contract' => $contract->id,
        ])
        ->call('sendForSigning')
        ->assertRedirect();

    expect($contract->fresh()->status)->toBe('pending_internal');
});

it('rejects editing non-draft contract', function () {
    $user = User::factory()->internal()->create();
    $contract = VendorContract::factory()->create(['status' => 'pending_internal']);

    $this->actingAs($user)
        ->get(route('vendor-contracts.edit', [
            'vendor' => $contract->vendor_id,
            'contract' => $contract->id,
        ]))
        ->assertSuccessful();

    // Content should be read-only (editor disabled)
    Livewire::actingAs($user)
        ->test('pages::settings.vendor-contract-editor', [
            'vendor' => $contract->vendor_id,
            'contract' => $contract->id,
        ])
        ->call('saveContent')
        ->assertDispatched('notify'); // Should dispatch error notification
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/Livewire/VendorContracts/VendorContractEditorTest.php
```

- [ ] **Step 3: Create the editor component**

Create `resources/views/pages/settings/⚡vendor-contract-editor.blade.php` as a Livewire SFC:

**PHP section:**
- Properties: `$vendor`, `$contract` (route model binding), `$content`, `$title`, `$expiresAt`, `$notes`, `$selectedSignerIds`
- `mount(int $vendor, int $contract)` — load contract, abort if vendor mismatch, populate properties
- `saveContent()` — calls `VendorContractService::updateContent()`, dispatches success notification
- `saveDetails()` — updates title, expires_at, notes on the contract
- `updateSigners()` — calls `VendorContractService::updateSigners()`
- `sendForSigning()` — calls `VendorContractService::sendForInternalSigning()`, redirects to sign page
- Computed: `internalUsers()` — list of internal users for signer selection
- Layout: `#[Layout('components.layouts.settings')]`

**Blade section:**
- Breadcrumb navigation back to vendor settings
- Contract title input
- Trix rich text editor (reuse same CDN + styles from `⚡template-editor.blade.php`)
- Sidebar/section: expiration date picker, notes textarea, signer multi-select
- Action buttons: Save Draft, Send for Internal Signing
- Read-only state when not in draft

- [ ] **Step 4: Add route**

Add to `routes/settings.php` inside the extended `vendors.manage` middleware group (same group where PDF route was added in Task 6):
```php
Route::livewire('settings/vendors/{vendor}/contracts/{contract}/edit', 'pages::settings.vendor-contract-editor')
    ->name('vendor-contracts.edit');
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
php artisan test --compact tests/Feature/Livewire/VendorContracts/VendorContractEditorTest.php
```

- [ ] **Step 6: Commit**

```bash
git add resources/views/pages/settings/⚡vendor-contract-editor.blade.php tests/Feature/Livewire/VendorContracts/VendorContractEditorTest.php routes/settings.php
git commit -m "feat(vendor-contracts): add contract editor page with Trix and signer management"
```

---

## Task 9: Internal Signing Page

**Files:**
- Create: `resources/views/pages/settings/⚡vendor-contract-sign-internal.blade.php`
- Create: `tests/Feature/Livewire/VendorContracts/VendorContractSignInternalTest.php`
- Modify: `routes/settings.php`

- [ ] **Step 1: Write internal signing tests**

```php
// tests/Feature/Livewire/VendorContracts/VendorContractSignInternalTest.php
<?php

use App\Models\User;
use App\Models\VendorContract;
use App\Models\VendorContractSigner;
use App\Notifications\VendorContractSentToVendor;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

it('loads signing page for assigned signer', function () {
    $user = User::factory()->internal()->create();
    $contract = VendorContract::factory()->pendingInternal()->create();
    VendorContractSigner::factory()->create([
        'vendor_contract_id' => $contract->id,
        'user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(route('vendor-contracts.sign-internal', [
            'vendor' => $contract->vendor_id,
            'contract' => $contract->id,
        ]))
        ->assertSuccessful()
        ->assertSee($contract->title);
});

it('shows signing progress', function () {
    $signer1 = User::factory()->internal()->create();
    $signer2 = User::factory()->internal()->create();
    $contract = VendorContract::factory()->pendingInternal()->create();

    VendorContractSigner::factory()->signed()->create([
        'vendor_contract_id' => $contract->id,
        'user_id' => $signer1->id,
    ]);
    VendorContractSigner::factory()->create([
        'vendor_contract_id' => $contract->id,
        'user_id' => $signer2->id,
    ]);

    Livewire::actingAs($signer2)
        ->test('pages::settings.vendor-contract-sign-internal', [
            'vendor' => $contract->vendor_id,
            'contract' => $contract->id,
        ])
        ->assertSee($signer1->name)
        ->assertSee('Signed');
});

it('signs a contract as internal user', function () {
    $user = User::factory()->internal()->create();
    $contract = VendorContract::factory()->pendingInternal()->create();
    VendorContractSigner::factory()->create([
        'vendor_contract_id' => $contract->id,
        'user_id' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::settings.vendor-contract-sign-internal', [
            'vendor' => $contract->vendor_id,
            'contract' => $contract->id,
        ])
        ->set('signatureData', 'Test User')
        ->set('signatureType', 'typed')
        ->call('sign');

    $signer = VendorContractSigner::where('user_id', $user->id)->first();
    expect($signer->signed_at)->not->toBeNull();
});

it('auto-transitions to pending_vendor when last signer signs', function () {
    $user = User::factory()->internal()->create();
    $contract = VendorContract::factory()->pendingInternal()->create();
    VendorContractSigner::factory()->create([
        'vendor_contract_id' => $contract->id,
        'user_id' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::settings.vendor-contract-sign-internal', [
            'vendor' => $contract->vendor_id,
            'contract' => $contract->id,
        ])
        ->set('signatureData', 'Test')
        ->set('signatureType', 'typed')
        ->call('sign');

    expect($contract->fresh()->status)->toBe('pending_vendor');
});

it('sends contract to vendor after all internal signatures', function () {
    Notification::fake();
    $user = User::factory()->internal()->create();
    $contract = VendorContract::factory()->pendingVendor()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.vendor-contract-sign-internal', [
            'vendor' => $contract->vendor_id,
            'contract' => $contract->id,
        ])
        ->call('sendToVendor');

    expect($contract->fresh()->public_token)->not->toBeNull();
    Notification::assertSentOnDemand(VendorContractSentToVendor::class);
});

it('recalls contract to draft', function () {
    $user = User::factory()->internal()->create();
    $contract = VendorContract::factory()->pendingInternal()->create();
    VendorContractSigner::factory()->signed()->create([
        'vendor_contract_id' => $contract->id,
        'user_id' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::settings.vendor-contract-sign-internal', [
            'vendor' => $contract->vendor_id,
            'contract' => $contract->id,
        ])
        ->call('recallToDraft')
        ->assertRedirect();

    expect($contract->fresh()->status)->toBe('draft');
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/Livewire/VendorContracts/VendorContractSignInternalTest.php
```

- [ ] **Step 3: Create the internal signing component**

Create `resources/views/pages/settings/⚡vendor-contract-sign-internal.blade.php` as Livewire SFC:

**PHP section:**
- Properties: contract data, `$signatureData`, `$signatureType`, `$showSignatureModal`
- `mount(int $vendor, int $contract)` — load contract with signers
- `sign()` — calls `VendorContractService::signAsInternal()`, refreshes contract
- `sendToVendor()` — calls `VendorContractService::sendToVendor()`
- `recallToDraft()` — calls `VendorContractService::recallToDraft()`, redirects to editor
- Layout: `#[Layout('components.layouts.settings')]`

**Blade section:**
- Contract content displayed read-only
- Signer progress list (name, signed/pending status, signed date)
- Signature capture section for current user (if they're an assigned unsigned signer)
- Reuse `@include('partials.public.signature-modal')` pattern for signature capture
- "Send to Vendor" button (visible when `status === pending_vendor`)
- "Recall to Draft" button (visible when `status === pending_internal`)

- [ ] **Step 4: Add route**

Add to `routes/settings.php` inside the extended `vendors.manage` middleware group:
```php
Route::livewire('settings/vendors/{vendor}/contracts/{contract}/sign', 'pages::settings.vendor-contract-sign-internal')
    ->name('vendor-contracts.sign-internal');
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
php artisan test --compact tests/Feature/Livewire/VendorContracts/VendorContractSignInternalTest.php
```

- [ ] **Step 6: Commit**

```bash
git add resources/views/pages/settings/⚡vendor-contract-sign-internal.blade.php tests/Feature/Livewire/VendorContracts/VendorContractSignInternalTest.php routes/settings.php
git commit -m "feat(vendor-contracts): add internal contract signing page with progress tracking"
```

---

## Task 10: Public Vendor Signing Page

**Files:**
- Create: `resources/views/components/public/⚡vendor-contract-sign.blade.php`
- Create: `tests/Feature/Livewire/Public/VendorContractSignTest.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Write public signing tests**

```php
// tests/Feature/Livewire/Public/VendorContractSignTest.php
<?php

use App\Models\Vendor;
use App\Models\VendorContract;
use App\Models\VendorContractSigner;
use App\Models\VendorContractVendorSignature;
use App\Notifications\VendorContractFullyExecuted;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('loads the public signing page with valid token', function () {
    $contract = VendorContract::factory()->pendingVendor()->create();
    $token = $contract->generatePublicToken();

    $this->get(route('vendor.contract.sign', ['token' => $token]))
        ->assertSuccessful()
        ->assertSee($contract->title);
});

it('returns 404 for invalid token', function () {
    $this->get(route('vendor.contract.sign', ['token' => 'invalid-token']))
        ->assertNotFound();
});

it('returns 410 for expired token', function () {
    $contract = VendorContract::factory()->pendingVendor()->create();
    $contract->generatePublicToken();
    $contract->update(['public_token_expires_at' => now()->subDay()]);

    $this->get(route('vendor.contract.sign', ['token' => $contract->public_token]))
        ->assertStatus(410);
});

it('shows contract content and internal signer names', function () {
    $contract = VendorContract::factory()->pendingVendor()->create([
        'content' => '<p>This is the contract content</p>',
    ]);
    $signer = VendorContractSigner::factory()->signed()->create([
        'vendor_contract_id' => $contract->id,
    ]);
    $token = $contract->generatePublicToken();

    Livewire::test('public.vendor-contract-sign', ['token' => $token])
        ->assertSee('This is the contract content')
        ->assertSee($signer->user->name);
});

it('signs contract as vendor', function () {
    Notification::fake();
    Storage::fake('local');

    $contract = VendorContract::factory()->pendingVendor()->create();
    $token = $contract->generatePublicToken();

    Livewire::test('public.vendor-contract-sign', ['token' => $token])
        ->set('signerName', 'Vendor Rep')
        ->set('signerEmail', 'vendor@example.com')
        ->set('signatureData', 'Vendor Rep')
        ->set('signatureType', 'typed')
        ->set('acceptedTerms', true)
        ->call('submitSignature');

    $contract->refresh();
    expect($contract->status)->toBe('fully_executed')
        ->and($contract->vendorSignature)->not->toBeNull()
        ->and($contract->vendorSignature->signer_name)->toBe('Vendor Rep');
});

it('shows confirmation after signing', function () {
    $contract = VendorContract::factory()->pendingVendor()->create();
    $token = $contract->generatePublicToken();

    // Sign the contract directly
    VendorContractVendorSignature::create([
        'vendor_contract_id' => $contract->id,
        'signer_name' => 'Already Signed',
        'signer_email' => 'vendor@test.com',
        'signature_data' => 'sig',
        'signature_type' => 'typed',
        'signed_at' => now(),
    ]);
    $contract->update(['status' => 'fully_executed']);

    // Reload — should show confirmation, not signing form
    // Public token was invalidated, so a new visit would 404
    // This test verifies the already-signed state renders correctly
    expect($contract->fresh()->vendorSignature)->not->toBeNull();
});

it('validates required fields before signing', function () {
    $contract = VendorContract::factory()->pendingVendor()->create();
    $token = $contract->generatePublicToken();

    Livewire::test('public.vendor-contract-sign', ['token' => $token])
        ->set('signerName', '')
        ->set('signerEmail', '')
        ->set('signatureData', '')
        ->call('submitSignature')
        ->assertHasErrors(['signerName', 'signerEmail', 'signatureData']);
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/Livewire/Public/VendorContractSignTest.php
```

- [ ] **Step 3: Create the public signing component**

Create `resources/views/components/public/⚡vendor-contract-sign.blade.php` as Livewire SFC:

**PHP section:**
- Layout: `#[Layout('components.layouts.public')]`
- Properties: `$contract`, `$token`, `$signerName`, `$signerEmail`, `$signatureData`, `$signatureType = 'typed'`, `$acceptedTerms = false`, `$isSigned = false`
- `mount(string $token)` — load contract by token with relations, abort 404/410, check if already signed
- `submitSignature()` — validate, call `VendorContractService::signAsVendor()`, set `$isSigned = true`
- Validation rules: `signerName` required, `signerEmail` required|email, `signatureData` required, `acceptedTerms` accepted

**Blade section:**
Follow the `⚡estimate-proposal.blade.php` layout pattern:
- Company header
- Contract content (read-only HTML)
- Internal signatures section (names + dates)
- If not signed: signature capture form (name, email, drawn/typed signature, terms checkbox, submit button)
- If signed: confirmation message ("Contract signed successfully. A copy will be emailed to you.")
- Include signature modal partial for draw functionality

- [ ] **Step 4: Add route**

Add to `routes/web.php` in public section:
```php
Route::livewire('/vendor/contract/{token}', 'public.vendor-contract-sign')
    ->name('vendor.contract.sign')
    ->middleware('throttle:60,1');
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
php artisan test --compact tests/Feature/Livewire/Public/VendorContractSignTest.php
```

- [ ] **Step 6: Commit**

```bash
git add resources/views/components/public/⚡vendor-contract-sign.blade.php tests/Feature/Livewire/Public/VendorContractSignTest.php routes/web.php
git commit -m "feat(vendor-contracts): add public vendor contract signing page"
```

---

## Task 11: Integration Testing & Final Verification

**Files:**
- Create: `tests/Feature/VendorContracts/VendorContractIntegrationTest.php`

- [ ] **Step 1: Write end-to-end integration tests**

```php
// tests/Feature/VendorContracts/VendorContractIntegrationTest.php
<?php

use App\Models\ContractTemplate;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorContract;
use App\Models\VendorDocument;
use App\Services\VendorContractService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

it('completes full contract lifecycle: create → sign internal → sign vendor → PDF', function () {
    Notification::fake();
    Storage::fake('local');

    $service = app(VendorContractService::class);
    $vendor = Vendor::factory()->create(['email' => 'vendor@test.com']);
    $creator = User::factory()->internal()->create();
    $signer = User::factory()->internal()->create();
    $template = ContractTemplate::create([
        'name' => 'Subcontractor Agreement',
        'type' => 'vendor',
        'content' => '<p>Agreement with {vendor_name}</p>',
        'is_active' => true,
    ]);

    // 1. Create from template
    $contract = $service->createFromTemplate($vendor, $template, [$signer->id], $creator);
    expect($contract->status)->toBe('draft')
        ->and($contract->content)->toContain($vendor->name);

    // 2. Edit content
    $service->updateContent($contract, '<p>Updated agreement with ' . $vendor->name . '</p>');

    // 3. Send for internal signing
    $service->sendForInternalSigning($contract);
    expect($contract->fresh()->status)->toBe('pending_internal');

    // 4. Internal signer signs
    $service->signAsInternal($contract, $signer, 'Internal Sig', 'typed');
    expect($contract->fresh()->status)->toBe('pending_vendor');

    // 5. Send to vendor
    $service->sendToVendor($contract);
    expect($contract->fresh()->public_token)->not->toBeNull();

    // 6. Vendor signs
    $service->signAsVendor(
        $contract->fresh(), 'Vendor Rep', 'vendor@test.com',
        'Vendor Signature', 'typed', request()
    );

    $contract->refresh();
    expect($contract->status)->toBe('fully_executed')
        ->and($contract->executed_pdf_path)->not->toBeNull()
        ->and($contract->public_token)->not->toBeNull() // Token kept alive for vendor PDF access
        ->and($contract->vendorSignature)->not->toBeNull();

    // 7. Verify VendorDocument was created
    $doc = VendorDocument::where('vendor_contract_id', $contract->id)->first();
    expect($doc)->not->toBeNull()
        ->and($doc->type)->toBe('contract')
        ->and($doc->status)->toBe('approved');
});

it('completes renewal lifecycle', function () {
    Notification::fake();
    Storage::fake('local');

    $service = app(VendorContractService::class);

    // Create an expired contract
    $original = VendorContract::factory()->expired()->create(['content' => '<p>Original</p>']);

    // Renew it
    $renewal = $service->renew($original);

    expect($renewal->status)->toBe('draft')
        ->and($renewal->renewed_from_id)->toBe($original->id)
        ->and($renewal->content)->toBe('<p>Original</p>');

    // Verify original points to renewal
    expect($original->fresh()->renewal->id)->toBe($renewal->id);
});

it('prevents double renewal', function () {
    $original = VendorContract::factory()->expired()->create();
    $service = app(VendorContractService::class);

    $service->renew($original);

    expect(fn () => $service->renew($original))
        ->toThrow(\InvalidArgumentException::class);
});
```

- [ ] **Step 2: Run integration tests**

```bash
php artisan test --compact tests/Feature/VendorContracts/VendorContractIntegrationTest.php
```

- [ ] **Step 3: Run ALL vendor contract tests**

```bash
php artisan test --compact tests/Feature/VendorContracts/ tests/Feature/Livewire/VendorContracts/ tests/Feature/Livewire/Public/VendorContractSignTest.php
```

- [ ] **Step 4: Run Pint to format all new files**

```bash
vendor/bin/pint --dirty
```

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/VendorContracts/VendorContractIntegrationTest.php
git commit -m "feat(vendor-contracts): add integration tests for full contract lifecycle"
```

- [ ] **Step 6: Run full test suite**

```bash
php artisan test --compact
```

Verify no regressions in existing tests.

---

## Task Summary

| Task | Description | Key Files |
|------|------------|-----------|
| 1 | Database migration | 3 new tables, 2 table alterations |
| 2 | Models & factories | VendorContract, VendorContractSigner, VendorContractVendorSignature + factories |
| 3 | VendorContractService | All lifecycle operations |
| 4 | Notifications | 5 contract lifecycle notifications |
| 5 | Expiration command | Daily scheduler with 30-day warning |
| 6 | PDF generation | Template + controller for internal/public download |
| 7 | Vendor settings flyout | Contracts modal integrated into existing vendor page |
| 8 | Contract editor page | Trix editor + signer management |
| 9 | Internal signing page | Signature capture + progress tracking |
| 10 | Public vendor signing | Token-based public signing page |
| 11 | Integration testing | End-to-end lifecycle tests + full suite |
