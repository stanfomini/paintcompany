# Customer Dashboard Color Redesign - "Unified Slate"

## Problem

The customer dashboard has 4 competing color identities:
- **Amber** - Schedule section (borders, backgrounds, buttons)
- **Blue/Indigo gradient** - Estimate cards
- **Green** - "Tell Us About Your Home" CTA
- **Emerald-to-Amber gradient** - Job Progress

Each section screams a different color, making the dashboard look disjointed rather than professional. A painting company customer portal should look refined and well-designed.

## Solution: Unified Slate

Replace all section-specific colored backgrounds, gradients, and borders with **one consistent card style** and **one accent color** (`sky`) for all interactive elements.

## Color System

| Role | Light Mode | Dark Mode | Usage |
|------|-----------|-----------|-------|
| Primary CTA | `sky-600` | `sky-500` | All buttons |
| Primary CTA hover | `sky-700` | `sky-400` | Button hover states |
| Card background | `white` (flux:card) | `zinc-800` | All section cards |
| Card border | `zinc-200` (flux:card) | `zinc-700` | Consistent borders |
| Section icon bg | `sky-100` | `sky-900/50` | Circular icon containers |
| Section icon color | `sky-600` | `sky-400` | Icon foreground |
| Status: completed | `green-500` | `green-400` | Timeline only |
| Status: active | `blue-500` | `blue-400` | Timeline only |
| Status: attention | `amber-500` | `amber-400` | Timeline only |
| Price text | `zinc-900` | `zinc-100` | Large price numbers |

## Changes Per Section

### 1. Schedule Card
- **Remove:** `border-2 border-amber-300`, `bg-amber-50`, `bg-amber-400` icon circle, `bg-amber-500` button
- **Replace with:** `flux:card`, `bg-sky-100 dark:bg-sky-900/50` icon circle, `bg-sky-600` button

### 2. Job Progress Card
- **Remove:** `bg-gradient-to-br from-emerald-600 to-amber-600`, white text overlay
- **Replace with:** `flux:card`, progress bar uses `bg-sky-500` (filled) / `bg-zinc-200` (empty), normal text

### 3. Estimate CTA Card
- **Remove:** `bg-gradient-to-br from-blue-600 to-indigo-700`, white text overlay
- **Replace with:** `flux:card`, price in large `text-zinc-900`, `bg-sky-600` button

### 4. "Tell Us About Your Home" CTA
- **Remove:** `border-green-300`, `bg-green-50`, `bg-green-600` button, green text
- **Replace with:** Card with `border-dashed border-zinc-300 dark:border-zinc-600`, `bg-sky-600` button

### 5. Status Timeline
- **No changes** - keep green/blue/amber semantic status indicators

## Scope

- **File:** `resources/views/components/customer/⚡dashboard.blade.php`
- **No backend changes**
- **No new dependencies**
- **No CSS theme changes needed** (sky is a standard Tailwind color)
