---
name: pest-testing
description: Pest testing patterns for Laravel 12 and Livewire 4. Feature tests, unit tests, browser tests.
---

# Pest Testing Skill

## Directory Structure

```
tests/
├── Unit/          # Unit tests
├── Feature/       # Feature tests
└── Pest.php       # Configuration
```

## Livewire Component Tests

```php
<?php

use App\Models\User;
use function Pest\Livewire\livewire;

describe('CreatePost Component', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    });

    it('renders successfully', function () {
        livewire('create-post')
            ->assertStatus(200)
            ->assertSee('Create Post');
    });

    it('validates required fields', function () {
        livewire('create-post')
            ->set('title', '')
            ->call('save')
            ->assertHasErrors(['title' => 'required']);
    });

    it('creates post with valid data', function () {
        livewire('create-post')
            ->set('title', 'Test Post')
            ->set('content', str_repeat('Content ', 20))
            ->call('save')
            ->assertHasNoErrors();
    });
});
```

## Running Tests

```bash
# All tests
php artisan test --compact

# Specific test
php artisan test --compact --filter=CreatePostTest

# With coverage
php artisan test --compact --coverage --min=80
```

## Dataset Testing

```php
it('validates email formats', function (string $email, bool $valid) {
    $response = $this->postJson('/api/register', [
        'email' => $email,
        'password' => 'password123',
    ]);

    if ($valid) {
        $response->assertJsonMissingValidationErrors('email');
    } else {
        $response->assertJsonValidationErrors('email');
    }
})->with([
    'valid email' => ['test@example.com', true],
    'missing @' => ['testexample.com', false],
]);
```
