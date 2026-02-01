# Apex Negotiations - Painting Company CRM Master Plan

## Project Overview

A comprehensive CRM system for a painting company with three user types:
- **Internal Team** - Company employees (admins, managers, painters)
- **Agents** - Real estate agents who refer customers
- **Customers** - Homeowners needing painting services

## Tech Stack

- **Backend**: Laravel 12, PHP 8.3
- **Frontend**: Livewire 4, Flux UI (Free), Tailwind CSS
- **Database**: SQLite (dev), MySQL/PostgreSQL (prod)
- **Authentication**: Laravel Fortify with multi-guard setup
- **Testing**: Pest v4
- **Email**: Mailgun
- **Real-time**: Laravel Reverb (planned)

---

## Phase 1: Multi-Auth Foundation [COMPLETED]

### What Was Built

#### Database Schema
- Added `type` column to users table (enum: 'internal', 'agent', 'customer')
- Added `is_active` column for account status
- Created profile tables:
  - `internal_users` - for internal team details
  - `agent_profiles` - for agent-specific data
  - `customer_profiles` - for customer-specific data

#### Models & Relationships
- **User Model** (`app/Models/User.php`)
  - Type helpers: `isInternal()`, `isAgent()`, `isCustomer()`
  - Auto-creates profile on user creation via `booted()` method
  - Relationships to profile models
- **InternalUser Model** - belongs to User
- **AgentProfile Model** - belongs to User
- **CustomerProfile Model** - belongs to User

#### Authentication Configuration
- Three guards in `config/auth.php`:
  - `web` - for internal users
  - `agent` - for agents
  - `customer` - for customers
- Three providers filtering by user type

#### Middleware
- `EnsureUserIsInternal` - protects internal routes
- `EnsureUserIsAgent` - protects agent routes
- `EnsureUserIsCustomer` - protects customer routes
- Registered in `bootstrap/app.php`

#### Routes
- **Internal**: `/login`, `/dashboard` (standard Fortify)
- **Agent**: `/agent/login`, `/agent/dashboard`, `/agent/invitations`
- **Customer**: `/customer/login`, `/customer/dashboard`

#### Controllers
- `Agent\LoginController` - handles agent authentication
- `Agent\RegisterController` - handles agent registration
- `Customer\LoginController` - handles customer authentication

#### Views & Layouts
- `resources/views/components/layouts/agent.blade.php` - Agent portal layout
- `resources/views/components/layouts/customer.blade.php` - Customer portal layout
- `resources/views/agent/` - Agent views (login, register, dashboard)
- `resources/views/customer/` - Customer views (login, dashboard)

#### Factories
- `UserFactory` with states: `internal()`, `agent()`, `customer()`

#### Tests
- `tests/Feature/MultiAuthTest.php` - 22 tests covering all auth flows
- `tests/Feature/UserModelTest.php` - User model tests

---

## Phase 1.1: Invitation System [COMPLETED]

### What Was Built

#### Permission Rules
- **Internal users** can invite: internal, agents, customers
- **Agents** can invite: customers only
- **Customers** cannot invite anyone

#### Database
- `invitations` table with:
  - `email` - invitee's email
  - `token` - 64-char secure token (auto-generated)
  - `invited_user_type` - enum ('internal', 'agent', 'customer')
  - `inviter_id` - foreign key to users
  - `expires_at` - 7 days from creation (auto-set)
  - `accepted_at` - null until accepted

#### Models
- **Invitation Model** (`app/Models/Invitation.php`)
  - Uses `HasFactory`, `Notifiable` traits
  - Auto-generates token on creation
  - Auto-sets 7-day expiration
  - Methods: `isExpired()`, `isAccepted()`, `isPending()`, `markAsAccepted()`
  - Scopes: `pending()`, `expired()`, `forEmail()`
  - Relationship: `inviter()` belongs to User

#### Services
- **InvitationService** (`app/Services/InvitationService.php`)
  - `getInvitableTypes(User $user)` - returns array of types user can invite
  - `canInvite(User $inviter, string $type)` - permission check
  - `canSendInvitations(User $user)` - can user invite anyone
  - `sendInvitation(User $inviter, string $email, string $type)` - creates and sends
  - `generateAcceptUrl(Invitation $invitation)` - signed URL
  - `validateInvitation(string $token)` - checks if valid
  - `acceptInvitation(Invitation $invitation, array $userData)` - creates user
  - `getPendingInvitations(User $user)` - list sent invitations
  - `cancelInvitation(Invitation $invitation, User $user)` - delete invitation

#### Notifications
- **InvitationNotification** (`app/Notifications/InvitationNotification.php`)
  - Queued email notification
  - Contains signed accept URL
  - Professional email template

#### Livewire Components
- **SendInvitation** (`app/Livewire/Invitations/SendInvitation.php`)
  - Modal for sending invitations
  - Shows only allowed user types
  - View: `resources/views/livewire/invitations/send-invitation.blade.php`

- **AcceptInvitation** (`app/Livewire/Invitations/AcceptInvitation.php`)
  - Full-page component for accepting
  - Creates account, logs user in, redirects to appropriate dashboard
  - View: `resources/views/livewire/invitations/accept-invitation.blade.php`

- **InvitationList** (`app/Livewire/Invitations/InvitationList.php`)
  - Lists pending invitations
  - Resend and cancel functionality
  - View: `resources/views/livewire/invitations/invitation-list.blade.php`

#### Routes
- `GET /invitation/accept/{token}` - signed URL for accepting (public)
- `GET /invitations` - manage invitations (internal users)
- `GET /agent/invitations` - manage invitations (agents)

