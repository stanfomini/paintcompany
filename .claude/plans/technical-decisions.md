# Technical Decisions & Architecture

## Authentication Architecture

### Decision: Single User Model with Type Column
**Why**: Simpler than separate tables, allows shared authentication logic, easier querying across user types.

**Implementation**:
- Single `users` table with `type` enum column ('internal', 'agent', 'customer')
- Type-specific profile tables for additional data
- Laravel guards for session isolation
- Middleware for access control

### Decision: Three Separate Guards
**Why**: Allows independent session management, cleaner route protection, prevents accidental cross-type access.

**Guards**:
- `web` - Internal users (default Laravel guard)
- `agent` - Agent users
- `customer` - Customer users

### Decision: Route Prefixing for Portals
**Why**: Clear URL structure, easier to understand and debug, SEO-friendly.

**Structure**:
- `/` - Landing page
- `/login`, `/dashboard` - Internal team
- `/agent/*` - Agent portal
- `/customer/*` - Customer portal

## Invitation System Architecture

### Decision: Signed URLs for Security
**Why**: Prevents URL guessing, includes expiration, standard Laravel feature.

**Implementation**:
- 64-character random token stored in database
- Laravel's `URL::signedRoute()` for URL generation
- Middleware validates signature automatically

### Decision: Invitation Model is Notifiable
**Why**: Allows sending email directly to the invitation's email address, not to a user model.

**Implementation**:
- Invitation model uses `Notifiable` trait
- Email routes to `$invitation->email`
- No user record needed until acceptance

### Decision: Queued Notifications
**Why**: Better UX (instant response), handles email failures gracefully.

**Implementation**:
- `InvitationNotification implements ShouldQueue`
- Database queue driver
- Queue worker processes emails asynchronously

## Frontend Architecture

### Decision: Livewire 4 Components
**Why**: Server-side state management, works well with Flux UI, no separate API needed.

### Decision: Flux UI Free Components
**Why**: Consistent design, built for Livewire, professional appearance.

**Note**: Using free tier - some Pro components unavailable.

## Database Decisions

### Decision: SQLite for Development
**Why**: Zero configuration, portable, fast for development.

### Decision: Database Queue Driver
**Why**: Simple setup, works with any database, good for moderate load.

## Code Organization

### Services Layer
- Business logic in `app/Services/`
- Controllers/Components stay thin
- Example: `InvitationService` handles all invitation logic

### Form Requests
- Validation in dedicated request classes
- Keeps controllers clean
- Reusable validation rules

### Factories with States
- Meaningful factory states for testing
- Example: `User::factory()->agent()->create()`

## Testing Strategy

### Feature Tests Over Unit Tests
**Why**: Test full request/response cycle, catch integration issues.

### Pest Framework
**Why**: Cleaner syntax, better DX, describe blocks for organization.

### Test Organization
```
tests/
  Feature/
    MultiAuthTest.php      # Auth flows for all user types
    InvitationSystemTest.php # Full invitation workflow
    UserModelTest.php      # Model behavior tests
```

## Email Configuration

### Decision: Mailgun API
**Why**: Reliable delivery, good Laravel support, webhook support.

**Configuration**:
- Uses Mailgun API (not SMTP)
- Domain: `mg.quickautooffers.com`
- FROM address must match Mailgun domain
