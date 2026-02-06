# Apex Negotiations - Painting Company CRM

## Project Plans

**IMPORTANT**: Before starting work, read the plan files in `.claude/plans/`:

- **`.claude/plans/master-plan.md`** - Complete project overview, all phases, what's built
- **`.claude/plans/current-status.md`** - Current progress, what's next, quick commands
- **`.claude/plans/technical-decisions.md`** - Architecture decisions and rationale
- **`.claude/plans/phase-2-crm-plan.md`** - Detailed plan for next phase (Core CRM)

## Livewire Components - ENFORCED CONVENTION

**ALWAYS use Livewire 4 single-file components (SFC).** Every Livewire component must be a single `.blade.php` file with PHP at the top and template below. **Never** create separate class-based components in `app/Livewire/`.

- **Location**: `resources/views/components/` for reusable components, `resources/views/pages/` for page components
- **Filename prefix**: `⚡` (e.g., `⚡create-modal.blade.php`)
- **Format**: PHP class in `<?php ... ?>` tags at top, then `?>`, then Blade template below
- **Reference in tests/routes**: Use string names like `'customers.create-modal'`, never `ClassName::class`
- **Keep components focused** on a single responsibility
- **Use `#[Computed]`** for data passed to templates (no `return view(..., ['data' => $value])`)

```blade
<?php
use Livewire\Component;

new class extends Component {
    public string $name = '';

    public function save(): void { /* ... */ }
};
?>

<div>
    <input wire:model="name" />
    <button wire:click="save">Save</button>
</div>
```

## Quick Status

- **Phase 1: Multi-Auth** - COMPLETE
- **Phase 1.1: Invitation System** - COMPLETE
- **Phase 2: Core CRM** - COMPLETE (Customers, Deals, Properties, Estimates)
- **Sprint 7: Integration & Polish** - COMPLETE (PDF generation, notifications, company settings)
- **PBP Pricing System** - COMPLETE (Substrates, Paint Catalog, Nested Areas, Calculator Service)
- **Simple Estimate Workflow** - IN PROGRESS (see `.claude/plans/spreadsheet-workflow-plan.md`)
- **Total Tests**: 1852 passing

## CURRENT WORK: Simple Estimate Workflow - Deep Dive Phase

**Goal**: Faster than the spreadsheet. One-click room selection, instant pricing.

**IMPORTANT**: Read `.claude/plans/simple-workflow-handoff.md` for next session context!

**Plan File**: `.claude/plans/spreadsheet-workflow-plan.md`

**The Vision**:
```
Click [+ Living Room] → Pick [Medium ▼] → See [$827] → Done
```

**What's Built** (PBP backend - all working):
- Production rates with sqft/hr, lnft/hr, hours/unit
- Company settings (wage, margins, cleanup ratio, sundries)
- PaintingEstimateCalculatorService for PBP formula
- EstimateArea and EstimateAreaSurface models

**What We're Building** (Tasks #6-10):
1. ✅ Room templates with size options (S/M/L) and surface ratios - DONE
2. ✅ Simple room picker UI (grid of buttons, size dropdown) - DONE
3. ✅ Connect to PBP calculator (auto-calculate from template) - DONE
4. ✅ Optional expand-to-customize feature - DONE (expandable breakdown)
5. ✅ Test prices match spreadsheet - DONE (7 tests verifying PBP formula)

**Completed Templates** (seeded in database):
- Interior: Living Room, Bedroom, Master Bedroom, Bathroom, Kitchen, Dining Room, Hallway, Office, Laundry, Closet
- Exterior: House Exterior, Garage, Deck, Fence, Porch, Shed
- Each has: sizes (S/M/L), surface_ratios (walls, ceiling, baseboards, doors)

**Key Features Built**:
- One-click Quick Add buttons for common rooms
- Size dropdown (S/M/L) that instantly recalculates price
- Per-surface breakdown using production rates (walls, ceiling, baseboards, doors)
- PBP formula applied: labor + cleanup + sundries + materials → margins
- Expandable details showing surface quantities and hours
- Comprehensive test coverage: SpreadsheetPricingTest.php (7 tests)

**Simple Workflow is COMPLETE** - Tasks 6-10 all done!

**NEXT PHASE: Deep Dive Audit** (see `.claude/plans/simple-workflow-handoff.md`)
- Are substrates actually affecting prices? (currently NO)
- Are we using the right production rates from spreadsheet?
- Is material cost coming from PaintProduct or hardcoded?
- Do we need a "detailed builder" for complex jobs?
- Should linear foot inputs be available for precision?
- Compare our output to spreadsheet for same inputs

