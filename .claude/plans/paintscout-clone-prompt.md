# PaintScout Clone Implementation - Planning Prompt

## Context

Use this prompt in a new Claude Code session to plan and build a PaintScout-like estimating system for the Apex Negotiations painting company CRM.

---

## The Prompt

```
I need you to help me build a professional estimating and proposal system modeled after PaintScout (paintscout.com) for our painting company CRM. This will replace/enhance our current basic estimates functionality.

## Current State
- We have a Laravel 12 + Livewire 4 + Flux UI application
- Basic estimates exist with line items, but lack the sophisticated features of PaintScout
- See `.claude/plans/master-plan.md` for full project context
- 758 tests passing - all new features need tests

## What PaintScout Does (Research Summary)

### Core Estimation System
1. **Areas** - Physical spaces (rooms, exteriors) with dimensions (length, width, height)
   - Automatically calculate sqft for walls, ceilings, floors
   - Contain multiple surfaces/substrates (walls, trim, doors, windows, ceilings)

2. **Production Rates** - Pre-configured rates that auto-calculate pricing
   - Based on sqft/hr, lnft/hr, or hrs/item
   - Different rates per coat (1 coat, 2 coats, 3 coats)
   - Categories: Interior walls, exterior siding, trim, cabinets, etc.
   - Formula: (sqft ÷ sqft_per_hour) × hourly_rate + materials

3. **Line Items** - Custom entries not tied to dimensions
   - Flat-fee items (travel, setup, permits)
   - Can show/hide from customer view
   - Reusable templates stored in settings

4. **Options/Add-ons** - Interactive upsells
   - Displayed separately below the estimate total
   - Customers can click "+Add Option" to include them
   - Total updates dynamically when options selected
   - Perfect for upgrades: premium paint, extra coats, accent walls

### Customer-Facing Proposal (Public URL)
1. **Presentation Pages** - Mini-website for each estimate
   - Branded header with logo, colors, team photos
   - Multiple pages/tabs (Quote, About Us, Terms, Gallery)
   - Customizable sections with photos, text, layouts
   - Mobile-responsive design

2. **Interactive Quote View**
   - Customer views via unique signed URL (no login required)
   - See all line items, areas, pricing
   - Options section at bottom for add-ons
   - Real-time total updates when selecting options
   - Print/Download PDF functionality

3. **E-Signature Acceptance**
   - "Accept" button triggers signature modal
   - Draw or type signature
   - Acceptance disclaimer (customizable terms)
   - Timestamp and IP logged
   - Status updates to "Accepted" automatically

4. **Activity Tracking**
   - Know when estimate is viewed
   - Track which options customer toggled
   - See acceptance timestamp
   - Automated follow-up triggers

### Estimate Builder (Internal)
1. **Templates** - Pre-built estimate types
   - Interior Residential, Exterior Residential, Commercial, etc.
   - Pre-populated areas, rates, line items
   - Default options/upgrades per template

2. **Quick Entry**
   - Enter room dimensions → auto-calculate surfaces
   - Select surfaces to include (walls, ceiling, trim, etc.)
   - Apply production rates → auto-calculate price
   - Add line items for extras

3. **Paint Calculator**
   - Auto-calculate paint needed based on sqft and coverage
   - Track by area/surface

4. **Photo Attachments**
   - Attach photos to specific areas or line items
   - Customer sees exactly what's included

## Features to Build (Priority Order)

### Phase 1: Production Rates & Areas System
- [ ] Production rates CRUD (categories, surfaces, rates per coat)
- [ ] Areas system (rooms with dimensions)
- [ ] Auto-calculation engine (dimensions → sqft → hours → price)
- [ ] Migrate existing estimates to new system

### Phase 2: Enhanced Estimate Builder
- [ ] Area-based estimate builder (add rooms, select surfaces)
- [ ] Line items library (reusable custom items)
- [ ] Options/add-ons system (separate from base price)
- [ ] Estimate templates (pre-configured for project types)
- [ ] Photo attachments per area/line item

### Phase 3: Customer-Facing Proposal
- [ ] Public URL generation (signed, no auth required)
- [ ] Branded presentation template
- [ ] Interactive options (customer can add/remove, see total update)
- [ ] Mobile-responsive proposal view
- [ ] Print/PDF download

### Phase 4: E-Signature & Acceptance
- [ ] Signature capture (draw or type)
- [ ] Acceptance workflow with terms/disclaimer
- [ ] Timestamp and IP logging
- [ ] Status updates and notifications
- [ ] Activity tracking (viewed, accepted, declined)

### Phase 5: Advanced Features
- [ ] Estimate templates library
- [ ] Paint calculator
- [ ] Multiple presentation pages (About, Gallery, Terms)
- [ ] Customer chat on estimates
- [ ] Automated follow-up reminders

## Database Schema Considerations

You'll need to design tables for:
- `production_rate_categories` - Group rates (Interior, Exterior, etc.)
- `production_rates` - Individual rates with coat variations
- `estimate_areas` - Rooms/spaces with dimensions
- `estimate_area_surfaces` - Surfaces within areas (links to rates)
- `estimate_options` - Add-on items customers can select
- `estimate_signatures` - E-signature data
- `estimate_views` - Track when/who viewed estimates
- `estimate_templates` - Reusable estimate configurations
- `line_item_library` - Saved reusable line items

## Technical Requirements
- All Livewire 4 components with proper event dispatching
- Real-time total calculations (no page refresh)
- Signed URLs for public estimate viewing (Laravel's URL::signedRoute)
- PDF generation for estimates (existing DomPDF setup)
- Mobile-first responsive design
- Full test coverage (Pest)

## Questions to Answer in Planning
1. How should we handle the transition from current simple estimates?
2. Should production rates be per-user or company-wide?
3. What signature library to use? (signature_pad.js recommended)
4. How to structure the public proposal pages?
5. What's the minimum viable feature set for Phase 1?

Please start by:
1. Reading the current estimate-related code (models, migrations, Livewire components)
2. Understanding the existing database schema
3. Proposing a detailed implementation plan with database migrations
4. Identifying what can be reused vs. needs rebuilding
```

