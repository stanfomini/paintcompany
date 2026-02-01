# Phase 2: Core CRM Implementation Plan

## Overview
Build the core CRM functionality: Customers, Properties, Deals with Kanban pipeline, Estimates, and Activities.

---

## Task 2.1: Customer Management

### Database Migration
```php
Schema::create('customers', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // If customer has portal access
    $table->foreignId('created_by')->constrained('users'); // Internal user who created
    $table->foreignId('referred_by')->nullable()->constrained('users'); // Agent who referred
    $table->string('first_name');
    $table->string('last_name');
    $table->string('email')->nullable();
    $table->string('phone')->nullable();
    $table->text('address')->nullable();
    $table->string('city')->nullable();
    $table->string('state')->nullable();
    $table->string('zip')->nullable();
    $table->text('notes')->nullable();
    $table->enum('status', ['active', 'inactive', 'archived'])->default('active');
    $table->timestamps();
    $table->softDeletes();

    $table->index(['last_name', 'first_name']);
    $table->index('email');
    $table->index('phone');
});
```

### Model Features
- Full name accessor
- Relationships: user, createdBy, referredBy, properties, deals
- Scopes: active, withAgent, search
- Factory with states

### Livewire Components
- `Customers\Index` - List with search, filters, pagination
- `Customers\Create` - Create form modal
- `Customers\Edit` - Edit form modal
- `Customers\Show` - Detail view with related data

### Routes
- `GET /customers` - List
- `GET /customers/{customer}` - Show
- `GET /customers/create` - Create form
- `GET /customers/{customer}/edit` - Edit form

---

## Task 2.2: Property Management

### Database Migration
```php
Schema::create('properties', function (Blueprint $table) {
    $table->id();
    $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
    $table->string('name')->nullable(); // "Main House", "Rental Property", etc.
    $table->text('address');
    $table->string('city');
    $table->string('state');
    $table->string('zip');
    $table->enum('type', ['residential', 'commercial', 'industrial'])->default('residential');
    $table->integer('square_footage')->nullable();
    $table->integer('year_built')->nullable();
    $table->text('notes')->nullable();
    $table->timestamps();
    $table->softDeletes();
});

Schema::create('property_photos', function (Blueprint $table) {
    $table->id();
    $table->foreignId('property_id')->constrained()->cascadeOnDelete();
    $table->foreignId('uploaded_by')->constrained('users');
    $table->string('path');
    $table->string('filename');
    $table->string('caption')->nullable();
    $table->enum('category', ['before', 'during', 'after', 'general'])->default('general');
    $table->integer('sort_order')->default(0);
    $table->timestamps();
});
```

### Model Features
- Full address accessor
- Relationships: customer, photos, deals
- Photo upload handling

### Livewire Components
- `Properties\Index` - List with filters
- `Properties\Create` - Create form
- `Properties\Edit` - Edit form with photo management
- `Properties\PhotoGallery` - Photo upload and management

---

## Task 2.3: Deal Pipeline (Kanban)

### Database Migration
```php
Schema::create('deals', function (Blueprint $table) {
    $table->id();
    $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
    $table->foreignId('property_id')->nullable()->constrained()->nullOnDelete();
    $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete(); // Referring agent
    $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete(); // Assigned team member
    $table->foreignId('created_by')->constrained('users');
    $table->string('title');
    $table->text('description')->nullable();
    $table->enum('stage', [
        'lead',
        'quoted',
        'scheduled',
        'in_progress',
        'completed',
        'paid',
        'cancelled'
    ])->default('lead');
    $table->decimal('estimated_value', 10, 2)->nullable();
    $table->decimal('actual_value', 10, 2)->nullable();
    $table->date('expected_start_date')->nullable();
    $table->date('expected_end_date')->nullable();
    $table->date('actual_start_date')->nullable();
    $table->date('actual_end_date')->nullable();
    $table->integer('sort_order')->default(0); // For Kanban ordering
    $table->timestamps();
    $table->softDeletes();

    $table->index('stage');
    $table->index(['stage', 'sort_order']);
});

Schema::create('deal_notes', function (Blueprint $table) {
    $table->id();
    $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->constrained();
    $table->text('content');
    $table->boolean('is_internal')->default(false); // Hidden from customer
    $table->timestamps();
});
```

### Model Features
- Stage transitions with validation
- Relationships: customer, property, agent, assignee, notes, estimates, activities
- Scopes: byStage, active, forAgent
- Value calculations

### Livewire Components
- `Deals\Pipeline` - Kanban board view
- `Deals\Card` - Individual deal card
- `Deals\Create` - Create deal modal
- `Deals\Show` - Deal detail page with timeline
- `Deals\Edit` - Edit deal modal

