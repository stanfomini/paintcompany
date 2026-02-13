# Research: Laravel Translation / Localization for Spanish Language Support
*Generated: 2026-02-13*

## Requirements

- Add Spanish (es) language support to the Apex Negotiations painting CRM
- Language switcher accessible on every page (all user types: internal, agent, customer, crew)
- Laravel 12 compatible
- Livewire 4 compatible (critical -- app uses SFC components exclusively)
- Easy to manage translations (UI strings, validation messages, etc.)
- Ideally auto-translate or provide tooling for managing translation files
- Must work with multi-guard auth system (web, agent, customer, crew guards)

---

## Options Evaluated

### Option 1: Laravel Built-in Localization (lang files + custom middleware)
- **Version**: Ships with Laravel 12
- **Laravel 12 Compatible**: YES
- **Livewire 4 Compatible**: YES (no routing conflicts, uses session-based locale)
- **GitHub Stars**: N/A (part of framework)
- **Last Commit**: N/A (maintained as part of Laravel core)
- **Maintenance Status**: Active (maintained by Laravel team)
- **What It Does**: Provides `__()` helper, `trans_choice()`, Blade `@lang` directive, PHP array files (`lang/en/messages.php`), and JSON translation files (`lang/es.json`). Supports parameter replacement, pluralization, and fallback locales.
- **Pros**:
  - Zero additional dependencies
  - No routing conflicts with Livewire 4 (session-based, not URL-prefix-based)
  - Native to Laravel -- all docs, tutorials, and community support assume this
  - Works perfectly with multi-guard auth (no URL prefix interference)
  - JSON translation files are simple: `{"Welcome": "Bienvenido"}`
  - `__('Welcome')` works everywhere -- Blade, PHP, Livewire components
  - Fallback locale support built in (falls back to `en` if `es` key missing)
  - Pluralization rules support Spanish natively (`Pluralizer::useLanguage('spanish')`)
  - No migration or database table needed
- **Cons**:
  - No built-in translation management UI
  - Manually creating translation files for custom app strings
  - No auto-translate feature
  - Language switcher must be built manually (simple Livewire component)
- **Source**: https://laravel.com/docs/12.x/localization

### Option 2: spatie/laravel-translatable (v6.12.0)
- **Version**: 6.12.0 (November 24, 2025)
- **Laravel 12 Compatible**: YES (`illuminate/database: ^11.0|^12.0`)
- **Livewire 4 Compatible**: YES
- **GitHub Stars**: ~3k
- **Last Commit**: November 2025
- **Maintenance Status**: Active (Spatie quality standard)
- **What It Does**: Makes Eloquent MODEL fields translatable. Stores translations as JSON in database columns. A `HasTranslations` trait lets you do `$model->setTranslation('name', 'es', 'Nombre')`.
- **Pros**:
  - Excellent for translating database content (model attributes)
  - No extra database tables needed (JSON columns)
  - Spatie quality and documentation
- **Cons**:
  - WRONG TOOL FOR THIS JOB -- this is for model/database content translation, NOT UI string translation
  - Does not handle `__('Welcome')` style translations
  - Does not provide language switcher
  - Does not translate validation messages, flash messages, or Blade templates
  - Would only be needed if you wanted to translate things like estimate area names, deal stage names, etc. in the database
- **Source**: https://github.com/spatie/laravel-translatable

### Option 3: mcamara/laravel-localization (v2.3.0)
- **Version**: 2.3.0 (February 26, 2025)
- **Laravel 12 Compatible**: YES (`^10.0|^11.0|^12.0`, PHP ^8.2)
- **Livewire 4 Compatible**: PROBLEMATIC -- known 404 issues with `/livewire/update` endpoint
- **GitHub Stars**: 3.5k
- **Last Commit**: February 2025
- **Maintenance Status**: Active
- **What It Does**: URL-prefix-based localization (`/es/dashboard`, `/en/dashboard`). Auto-detects browser language. Provides translatable routes. Language selector helpers.
- **Pros**:
  - Mature package with large community
  - Auto-detects browser language preference
  - URL-based locale (`/es/estimates`) is SEO-friendly for public pages
  - Built-in language selector helpers
- **Cons**:
  - KNOWN LIVEWIRE CONFLICT: Livewire's `/livewire/update` endpoint gets 404 errors on non-default locales because the URL prefix is not applied to Livewire's internal routes
  - Workaround exists (`Livewire::setUpdateRoute()` inside localized route group) but adds complexity
  - Route caching is broken with Livewire 3+ (and likely 4)
  - URL-prefix approach is unnecessary for a CRM app (not a public website needing SEO)
  - Wraps ALL routes in localized groups, which conflicts with the multi-guard routing setup in `bootstrap/app.php`
  - Would require restructuring agent, customer, crew route groups to nest inside locale prefix
  - Overkill for a 2-language CRM