#### Views
- `resources/views/welcome.blade.php` - Landing page with 3 login options
- `resources/views/invitations.blade.php` - Internal invitation management
- `resources/views/agent/invitations.blade.php` - Agent invitation management
- `resources/views/components/layouts/guest.blade.php` - Guest layout

#### Sidebar Navigation
- Internal users see "Invitations" link in sidebar
- Agents see "Invite Customers" link in sidebar
- Customers see no invite option

#### Email Configuration
- Mailgun configured in `config/mail.php` and `config/services.php`
- Environment variables:
  - `MAIL_MAILER=mailgun`
  - `MAILGUN_DOMAIN=mg.quickautooffers.com`
  - `MAILGUN_SECRET=<api-key>`
  - `MAIL_FROM_ADDRESS=noreply@mg.quickautooffers.com`

#### Factory
- **InvitationFactory** with states:
  - `forInternal()`, `forAgent()`, `forCustomer()`
  - `accepted()`, `expired()`
  - `fromAgent()` - creates invitation from agent inviter

#### Tests
- `tests/Feature/InvitationSystemTest.php` - 29 tests covering:
  - Permission rules for all user types
  - Invitation model methods
  - Sending invitations
  - Accepting invitations
  - URL generation and validation
  - Route access control
  - Factory states

---

## Phase 2: Core CRM [NOT STARTED]

### Planned Features

#### Customer Management
- Customer profiles with contact info
- Property associations
- Communication history
- Notes and tags

#### Deal/Project Pipeline
- Kanban board for deal stages
- Stages: Lead → Quoted → Scheduled → In Progress → Completed → Paid
- Deal details: property, estimate, dates, assignees
- Activity timeline

#### Property Management
- Property records (address, type, size)
- Photo gallery per property
- Historical work done
- Associated customers

#### Estimates
- Create estimates for deals
- Line items with pricing
- PDF generation
- Email to customer
- Estimate acceptance workflow

#### Activities
- Task management
- Calendar integration
- Reminders and notifications
- Activity history on deals

### Database Schema (Planned)

```
customers
- id, user_id (nullable), name, email, phone, address, notes, created_at, updated_at

properties
- id, customer_id, address, city, state, zip, type, square_footage, notes, created_at, updated_at

deals
- id, customer_id, property_id, agent_id (nullable), status, stage, title, description, estimated_value, actual_value, started_at, completed_at, created_at, updated_at

estimates
- id, deal_id, number, status, subtotal, tax, total, notes, valid_until, accepted_at, created_at, updated_at

estimate_items
- id, estimate_id, description, quantity, unit_price, total, sort_order

activities
- id, deal_id, user_id, type, title, description, due_at, completed_at, created_at, updated_at

deal_notes
- id, deal_id, user_id, content, created_at, updated_at

property_photos
- id, property_id, path, caption, sort_order, created_at, updated_at
```

---

## Phase 3: Agent Portal [NOT STARTED]

### Planned Features
- View referred customers only
- Track deals for their referrals
- View estimates (read-only)
- Commission tracking
- Performance dashboard

---

## Phase 4: Customer Portal [NOT STARTED]

### Planned Features
- View their properties
- See project status
- View and accept estimates
- Photo gallery of work
- Message/chat with team
- Invoice history

---

## Phase 5: Real-time Chat [NOT STARTED]

### Planned Features
- Laravel Reverb for WebSockets
- Chat between internal team and customers
- Chat between internal team and agents
- Chat threads per deal/project
- File/image sharing
- Read receipts
- Push notifications

---

## Phase 6: Ticketing System [NOT STARTED]

### Planned Features
- Support tickets from customers
- Internal task tickets
- Priority levels
- Assignment workflow
- Status tracking
- Email notifications
- SLA tracking

---

## Current Test Status

```
Tests: 95 passed (200 assertions)
- MultiAuthTest: 22 tests
- InvitationSystemTest: 29 tests
- Other existing tests: 44 tests
```

---

## Environment Setup Notes

### Required ENV Variables
```
APP_NAME=PaintBusiness
MAIL_MAILER=mailgun
MAILGUN_DOMAIN=mg.quickautooffers.com
MAILGUN_SECRET=<your-api-key>
MAILGUN_ENDPOINT=api.mailgun.net
MAIL_FROM_ADDRESS=noreply@mg.quickautooffers.com
MAIL_FROM_NAME="${APP_NAME}"
QUEUE_CONNECTION=database
```

### Queue Worker
For production, set up a queue worker via Laravel Forge or:
```bash
php artisan queue:work
```

### Running Tests
```bash
php artisan test --compact
```

### Code Formatting
```bash
vendor/bin/pint --dirty
```

---

## Key Files Reference

### Authentication
- `config/auth.php` - Guards and providers
- `bootstrap/app.php` - Middleware registration
- `app/Http/Middleware/EnsureUserIsInternal.php`
- `app/Http/Middleware/EnsureUserIsAgent.php`
- `app/Http/Middleware/EnsureUserIsCustomer.php`

### Routes
- `routes/web.php` - Internal routes
- `routes/agent.php` - Agent routes
- `routes/customer.php` - Customer routes

### Models
- `app/Models/User.php`
- `app/Models/Invitation.php`
- `app/Models/InternalUser.php`
- `app/Models/AgentProfile.php`
- `app/Models/CustomerProfile.php`

### Services
- `app/Services/InvitationService.php`

### Livewire Components
- `app/Livewire/Invitations/SendInvitation.php`
- `app/Livewire/Invitations/AcceptInvitation.php`
- `app/Livewire/Invitations/InvitationList.php`