### Kanban Implementation
- Drag and drop between stages
- Wire:sortable for ordering
- Optimistic UI updates
- Stage transition rules

---

## Task 2.4: Estimates

### Database Migration
```php
Schema::create('estimates', function (Blueprint $table) {
    $table->id();
    $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
    $table->foreignId('created_by')->constrained('users');
    $table->string('number')->unique(); // EST-2026-0001
    $table->enum('status', ['draft', 'sent', 'viewed', 'accepted', 'declined', 'expired'])->default('draft');
    $table->decimal('subtotal', 10, 2)->default(0);
    $table->decimal('tax_rate', 5, 2)->default(0);
    $table->decimal('tax_amount', 10, 2)->default(0);
    $table->decimal('total', 10, 2)->default(0);
    $table->text('notes')->nullable();
    $table->text('terms')->nullable();
    $table->date('valid_until')->nullable();
    $table->timestamp('sent_at')->nullable();
    $table->timestamp('viewed_at')->nullable();
    $table->timestamp('accepted_at')->nullable();
    $table->timestamp('declined_at')->nullable();
    $table->timestamps();
});

Schema::create('estimate_items', function (Blueprint $table) {
    $table->id();
    $table->foreignId('estimate_id')->constrained()->cascadeOnDelete();
    $table->string('description');
    $table->decimal('quantity', 10, 2)->default(1);
    $table->string('unit')->default('each'); // each, sqft, hour, etc.
    $table->decimal('unit_price', 10, 2);
    $table->decimal('total', 10, 2);
    $table->integer('sort_order')->default(0);
    $table->timestamps();
});
```

### Model Features
- Auto-generate estimate number
- Calculate totals from items
- Status transitions
- PDF generation

### Livewire Components
- `Estimates\Create` - Estimate builder
- `Estimates\Edit` - Edit with line items
- `Estimates\Show` - Preview/PDF view
- `Estimates\ItemRow` - Line item component
- `Estimates\Send` - Send via email

### PDF Generation
- Use Laravel DomPDF or Browsershot
- Professional template
- Company branding

---

## Task 2.5: Activities & Tasks

### Database Migration
```php
Schema::create('activities', function (Blueprint $table) {
    $table->id();
    $table->foreignId('deal_id')->nullable()->constrained()->cascadeOnDelete();
    $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->constrained(); // Created by
    $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
    $table->enum('type', ['task', 'call', 'email', 'meeting', 'note', 'system']);
    $table->string('title');
    $table->text('description')->nullable();
    $table->datetime('due_at')->nullable();
    $table->datetime('completed_at')->nullable();
    $table->enum('priority', ['low', 'medium', 'high'])->default('medium');
    $table->timestamps();

    $table->index(['deal_id', 'type']);
    $table->index(['assigned_to', 'completed_at']);
    $table->index('due_at');
});
```

### Model Features
- Polymorphic relationship (deal or customer)
- Completion tracking
- Due date reminders

### Livewire Components
- `Activities\Index` - Activity feed/timeline
- `Activities\Create` - Quick add activity
- `Activities\Task` - Task item with checkbox
- `Activities\Calendar` - Calendar view (optional)

---

## Suggested Implementation Order

1. **Week 1: Foundation**
   - Customer migration, model, factory
   - Customer Livewire CRUD components
   - Customer tests

2. **Week 2: Properties**
   - Property migration, model, factory
   - Property CRUD components
   - Photo upload functionality
   - Property tests

3. **Week 3: Deals Part 1**
   - Deal migration, model, factory
   - Deal CRUD components
   - Deal detail page with timeline
   - Deal tests

4. **Week 4: Deals Part 2 (Kanban)**
   - Kanban board component
   - Drag and drop functionality
   - Stage transitions
   - Kanban tests

5. **Week 5: Estimates**
   - Estimate migrations, models
   - Estimate builder component
   - Line items management
   - PDF generation
   - Estimate tests

6. **Week 6: Activities**
   - Activity migration, model
   - Activity components
   - Activity timeline on deals
   - Activity tests

---

## Testing Strategy

Each feature should have:
- Model tests (relationships, scopes, accessors)
- Livewire component tests (rendering, actions)
- Feature tests (full workflows)
- Authorization tests (who can do what)

---

## Notes for Implementation

### Access Control
- Internal users: Full access to all CRM features
- Agents: Read-only access to their referred customers' deals
- Customers: View their own deals and estimates only (Phase 4)

### Soft Deletes
Use soft deletes on customers, properties, deals for data integrity.

### Audit Trail
Consider adding activity logging for important actions (created, updated, stage changed).