- **Source**: https://github.com/mcamara/laravel-localization

### Option 4: laravel-lang/common + laravel-lang/lang (v15.28.3)
- **Version**: laravel-lang/common v6.7.2, laravel-lang/lang v15.28.3 (February 8, 2026)
- **Laravel 12 Compatible**: YES (conflicts with Laravel <11.0.7, so 12 is supported)
- **Livewire 4 Compatible**: YES (provides translation files only, no routing changes)
- **GitHub Stars**: 7.8k (laravel-lang/lang)
- **Last Commit**: February 2026
- **Maintenance Status**: Very active (683 contributors, 17,829 commits)
- **What It Does**: Provides pre-translated validation messages, auth messages, pagination strings, and password reset strings for 128 languages. Covers Laravel Framework, Fortify, Breeze, Jetstream, Cashier, Nova, UI.
- **Pros**:
  - Instant Spanish translations for ALL Laravel framework strings (validation, auth, pagination, passwords)
  - One command to add: `php artisan lang:add es`
  - Covers Fortify strings (which this app uses for auth)
  - Massively maintained with 128 languages
  - Does not modify routing -- just provides lang files
  - Perfect complement to Laravel's built-in localization
- **Cons**:
  - Only translates Laravel framework strings -- you still need to translate your own app strings manually
  - Dev dependency (translations are published to `lang/` directory)
  - Does not provide a language switcher component
- **Source**: https://github.com/Laravel-Lang/lang

### Option 5: joedixon/laravel-translation (v2.2.0)
- **Version**: 2.2.0 (April 26, 2023)
- **Laravel 12 Compatible**: NO (supports Laravel 8.x and 9.x only, confirmed by Laravel Shift)
- **Livewire 4 Compatible**: Unknown (not compatible with Laravel 12)
- **GitHub Stars**: 717
- **Last Commit**: 2023
- **Maintenance Status**: Stale/Abandoned (no updates in ~3 years, no Laravel 10+ support)
- **What It Does**: Translation management with a web UI. Database or file driver for translations. Browse/edit translations in browser.
- **Pros**:
  - Had a nice web UI for managing translations
  - Database driver for multi-server setups
- **Cons**:
  - NOT COMPATIBLE with Laravel 12 (or even 10/11)
  - Appears abandoned -- no releases since April 2023
  - Cannot be used for this project
- **Source**: https://github.com/joedixon/laravel-translation

### Option 6: spatie/laravel-translation-loader (v2.8.2)
- **Version**: 2.8.2 (early 2025)
- **Laravel 12 Compatible**: YES (`illuminate/translation: ^12.0`)
- **Livewire 4 Compatible**: YES
- **GitHub Stars**: ~1k
- **Last Commit**: 2025
- **Maintenance Status**: Active
- **What It Does**: Stores translations in the database alongside file-based translations. Database translations override file translations. Uses a `LanguageLine` model.
- **Pros**:
  - Database translations override file translations (useful for admin-editable text)
  - Works with Laravel's native `__()` helper
  - Spatie quality
- **Cons**:
  - Requires a database migration and model
  - No translation management UI included (just a model)
  - Adds complexity for a 2-language app that could use simple JSON files
  - Overkill unless you need non-developers to edit translations via a UI you build
- **Source**: https://github.com/spatie/laravel-translation-loader

---

## Recommendation

**Winner**: Laravel Built-in Localization + laravel-lang/common (combined approach)

**Reason**: For a painting company CRM with 2 languages (English + Spanish), the built-in Laravel localization is the right foundation. Here is why:

1. **No Livewire conflicts**: Session-based locale switching has zero interaction with Livewire's internal routing. The `mcamara/laravel-localization` package has documented, ongoing issues with Livewire because of its URL-prefix approach.

2. **No routing restructuring needed**: The app has a complex multi-guard routing setup (`/agent/*`, `/customer/*`, `/crew/*`). URL-prefix localization would require nesting all of these inside locale prefixes (`/es/agent/*`), which is both unnecessary and risky.

3. **laravel-lang/common gives you instant Spanish Fortify translations**: Since the app uses Fortify for authentication, this package provides all login, registration, password reset, and validation messages in Spanish out of the box.