**Key Insight from Spreadsheet Analysis**:
- User enters room sqft → system auto-calculates surfaces using ratios
- Walls sqft = room sqft × 1.0
- Ceiling sqft = room sqft × 0.9
- Baseboards lnft = room sqft / 100 × 28
- Doors = count (typically 1 per room)
- All PBP complexity hidden from user

**Reference Spreadsheet**: https://docs.google.com/spreadsheets/d/13jhzT7ulHZVIwUBGfKx2Z-qwaT3IirDrPLZ8AdcuOCw/

## User Types

1. **Internal** - Company team (full access)
2. **Agent** - Real estate agents (can invite customers, see their referrals)
3. **Customer** - Homeowners (view their projects only)

## Key Services

- `InvitationService` - Handles all invitation logic with permission rules
- `ProductionRateService` - Painting production rate calculations and seeding
- `PythonBridgeService` - HTTP client for Python ML/processing service integration
- `PaintingEstimateCalculatorService` - Core PBP pricing engine (labor + materials + margins)

## Key Models (Estimates)

- `Estimate` - Main estimate with status workflow (draft → sent → accepted/rejected)
- `EstimateItem` - Traditional line items with quantity, unit, price
- `EstimateArea` - Room/area with dimensions (auto-calculates sqft)
- `EstimateAreaSurface` - Surfaces within areas (walls, ceiling, trim) with production rates
- `EstimateOption` - Add-on options customers can select (fixed, per_sqft, per_surface pricing)
- `EstimatePhoto` - Photos with show_on_proposal flag
- `EstimateSignature` - Customer digital signature (drawn or typed)
- `EstimateActivity` - Activity log for estimate changes
- `EstimateView` - Tracks when customers view public proposals
- `ProductionRate` - Painting industry production rates by category
- `ProductionRateCategory` - Categories (Interior, Exterior, Specialty)
- `CompanySetting` - Singleton model for company information and defaults (name, address, tax rate, etc.)

## PBP (Painting Business Pricing) Models

- `Substrate` - Surface conditions (previously painted, new drywall, peeling) with prep/paint multipliers
- `PaintBrand` - Paint manufacturers (Sherwin-Williams, Benjamin Moore, Behr, PPG)
- `PaintProduct` - Individual paints with pricing, coverage, sheens (Duration, ProMar 200, etc.)
- `NestedAreaTemplate` - Fixed-price templates for closets, half baths, etc.
- `EstimateAreaPaintSelection` - Links areas to specific paint products per surface

## PBP Seeders

```bash
php artisan db:seed --class=SubstrateSeeder        # 22 substrates
php artisan db:seed --class=PaintCatalogSeeder     # 4 brands, 25+ products
php artisan db:seed --class=NestedAreaTemplateSeeder  # 15 templates
```

## PBP Pricing Calculator

```php
$calculator = new PaintingEstimateCalculatorService();

// Quick estimate for a surface
$result = $calculator->quickEstimate(sqft: 400, productionRate: 200, coats: 2);
// Returns: hours, labor_cost, price_with_margins

// Calculate full area with substrates, paint, nested areas
$areaResult = $calculator->calculateArea($area);

// Get pricing formula explanation
$explanation = $calculator->getPricingExplanation();
```

**Pricing Formula**: `(sqft / rate) × multipliers × hourly_rate + materials → profit → overhead → markup`

## Key Routes

- `/estimates` - Estimate management (internal users)
- `/estimates/{estimate}/pdf` - PDF download
- `/proposal/{token}` - Public customer proposal view
- `/production-rates` - Production rate management
- `/settings/company` - Company settings (internal users only)

## Notifications

- `EstimateProposalSent` - Sent to customer when estimate is sent (includes public proposal URL)
- `EstimateAccepted` - Sent to internal users when customer accepts estimate
- `EstimateRejected` - Sent to internal users when customer rejects estimate

## Artisan Commands

- `php artisan production-rates:seed` - Seed default painting production rates

## Python Service

The application includes a Python FastAPI service for ML processing, web scraping, and media handling.

### Structure
- `python/` - Python service root
- `python/api/` - FastAPI application
- `python/api/routers/` - API endpoints (health, images, scraping, media)
- `python/api/services/` - Business logic (ml, scraper, media)
- `config/python.php` - Laravel configuration
- `app/Services/PythonBridgeService.php` - Laravel HTTP client

