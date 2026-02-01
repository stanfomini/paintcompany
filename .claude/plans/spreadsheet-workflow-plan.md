# Simple Estimate Workflow Plan

## Goal
**Faster than the spreadsheet.** Salesman picks rooms, gets prices. Done.

---

## The User Experience

```
┌─────────────────────────────────────────────────────────────┐
│  NEW ESTIMATE                                               │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  Project Type: (●) Interior  ( ) Exterior                   │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ + Living Room    + Bedroom    + Bathroom            │   │
│  │ + Kitchen        + Dining     + Hallway             │   │
│  │ + Office         + Laundry    + Closet              │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  YOUR ROOMS:                                                │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ Living Room     [Medium ▼]  200 sqft     $827    ✕  │   │
│  │ Bedroom         [Medium ▼]  150 sqft     $612    ✕  │   │
│  │ Bedroom         [Small ▼]   120 sqft     $498    ✕  │   │
│  │ Bathroom        [Standard▼]  80 sqft     $425    ✕  │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  ───────────────────────────────────────────────────────   │
│                              SUBTOTAL:    $2,362           │
│                              TAX (8%):      $189           │
│                              TOTAL:       $2,551           │
│                                                             │
│                    [Save Draft]  [Send to Customer]        │
└─────────────────────────────────────────────────────────────┘
```

**That's it.** Click rooms, pick sizes, send estimate.

---

## What Happens Behind the Scenes

When user clicks "+ Living Room (Medium)":

1. **System looks up "Living Room Medium" template**
   - Walls: 200 sqft, Textured same color
   - Ceiling: 180 sqft, Textured same color
   - Baseboards: 55 lnft, Same color
   - Doors: 1, Both sides
   - Trim: Included

2. **System calculates each surface using PBP formula**
   - Uses production rates from `production_rates` table
   - Uses company settings (wage, margins, etc.)
   - All automatic, user never sees this

3. **System shows ONE price: $827**

4. **If user wants to customize** (optional):
   - Click to expand room
   - See individual surfaces
   - Adjust sqft, add/remove surfaces
   - Price updates live

---

## Template Structure

Each room template defines:
```php
[
    'name' => 'Living Room',
    'scope_type' => 'interior',
    'sizes' => [
        'small' => ['sqft' => 150, 'label' => 'Small (150 sqft)'],
        'medium' => ['sqft' => 200, 'label' => 'Medium (200 sqft)'],
        'large' => ['sqft' => 300, 'label' => 'Large (300 sqft)'],
    ],
    'surfaces' => [
        'walls' => ['included' => true, 'sqft_ratio' => 1.0, 'rate_id' => 1],
        'ceiling' => ['included' => true, 'sqft_ratio' => 0.9, 'rate_id' => 5],
        'baseboards' => ['included' => true, 'lnft_per_100sqft' => 28, 'rate_id' => 10],
        'doors' => ['included' => true, 'count' => 1, 'rate_id' => 15],
    ],
]
```

When user selects "Medium (200 sqft)":
- Walls = 200 × 1.0 = 200 sqft
- Ceiling = 200 × 0.9 = 180 sqft
- Baseboards = (200/100) × 28 = 56 lnft
- Doors = 1

All calculated automatically from the single "200 sqft" input.

---

## Room Templates to Create

### Interior Rooms
| Room | Small | Medium | Large | Surfaces |
|------|-------|--------|-------|----------|
| Living Room | 150 | 200 | 300 | Walls, Ceiling, Baseboards, 1 Door |
| Bedroom | 100 | 150 | 200 | Walls, Ceiling, Baseboards, 1 Door, 1 Closet |
| Master Bedroom | 150 | 200 | 300 | Walls, Ceiling, Baseboards, 2 Doors, Walk-in Closet |
| Bathroom | 50 | 80 | 120 | Walls, Ceiling, Baseboards, 1 Door |
| Kitchen | 100 | 150 | 200 | Walls, Ceiling, Baseboards (no doors typical) |
| Dining Room | 100 | 150 | 200 | Walls, Ceiling, Baseboards, 1 Door |
| Hallway | 60 | 100 | 150 | Walls, Ceiling, Baseboards |
| Office | 80 | 120 | 180 | Walls, Ceiling, Baseboards, 1 Door |
| Laundry | 40 | 60 | 80 | Walls, Ceiling, Baseboards, 1 Door |

### Add-ons (flat price or per-unit)
| Item | Pricing |
|------|---------|
| Closet (walk-in) | Fixed: $150 |
| Closet (standard) | Fixed: $75 |
| Extra Door | Per unit: hours × rate |
| Extra Window | Per unit: hours × rate |
| Accent Wall | Per sqft |
| Cabinet Painting | Per cabinet |

### Exterior (separate scope)
| Area | Small | Medium | Large |
|------|-------|--------|-------|
| House Exterior | 1500 | 2500 | 4000 sqft |
| Garage | 400 | 600 | 800 sqft |
| Deck | 150 | 250 | 400 sqft |
| Fence | 100 | 200 | 400 lnft |

---

## Implementation Tasks

