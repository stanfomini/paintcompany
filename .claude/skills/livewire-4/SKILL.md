---
name: livewire-4
description: Livewire 4 component patterns, wire:model modifiers, loading states, validation. Triggered when creating or modifying Livewire components.
---

# Livewire 4 Skill

## Core Principles

1. **Use `php artisan make:livewire` to create components**
2. **Follow Laravel Boost guidelines for Livewire**
3. **ALWAYS validate and authorize**

## Component Creation

```bash
php artisan make:livewire ComponentName
# Creates:
# - app/Livewire/ComponentName.php
# - resources/views/livewire/component-name.blade.php
```

## Standard Component Pattern

```php
<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\Attributes\Validate;

class ExampleComponent extends Component
{
    #[Validate('required|string|max:255')]
    public string $name = '';

    public function save(): void
    {
        $this->validate();
        // Business logic
        $this->dispatch('saved');
    }

    public function render()
    {
        return view('livewire.example-component');
    }
}
```

## Wire Model Modifiers

| Modifier | Use When |
|----------|----------|
| `wire:model` | Default, updates on change |
| `wire:model.blur` | Update when field loses focus (forms) |
| `wire:model.live` | Real-time updates (search) |
| `wire:model.live.debounce.300ms` | Debounced live updates |

## Loading States

```blade
<button wire:click="save" wire:loading.attr="disabled">
    <span wire:loading.remove>Save</span>
    <span wire:loading>Saving...</span>
</button>
```

## Validation Patterns

```php
// Real-time validation
public function updated(string $property): void
{
    $this->validateOnly($property);
}

// Custom error messages
protected function messages(): array
{
    return [
        'email.required' => 'Please enter your email.',
    ];
}
```