### Environment Variables
```env
PYTHON_ENABLED=true
PYTHON_SERVICE_URL=http://127.0.0.1:8000
PYTHON_SERVICE_TIMEOUT=60
ML_GPU_ENABLED=false
```

### Running Python Service
```bash
cd python
python -m venv venv
source venv/bin/activate
pip install -r requirements.txt
uvicorn api.main:app --reload --port 8000
```

### Using in Laravel
```php
$python = app(PythonBridgeService::class);
$python->healthCheck();  // Check service status
$python->detectSubstrate($imageUrl);  // Detect paint surface type
$python->scrapeListing($zillowUrl);  // Scrape real estate listing
$python->transcribeAudio($audioUrl);  // Transcribe audio with Whisper
$python->recolorSurface($imageUrl, '#C0D6D2');  // Change wall color
```

### Laravel Job Classes
- `App\Jobs\Python\ScrapeListingJob` - Queue scraping jobs
- `App\Jobs\Python\ProcessImageMLJob` - Queue image processing
- `App\Jobs\Python\TranscribeAudioJob` - Queue audio transcription

## Running the Project

```bash
# Queue worker (required for emails)
php artisan queue:work

# Python service (optional, for ML/scraping)
cd python && uvicorn api.main:app --reload --port 8000

# Run tests
php artisan test --compact

# Format code
vendor/bin/pint --dirty
```

---

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to enhance the user's satisfaction building Laravel applications.

## Foundational Context
This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.3.11
- laravel/fortify (FORTIFY) - v1
- laravel/framework (LARAVEL) - v12
- laravel/prompts (PROMPTS) - v0
- livewire/flux (FLUXUI_FREE) - v2
- livewire/livewire (LIVEWIRE) - v4
- laravel/mcp (MCP) - v0
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- laravel-echo (ECHO) - v2
- react (REACT) - v19
- tailwindcss (TAILWINDCSS) - v4

## Conventions
- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts
- Do not create verification scripts or tinker when tests cover that functionality and prove it works. Unit and feature tests are more important.

## Application Structure & Architecture
- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling
- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Replies
- Be concise in your explanations - focus on what's important rather than explaining obvious details.

## Documentation Files
- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

## Laravel Boost
- Laravel Boost is an MCP server that comes with powerful tools designed specifically for this application. Use them.

## Artisan
- Use the `list-artisan-commands` tool when you need to call an Artisan command to double-check the available parameters.

## URLs
- Whenever you share a project URL with the user, you should use the `get-absolute-url` tool to ensure you're using the correct scheme, domain/IP, and port.

## Tinker / Debugging
- You should use the `tinker` tool when you need to execute PHP to debug code or query Eloquent models directly.
- Use the `database-query` tool when you only need to read from the database.

## Reading Browser Logs With the `browser-logs` Tool
- You can read browser logs, errors, and exceptions using the `browser-logs` tool from Boost.
- Only recent browser logs will be useful - ignore old logs.

## Searching Documentation (Critically Important)
- Boost comes with a powerful `search-docs` tool you should use before any other approaches when dealing with Laravel or Laravel ecosystem packages. This tool automatically passes a list of installed packages and their versions to the remote Boost API, so it returns only version-specific documentation for the user's circumstance. You should pass an array of packages to filter on if you know you need docs for particular packages.
- The `search-docs` tool is perfect for all Laravel-related packages, including Laravel, Inertia, Livewire, Filament, Tailwind, Pest, Nova, Nightwatch, etc.
- You must use this tool to search for Laravel ecosystem documentation before falling back to other approaches.
- Search the documentation before making code changes to ensure we are taking the correct approach.
- Use multiple, broad, simple, topic-based queries to start. For example: `['rate limiting', 'routing rate limiting', 'routing']`.
- Do not add package names to queries; package information is already shared. For example, use `test resource table`, not `filament 4 test resource table`.

### Available Search Syntax
- You can and should pass multiple queries at once. The most relevant results will be returned first.

1. Simple Word Searches with auto-stemming - query=authentication - finds 'authenticate' and 'auth'.
2. Multiple Words (AND Logic) - query=rate limit - finds knowledge containing both "rate" AND "limit".
3. Quoted Phrases (Exact Position) - query="infinite scroll" - words must be adjacent and in that order.
4. Mixed Queries - query=middleware "rate limit" - "middleware" AND exact phrase "rate limit".
5. Multiple Queries - queries=["authentication", "middleware"] - ANY of these terms.