4. **Simple JSON files for app-specific strings**: Custom strings like "Create Estimate", "Add Room", "Send Proposal" go into `lang/es.json`. This is easy to maintain and version-controlled.

5. **Language switcher is trivial to build**: A small Livewire component that sets `session('locale')` and a middleware that reads it. No package needed for this.

6. **Future-proof**: If you later need model translations (e.g., estimate area names in Spanish), you can add `spatie/laravel-translatable` as a targeted addition without changing the UI translation approach.

---

## Installation

### Step 1: Install laravel-lang/common for framework translations

```bash
composer require --dev laravel-lang/common
php artisan lang:publish
php artisan lang:add es
```

This creates `lang/es/` directory with Spanish translations for:
- `validation.php` (all validation messages)
- `auth.php` (authentication messages)
- `pagination.php` (pagination text)
- `passwords.php` (password reset messages)

### Step 2: Create JSON file for app-specific translations

Create `lang/es.json`:

```json
{
    "Dashboard": "Panel",
    "Estimates": "Presupuestos",
    "Create Estimate": "Crear Presupuesto",
    "Customers": "Clientes",
    "Jobs": "Trabajos",
    "Settings": "Configuracion",
    "Save": "Guardar",
    "Cancel": "Cancelar",
    "Delete": "Eliminar",
    "Search": "Buscar",
    "Welcome": "Bienvenido",
    "Logout": "Cerrar sesion"
}
```

### Step 3: Create a SetLocale middleware

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = session('locale', config('app.locale'));

        if (in_array($locale, ['en', 'es'])) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
```

Register it in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->web(append: [
        \App\Http\Middleware\SetLocale::class,
    ]);
    // ... existing middleware aliases
});
```

### Step 4: Create a language switcher route

```php
// routes/web.php
Route::get('/locale/{locale}', function (string $locale) {
    if (in_array($locale, ['en', 'es'])) {
        session(['locale' => $locale]);
    }
    return redirect()->back();
})->name('locale.switch');
```

### Step 5: Add language switcher to layouts

Add a small dropdown/toggle to each layout file (app, agent, customer, crew):

```blade
<div>
    <a href="{{ route('locale.switch', 'en') }}"
       class="{{ app()->getLocale() === 'en' ? 'font-bold' : '' }}">
        EN
    </a>
    <span>|</span>
    <a href="{{ route('locale.switch', 'es') }}"
       class="{{ app()->getLocale() === 'es' ? 'font-bold' : '' }}">
        ES
    </a>
</div>
```

### Step 6: Use `__()` in Blade templates

Replace hardcoded English strings:

```blade
{{-- Before --}}
<h1>Dashboard</h1>

{{-- After --}}
<h1>{{ __('Dashboard') }}</h1>
```

---

## Integration Notes for This Project

### Livewire 4 SFC Components
The `__()` helper works inside both the PHP section and Blade template of Livewire SFC components:

```blade
<?php
use Livewire\Component;

new class extends Component {
    public function save(): void
    {
        session()->flash('success', __('Estimate saved successfully'));
    }
};
?>

<div>
    <h2>{{ __('Create Estimate') }}</h2>
    <button wire:click="save">{{ __('Save') }}</button>
</div>
```

### Multi-Guard Compatibility
The `SetLocale` middleware appended to the `web` middleware group will apply to ALL route groups (web, agent, customer, crew) since they all use `Route::middleware('web')` in `bootstrap/app.php`. No per-guard configuration needed.

### Fortify Integration
Since `laravel-lang/common` includes Fortify translations, login/register/password-reset pages for all user types (internal, agent, customer, crew) will automatically show Spanish validation messages when the locale is `es`.

### Public Proposal Pages
Public estimate proposal pages (`/proposal/{token}`) will also respect the locale since they use the `web` middleware group. Spanish-speaking customers will see the proposal in Spanish if they switch the language.

### Translation Scope Estimate
For a CRM of this size, expect to translate approximately:
- 50-100 navigation/menu strings
- 100-200 form labels and buttons
- 50-100 flash messages and notifications
- 30-50 status labels (estimate stages, job stages, deal stages)
- Framework strings are covered by laravel-lang/common (~500+ strings)

Total custom strings to translate: approximately 250-450 strings in `lang/es.json`.

### Optional Future Addition: spatie/laravel-translatable
If you later need database content in Spanish (e.g., room template names "Living Room" -> "Sala de estar", or estimate area names), add `spatie/laravel-translatable` at that point. It is fully compatible with Laravel 12 and can be layered on top of this approach without conflict.
