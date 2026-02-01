# PBP Implementation Verification Prompt

Use this prompt to verify that all items from the PBP (Painting Business Pricing) spreadsheets have been implemented correctly.

---

## Verification Checklist

### Interior Items Sheet

#### Substrates/Surface Conditions ✓
- [ ] Previously Painted - Good Condition (prep_multiplier: 1.0)
- [ ] Previously Painted - Fair Condition (prep_multiplier: 1.15)
- [ ] New Drywall (requires prime, prep_multiplier: 1.3)
- [ ] Repaired Drywall (requires prime, prep_multiplier: 1.4)
- [ ] Peeling/Damaged Paint (requires prime+sand+scrape, prep_multiplier: 2.0)
- [ ] Smoke/Water Damaged (requires full prep, prep_multiplier: 2.5)
- [ ] Wood Trim - Stained (requires prime, prep_multiplier: 1.5)
- [ ] Wood Trim - Painted (prep_multiplier: 1.2)
- [ ] Bare Wood (requires prime, prep_multiplier: 1.4)

#### Nested Areas/Fixed Price Items ✓
- [ ] Small Closet - $95 (up to 24 sq ft)
- [ ] Medium Closet - $165 (25-50 sq ft)
- [ ] Large Closet - $245 (50+ sq ft)
- [ ] Closet w/ Shelving - $195
- [ ] Half Bath - $175
- [ ] Full Bath Standard - $285
- [ ] Full Bath Large - $385
- [ ] Laundry Room Small - $145
- [ ] Laundry Room Standard - $225
- [ ] Pantry - $125
- [ ] Mudroom Small - $165
- [ ] Mudroom Large - $275
- [ ] Stairwell 2-Story - $325
- [ ] Entry Foyer Small - $145
- [ ] Entry Foyer 2-Story - $475

#### Interior Pricing Parameters ✓
- [ ] Base Hourly Wage: $21/hr → Verify in CompanySetting (or adjustable)
- [ ] Profit Margin: 30%
- [ ] Overhead: 10%
- [ ] Markup: 50%
- [ ] Payroll Burden: 35% (1.35 multiplier)

### Exterior Items Sheet

#### Exterior Substrates ✓
- [ ] Previously Painted Good (requires wash, prep_multiplier: 1.1)
- [ ] Previously Painted Fair (requires wash+scrape, prep_multiplier: 1.35)
- [ ] Peeling/Weathered (requires full prep, prep_multiplier: 2.25)
- [ ] Wood Siding Normal (requires wash, prep_multiplier: 1.2)
- [ ] Wood Siding Weathered (requires full prep, prep_multiplier: 1.8)
- [ ] Cedar Shake (requires wash, paint_multiplier: 1.3, material_multiplier: 1.5)
- [ ] Stucco Normal (requires wash, paint_multiplier: 1.2, material_multiplier: 1.4)
- [ ] Stucco Damaged (requires wash+prime, prep_multiplier: 1.6)
- [ ] Brick (requires wash+prime, paint_multiplier: 1.35, material_multiplier: 1.6)
- [ ] Concrete/Block (requires wash+prime, paint_multiplier: 1.25)
- [ ] Metal - Painted (requires sand, prep_multiplier: 1.2)
- [ ] Metal - Bare/Rusty (requires full prep, prep_multiplier: 2.0)

#### Exterior Pricing Parameters ✓
- [ ] Base Hourly Wage: $27/hr (different from interior!)
- [ ] Profit Margin: 30%
- [ ] Overhead: 12%
- [ ] Markup: 41%

### Paint Catalog ✓

#### Sherwin-Williams
- [ ] Duration Home - $79.99/gal, 400 sqft coverage
- [ ] Duration Home Matte - $79.99/gal
- [ ] Cashmere - $69.99/gal
- [ ] SuperPaint - $59.99/gal
- [ ] ProMar 200 - $42.99/gal, 350 sqft coverage
- [ ] ProMar 400 - $32.99/gal
- [ ] ProMar Ceiling Paint - $34.99/gal
- [ ] CHB Ceiling Paint - $28.99/gal
- [ ] ProClassic Alkyd Trim - $74.99/gal
- [ ] ProClassic Waterborne Trim - $69.99/gal
- [ ] Emerald Urethane Trim - $89.99/gal
- [ ] Duration Exterior - $89.99/gal
- [ ] SuperPaint Exterior - $69.99/gal
- [ ] A-100 Exterior - $49.99/gal
- [ ] PrepRite ProBlock Primer - $34.99/gal
- [ ] Extreme Bond Primer - $44.99/gal
- [ ] Exterior Oil Primer - $54.99/gal