=== php rules ===

## PHP

- Always use curly braces for control structures, even if it has one line.

### Constructors
- Use PHP 8 constructor property promotion in `__construct()`.
    - <code-snippet>public function __construct(public GitHub $github) { }</code-snippet>
- Do not allow empty `__construct()` methods with zero parameters unless the constructor is private.

### Type Declarations
- Always use explicit return type declarations for methods and functions.
- Use appropriate PHP type hints for method parameters.

<code-snippet name="Explicit Return Types and Method Params" lang="php">
protected function isAccessible(User $user, ?string $path = null): bool
{
    ...
}
</code-snippet>

## Comments
- Prefer PHPDoc blocks over inline comments. Never use comments within the code itself unless there is something very complex going on.

## PHPDoc Blocks
- Add useful array shape type definitions for arrays when appropriate.

## Enums
- Typically, keys in an Enum should be TitleCase. For example: `FavoritePerson`, `BestLake`, `Monthly`.

=== tests rules ===

## Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

## Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using the `list-artisan-commands` tool.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Database
- Always use proper Eloquent relationship methods with return type hints. Prefer relationship methods over raw queries or manual joins.
- Use Eloquent models and relationships before suggesting raw database queries.
- Avoid `DB::`; prefer `Model::query()`. Generate code that leverages Laravel's ORM capabilities rather than bypassing them.
- Generate code that prevents N+1 query problems by using eager loading.
- Use Laravel's query builder for very complex database operations.

### Model Creation
- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `list-artisan-commands` to check the available options to `php artisan make:model`.

### APIs & Eloquent Resources
- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

### Controllers & Validation
- Always create Form Request classes for validation rather than inline validation in controllers. Include both validation rules and custom error messages.
- Check sibling Form Requests to see if the application uses array or string based validation rules.

### Queues
- Use queued jobs for time-consuming operations with the `ShouldQueue` interface.

### Authentication & Authorization
- Use Laravel's built-in authentication and authorization features (gates, policies, Sanctum, etc.).

### URL Generation
- When generating links to other pages, prefer named routes and the `route()` function.

### Configuration
- Use environment variables only in configuration files - never use the `env()` function directly outside of config files. Always use `config('app.name')`, not `env('APP_NAME')`.

### Testing
- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

### Vite Error
- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel/v12 rules ===

## Laravel 12

- Use the `search-docs` tool to get version-specific documentation.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

### Laravel 12 Structure
- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app\Console\Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

### Database
- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models
- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== fluxui-free/core rules ===

## Flux UI Free

- This project is using the free edition of Flux UI. It has full access to the free components and variants, but does not have access to the Pro components.
- Flux UI is a component library for Livewire. Flux is a robust, hand-crafted UI component library for your Livewire applications. It's built using Tailwind CSS and provides a set of components that are easy to use and customize.
- You should use Flux UI components when available.
- Fallback to standard Blade components if Flux is unavailable.
- If available, use the `search-docs` tool to get the exact documentation and code snippets available for this project.
- Flux UI components look like this:

<code-snippet name="Flux UI Component Example" lang="blade">
    <flux:button variant="primary"/>
</code-snippet>

### Available Components
This is correct as of Boost installation, but there may be additional components within the codebase.

<available-flux-components>
avatar, badge, brand, breadcrumbs, button, callout, checkbox, dropdown, field, heading, icon, input, modal, navbar, otp-input, profile, radio, select, separator, skeleton, switch, text, textarea, tooltip
</available-flux-components>

=== livewire/core rules ===

## Livewire

- Use the `search-docs` tool to find exact version-specific documentation for how to write Livewire and Livewire tests.
- Use the `php artisan make:livewire [Posts\CreatePost]` Artisan command to create new components.
- State should live on the server, with the UI reflecting it.
- All Livewire requests hit the Laravel backend; they're like regular HTTP requests. Always validate form data and run authorization checks in Livewire actions.

## Livewire Best Practices
- Livewire components require a single root element.
- Use `wire:loading` and `wire:dirty` for delightful loading states.
- Add `wire:key` in loops:

    ```blade
    @foreach ($items as $item)
        <div wire:key="item-{{ $item->id }}">
            {{ $item->name }}
        </div>
    @endforeach
    ```

- Prefer lifecycle hooks like `mount()`, `updatedFoo()` for initialization and reactive side effects:

<code-snippet name="Lifecycle Hook Examples" lang="php">
    public function mount(User $user) { $this->user = $user; }
    public function updatedSearch() { $this->resetPage(); }
</code-snippet>

## Testing Livewire

<code-snippet name="Example Livewire Component Test" lang="php">
    Livewire::test(Counter::class)
        ->assertSet('count', 0)
        ->call('increment')
        ->assertSet('count', 1)
        ->assertSee(1)
        ->assertStatus(200);
</code-snippet>

<code-snippet name="Testing Livewire Component Exists on Page" lang="php">
    $this->get('/posts/create')
    ->assertSeeLivewire(CreatePost::class);
</code-snippet>

=== pint/core rules ===

## Laravel Pint Code Formatter

- You must run `vendor/bin/pint --dirty` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test`, simply run `vendor/bin/pint` to fix any formatting issues.

=== pest/core rules ===

## Pest
### Testing
- If you need to verify a feature is working, write or update a Unit / Feature test.

### Pest Tests
- All tests must be written using Pest. Use `php artisan make:test --pest {name}`.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files - these are core to the application.
- Tests should test all of the happy paths, failure paths, and weird paths.
- Tests live in the `tests/Feature` and `tests/Unit` directories.
- Pest tests look and behave like this:
<code-snippet name="Basic Pest Test Example" lang="php">
it('is true', function () {
    expect(true)->toBeTrue();
});
</code-snippet>

### Running Tests
- Run the minimal number of tests using an appropriate filter before finalizing code edits.
- To run all tests: `php artisan test --compact`.
- To run all tests in a file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --compact --filter=testName` (recommended after making a change to a related file).
- When the tests relating to your changes are passing, ask the user if they would like to run the entire test suite to ensure everything is still passing.

### Pest Assertions
- When asserting status codes on a response, use the specific method like `assertForbidden` and `assertNotFound` instead of using `assertStatus(403)` or similar, e.g.:
<code-snippet name="Pest Example Asserting postJson Response" lang="php">
it('returns all', function () {
    $response = $this->postJson('/api/docs', []);

    $response->assertSuccessful();
});
</code-snippet>

### Mocking
- Mocking can be very helpful when appropriate.
- When mocking, you can use the `Pest\Laravel\mock` Pest function, but always import it via `use function Pest\Laravel\mock;` before using it. Alternatively, you can use `$this->mock()` if existing tests do.
- You can also create partial mocks using the same import or self method.

### Datasets
- Use datasets in Pest to simplify tests that have a lot of duplicated data. This is often the case when testing validation rules, so consider this solution when writing tests for validation rules.

<code-snippet name="Pest Dataset Example" lang="php">
it('has emails', function (string $email) {
    expect($email)->not->toBeEmpty();
})->with([
    'james' => 'james@laravel.com',
    'taylor' => 'taylor@laravel.com',
]);
</code-snippet>

=== pest/v4 rules ===

## Pest 4

- Pest 4 is a huge upgrade to Pest and offers: browser testing, smoke testing, visual regression testing, test sharding, and faster type coverage.
- Browser testing is incredibly powerful and useful for this project.
- Browser tests should live in `tests/Browser/`.
- Use the `search-docs` tool for detailed guidance on utilizing these features.

### Browser Testing
- You can use Laravel features like `Event::fake()`, `assertAuthenticated()`, and model factories within Pest 4 browser tests, as well as `RefreshDatabase` (when needed) to ensure a clean state for each test.
- Interact with the page (click, type, scroll, select, submit, drag-and-drop, touch gestures, etc.) when appropriate to complete the test.
- If requested, test on multiple browsers (Chrome, Firefox, Safari).
- If requested, test on different devices and viewports (like iPhone 14 Pro, tablets, or custom breakpoints).
- Switch color schemes (light/dark mode) when appropriate.
- Take screenshots or pause tests for debugging when appropriate.

### Example Tests

<code-snippet name="Pest Browser Test Example" lang="php">
it('may reset the password', function () {
    Notification::fake();

    $this->actingAs(User::factory()->create());

    $page = visit('/sign-in'); // Visit on a real browser...

    $page->assertSee('Sign In')
        ->assertNoJavascriptErrors() // or ->assertNoConsoleLogs()
        ->click('Forgot Password?')
        ->fill('email', 'nuno@laravel.com')
        ->click('Send Reset Link')
        ->assertSee('We have emailed your password reset link!')

    Notification::assertSent(ResetPassword::class);
});
</code-snippet>