### Task 1: Create Room Template Seeder
Create templates with size options and surface ratios.
- All interior room types
- All exterior area types
- Add-ons (closets, doors, etc.)

### Task 2: Build Simple Room Picker UI
- Grid of room type buttons
- Size dropdown per room
- Live price calculation
- Remove button

### Task 3: Connect to PBP Calculator
- When room added, create surfaces in background
- Calculate price using existing PBP service
- Show total only (hide complexity)

### Task 4: Add "Expand to Customize" Option
- Optional drill-down to see surfaces
- Edit individual surface sqft
- Add/remove surfaces
- Price updates live

### Task 5: Test with Real Numbers
- Verify prices match spreadsheet
- Test all room types
- Test size variations

---

## What We're NOT Building

❌ Length × Width × Height inputs for every surface
❌ Production rate selection by user
❌ Substrate selection by user
❌ Complex multi-step forms
❌ Anything slower than the spreadsheet

---

## Success Criteria

1. ✅ Add a room in ONE click
2. ✅ Adjust size with ONE dropdown
3. ✅ See price INSTANTLY
4. ✅ Complete 3-bedroom house estimate in < 2 minutes
5. ✅ Prices match spreadsheet calculations
6. ✅ Optional customization available but not required

---

## Files to Modify

| File | Change |
|------|--------|
| `database/seeders/RoomTemplateSeeder.php` | Create room templates with sizes and surface ratios |
| `app/Livewire/Estimates/Builder.php` | Simple room picker, size dropdown, auto-calculate |
| `resources/views/livewire/estimates/builder.blade.php` | Clean grid UI for room selection |
| `app/Services/RoomPricingService.php` | Create surfaces from template + calculate total |
| `tests/Feature/RoomPricingTest.php` | Verify calculations match spreadsheet |

---

## Reference: Spreadsheet Production Rates

**Source**: https://docs.google.com/spreadsheets/d/13jhzT7ulHZVIwUBGfKx2Z-qwaT3IirDrPLZ8AdcuOCw/

### Global Settings (Inputs Tab)
- Wage Rate: $21.00/hr
- Payroll Burden: 1.0 (multiplier)
- Net Profit Margin: 30%
- Overhead: 10%
- Markup: 50%
- Cleanup ratio: 16 (1 hr cleanup per 16 labor hrs)
- Sundries: $3.30/hr
- Paint coverage: 400 sqft/gallon

### Wall Production Rates (sqft/hr)
| Type | Rate |
|------|------|
| Textured Walls same color | 265 |
| Textured Walls change color | 150 |
| Smooth Walls same color | 210 |
| Smooth Walls change color | 120 |
| Prime+paint new textured drywall | 95.78 |
| Prime+paint new smooth drywall | 76.36 |

### Ceiling Production Rates (sqft/hr)
| Type | Rate |
|------|------|
| Textured Ceiling same color | 175 |
| Textured Ceiling change color | 100 |
| Smooth Ceiling same color | 100 |
| Smooth Ceiling change color | 50 |
| Popcorn Ceiling same color | 100 |
| Popcorn Ceiling change color | 65 |
| Vaulted (>18ft) same color | 75-100 |
| Vaulted change color | 40-50 |

### Baseboard Production Rates (lnft/hr)
| Type | Rate |
|------|------|
| Same color | 40 |
| Change color | 30 |
| Stain to paint | 12 |
| New (never painted) | 18.75 |
| Prime & 2 coats + caulk | 21.43 |

### Door Rates (hours per door)
| Type | Hours |
|------|-------|
| 1 side | 1.0 |
| Both sides | 2.0 |
| Closet 1 side | 1.0 |
| Closet both sides | 4.0 |
| Same color 1 side | 0.75 |
| Same color both sides | 1.5 |
| Commercial steel 1 side | 1.38 |
| Commercial steel both sides | 2.75 |

### Prep Work Rates (from Inputs Tab)
| Task | Rate |
|------|------|
| Masking flooring | 250 sqft/hr |
| Tape line with caulking | 30 lnft/hr |
| Tape line | 40 lnft/hr |
| Prep newly installed baseboards | 50 lnft/hr |
| Smooth walls prime new drywall | 150 sqft/hr |
| Textured prime new drywall | 100 sqft/hr |
| Hand cut line same color | 60 lnft/hr |
| Hand cut line change color | 40 lnft/hr |
| Caulking in baseboards | 75 lnft/hr |

### Price Calculation Formula
```
For sqft/lnft surfaces:
  Labor Hours = Quantity ÷ Production Rate × Coat Multiplier
  Labor Cost = Labor Hours × Wage × Payroll Burden
  Cleanup Hours = Labor Hours ÷ 16
  Cleanup Cost = Cleanup Hours × Wage × Burden
  Sundries = Labor Hours × $3.30
  Material Cost = (Quantity ÷ 400) × Paint Price × Coats

  Base Total = Labor + Cleanup + Sundries + Materials
  Final Price = Base Total × 1.30 (profit) × 1.10 (overhead) × 1.50 (markup)

For doors (count-based):
  Labor Hours = Count × Hours Per Door
  (same formula for costs and margins)
```