#### Benjamin Moore
- [ ] Regal Select - $74.99/gal
- [ ] Aura - $89.99/gal
- [ ] Ben - $49.99/gal
- [ ] Advance Trim - $79.99/gal
- [ ] Aura Exterior - $89.99/gal
- [ ] Ultra Spec Exterior - $54.99/gal

#### Behr
- [ ] Marquee - $54.99/gal (one-coat)
- [ ] Ultra - $44.99/gal
- [ ] Premium Plus - $34.99/gal
- [ ] Alkyd Semi-Gloss Trim - $44.99/gal

### Calculation Formula ✓

The pricing formula should be:
```
1. Hours = sqft / production_rate
2. Adjusted Hours = Hours × prep_multiplier × paint_multiplier × coat_multiplier
3. Labor Cost = Adjusted Hours × (base_wage × payroll_burden)
4. Material Cost = gallons_needed × price_per_gallon + recycle_fee
5. Subtotal = Labor Cost + Material Cost
6. With Profit = Subtotal × (1 + profit_percent)
7. With Overhead = With Profit × (1 + overhead_percent)
8. Final Price = With Overhead × (1 + markup_percent)
```

### Coat Multipliers ✓
- [ ] 1 coat: 1.0x
- [ ] 2 coats: 1.8x (not 2.0 - second coat is faster)
- [ ] 3 coats: 2.5x

### Coverage Adjustments ✓
- [ ] Brush/Roll: Base coverage
- [ ] Spray: ~1.4x more coverage (less paint waste)
- [ ] Second Coat: ~1.25-1.35x better coverage

### Miscellaneous Fees ✓
- [ ] Recycle Fee: $0.54/gallon
- [ ] Material Tax Rate: Configurable in CompanySetting

---

## Questions to Verify

1. **Are all substrates from the PBP Items sheets accounted for?**
   - Check `php artisan tinker` → `Substrate::count()` should be 22

2. **Are all nested area templates with correct prices?**
   - Check `NestedAreaTemplate::all()->pluck('fixed_price', 'name')`

3. **Are all paint products with correct pricing?**
   - Check `PaintProduct::count()` should be 25+

4. **Does the formula correctly apply margins in sequence?**
   - Test: $100 base → $130 (profit) → $145.60 (overhead) → $211.12 (markup)
   - `CompanySetting::getInstance()->calculateWithMargins(100)` should return 211.12

5. **Can areas have nested children (closets)?**
   - Check EstimateArea has `parent_area_id` and `childAreas()` relationship

6. **Can areas select substrates?**
   - Check EstimateArea has `substrate_id` and `substrate()` relationship

7. **Can areas have paint selections per surface?**
   - Check `EstimateAreaPaintSelection` model and relationships

---

## Items That May Need Future Implementation

These items from PBP may need additional work:

1. **Separate Interior/Exterior wage rates** - Currently CompanySetting has one rate
   - May need `interior_hourly_wage` and `exterior_hourly_wage` fields

2. **Trim linear foot calculations** - How to calculate trim sqft from linear feet
   - May need `trim_width_inches` settings

3. **Door/Window counts and pricing** - Individual door/window pricing
   - May need separate models for doors and windows

4. **Cabinet pricing** - Kitchen cabinet refinishing has different rates
   - May need cabinet-specific production rates

5. **Deck/Fence pricing** - Exterior specialty surfaces
   - May need deck/fence-specific templates

6. **Pressure washing as separate line item** - Currently just a prep flag
   - May need separate pricing for pressure washing

7. **Caulking and patching materials** - Not included in material costs
   - May need sundries/consumables tracking

---

## Run This Verification

```bash
# Check counts
php artisan tinker --execute="echo 'Substrates: ' . \App\Models\Substrate::count();"
php artisan tinker --execute="echo 'Paint Products: ' . \App\Models\PaintProduct::count();"
php artisan tinker --execute="echo 'Nested Templates: ' . \App\Models\NestedAreaTemplate::count();"

# Test margin calculation
php artisan tinker --execute="echo 'Margin Test: ' . \App\Models\CompanySetting::getInstance()->calculateWithMargins(100);"

# Run all PBP tests
php artisan test --compact tests/Feature/Services/PaintingEstimateCalculatorServiceTest.php
```

If all items check out, the PBP system is complete for Phase 1-6. Phases 7-8 (UI integration) can proceed.