<code-snippet name="Pest Smoke Testing Example" lang="php">
$pages = visit(['/', '/about', '/contact']);

$pages->assertNoJavascriptErrors()->assertNoConsoleLogs();
</code-snippet>

=== tailwindcss/core rules ===

## Tailwind CSS

- Use Tailwind CSS classes to style HTML; check and use existing Tailwind conventions within the project before writing your own.
- Offer to extract repeated patterns into components that match the project's conventions (i.e. Blade, JSX, Vue, etc.).
- Think through class placement, order, priority, and defaults. Remove redundant classes, add classes to parent or child carefully to limit repetition, and group elements logically.
- You can use the `search-docs` tool to get exact examples from the official documentation when needed.

### Spacing
- When listing items, use gap utilities for spacing; don't use margins.

<code-snippet name="Valid Flex Gap Spacing Example" lang="html">
    <div class="flex gap-8">
        <div>Superior</div>
        <div>Michigan</div>
        <div>Erie</div>
    </div>
</code-snippet>

### Dark Mode
- If existing pages and components support dark mode, new pages and components must support dark mode in a similar way, typically using `dark:`.

=== tailwindcss/v4 rules ===

## Tailwind CSS 4

- Always use Tailwind CSS v4; do not use the deprecated utilities.
- `corePlugins` is not supported in Tailwind v4.
- In Tailwind v4, configuration is CSS-first using the `@theme` directive — no separate `tailwind.config.js` file is needed.

<code-snippet name="Extending Theme in CSS" lang="css">
@theme {
  --color-brand: oklch(0.72 0.11 178);
}
</code-snippet>

- In Tailwind v4, you import Tailwind using a regular CSS `@import` statement, not using the `@tailwind` directives used in v3:

<code-snippet name="Tailwind v4 Import Tailwind Diff" lang="diff">
   - @tailwind base;
   - @tailwind components;
   - @tailwind utilities;
   + @import "tailwindcss";
</code-snippet>

### Replaced Utilities
- Tailwind v4 removed deprecated utilities. Do not use the deprecated option; use the replacement.
- Opacity values are still numeric.

| Deprecated |	Replacement |
|------------+--------------|
| bg-opacity-* | bg-black/* |
| text-opacity-* | text-black/* |
| border-opacity-* | border-black/* |
| divide-opacity-* | divide-black/* |
| ring-opacity-* | ring-black/* |
| placeholder-opacity-* | placeholder-black/* |
| flex-shrink-* | shrink-* |
| flex-grow-* | grow-* |
| overflow-ellipsis | text-ellipsis |
| decoration-slice | box-decoration-slice |
| decoration-clone | box-decoration-clone |

=== laravel/fortify rules ===

## Laravel Fortify

Fortify is a headless authentication backend that provides authentication routes and controllers for Laravel applications.

**Before implementing any authentication features, use the `search-docs` tool to get the latest docs for that specific feature.**

### Configuration & Setup
- Check `config/fortify.php` to see what's enabled. Use `search-docs` for detailed information on specific features.
- Enable features by adding them to the `'features' => []` array: `Features::registration()`, `Features::resetPasswords()`, etc.
- To see the all Fortify registered routes, use the `list-routes` tool with the `only_vendor: true` and `action: "Fortify"` parameters.
- Fortify includes view routes by default (login, register). Set `'views' => false` in the configuration file to disable them if you're handling views yourself.

### Customization
- Views can be customized in `FortifyServiceProvider`'s `boot()` method using `Fortify::loginView()`, `Fortify::registerView()`, etc.
- Customize authentication logic with `Fortify::authenticateUsing()` for custom user retrieval / validation.
- Actions in `app/Actions/Fortify/` handle business logic (user creation, password reset, etc.). They're fully customizable, so you can modify them to change feature behavior.

## Available Features
- `Features::registration()` for user registration.
- `Features::emailVerification()` to verify new user emails.
- `Features::twoFactorAuthentication()` for 2FA with QR codes and recovery codes.
  - Add options: `['confirmPassword' => true, 'confirm' => true]` to require password confirmation and OTP confirmation before enabling 2FA.
- `Features::updateProfileInformation()` to let users update their profile.
- `Features::updatePasswords()` to let users change their passwords.
- `Features::resetPasswords()` for password reset via email.
</laravel-boost-guidelines>