---

## Key PaintScout Sources for Reference

- [PaintScout Main Site](https://www.paintscout.com)
- [Painting Estimates Features](https://www.paintscout.com/painting-estimates)
- [How Customers View & Accept Estimates](https://help.paintscout.com/en/articles/3206822-how-customers-view-accept-estimates)
- [Working With Options](https://help.paintscout.com/en/articles/3207145-working-with-options)
- [Presentations Overview](https://help.paintscout.com/en/articles/4649624-presentations-overview)
- [Production Rates Overview](https://help.paintscout.com/en/articles/3206998-production-rates-section-overview)
- [How Production Rates are Calculated](https://help.paintscout.com/en/articles/5524358-how-production-rates-are-calculated)
- [Areas vs Line Items](https://help.paintscout.com/en/articles/3207625-what-s-the-difference-between-an-area-and-a-line-item)
- [Line Items Overview](https://help.paintscout.com/en/articles/3207003-line-items-overview)

---

## PaintScout Feature Summary

| Feature | Description |
|---------|-------------|
| **Production Rates** | Pre-set $/sqft or $/hr rates that auto-calculate from dimensions |
| **Areas** | Rooms/spaces with dimensions (L×W×H) containing multiple surfaces |
| **Line Items** | Custom flat-fee items not tied to dimensions |
| **Options** | Add-ons displayed separately, customer can toggle on/off |
| **Presentations** | Multi-page branded proposal (mini-website) |
| **Public URL** | Signed link customers access without login |
| **E-Signature** | Draw/type signature with acceptance terms |
| **Activity Tracking** | View/accept timestamps, follow-up triggers |
| **Templates** | Pre-built estimate configurations by project type |
| **Paint Calculator** | Auto-calculate gallons needed per surface |
