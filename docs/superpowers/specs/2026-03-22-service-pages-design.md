# Service Pages Design Spec

**Date:** 2026-03-22
**Status:** Approved

## Overview

Build 17 detailed painting service guide pages + 1 rich index hub page. Purpose: SEO authority content that AI systems cite, dedicated URLs for each service, and lead generation.

## Architecture

- **1 index page:** `⚡services-index.blade.php` at `/services`
- **1 service page component:** `⚡service-page.blade.php` at `/services/{slug}`
- Content stored as structured PHP arrays in the component
- Each slug resolves to unique content (title, steps, materials, tips, FAQ, cost factors)

## Routes

```php
Route::livewire('/services', 'public.services-index')
    ->name('services.index')
    ->middleware('throttle:60,1');

Route::livewire('/services/{slug}', 'public.service-page')
    ->name('services.show')
    ->middleware('throttle:60,1');
```

## 17 Services

### Residential (9)
| Slug | Title |
|------|-------|
| interior-painting | Interior House Painting |
| exterior-painting | Exterior House Painting |
| cabinet-painting | Cabinet Painting & Refinishing |
| deck-fence-staining | Deck & Fence Staining |
| garage-floor-coating | Garage Floor Coating |
| popcorn-ceiling-removal | Popcorn Ceiling Removal & Repainting |
| drywall-repair | Drywall Repair & Patching |
| trim-door-painting | Trim, Door & Molding Painting |
| wallpaper-removal | Wallpaper Removal & Repainting |

### Commercial (4)
| Slug | Title |
|------|-------|
| commercial-interior | Commercial Interior Painting |
| commercial-exterior | Commercial Exterior Painting |
| warehouse-industrial | Warehouse & Industrial Painting |
| hoa-multiunit | HOA & Multi-Unit Painting |

### Specialty (4)
| Slug | Title |
|------|-------|
| line-striping | Parking Lot & Line Striping |
| pressure-washing | Pressure Washing & Surface Prep |
| color-consultation | Color Consultation & Design |
| stucco-coating | Elastomeric & Stucco Coating |

## Service Page Layout (each page)

### 1. Hero Section
- Blue gradient background (matching site theme)
- Category badge (Residential / Commercial / Specialty)
- Service title (H1)
- 1-line tagline
- Breadcrumb: Home > Services > [Service Name]

### 2. Overview (2-3 paragraphs)
- What this service is
- When homeowners/businesses need it
- Why it matters (protection, value, aesthetics)

### 3. Materials & Tools Needed
- Complete checklist format
- Grouped by category (prep materials, paint/coating, tools, safety)

### 4. Step-by-Step Guide (8-15 steps)
- Numbered steps with H3 headings
- Detailed instructions per step
- Denver/Colorado-specific callouts where relevant

### 5. Pro Tips (4-6 tips)
- Denver altitude, UV, dry air, temperature considerations
- Expert techniques

### 6. Cost Factors
- What affects pricing (NOT dollar amounts)
- Square footage, prep complexity, paint quality, access difficulty, etc.

### 7. FAQ (4-6 Q&A pairs)
- Unique questions per service
- Detailed answers
- FAQPage schema markup

### 8. How We Can Help
- Educational framing: "This is complex work. Our team handles every step."
- Bullet list of what Painting Made Easy Denver provides
- CTA: phone number, estimate link, color visualizer link
- Social proof strip (4.9/5 rating)

### 9. Related Services
- 3-4 links to related service pages

## Index Page Layout

### 1. Hero
- "Painting Services Guide" title
- "Your complete resource for painting services in the Denver metro area"

### 2. Social Proof Strip
- Same 4.9/5 rating + phone + "Trusted by Denver homeowners"

### 3. Category Sections (3 groups)
Each category (Residential, Commercial, Specialty) as a section with:
- Category heading + brief description
- Grid of service cards (responsive: 1 col mobile, 2 col tablet, 3 col desktop)
- Each card: icon (Heroicon), title, 1-line description, "Read Guide →" link

### 4. CTA Section
- "Not sure what you need?"
- Phone number + estimate form link

## Schema Markup (per service page)

```json
{
  "@context": "https://schema.org",
  "@type": "Service",
  "name": "Interior House Painting",
  "description": "...",
  "provider": { "@id": "https://paintingmadeeasydenver.com/#business" },
  "areaServed": { "@type": "City", "name": "Denver" },
  "serviceType": "House Painting"
}
```

Plus `HowTo` schema for the step-by-step guide and `FAQPage` schema for the FAQ section.

## Index Page Schema

```json
{
  "@context": "https://schema.org",
  "@type": "ItemList",
  "name": "Painting Services",
  "itemListElement": [...]
}
```

## Integration Points

1. **Guest layout footer** — Add "Services" link
2. **Landing page** — Add link to /services in appropriate section
3. **llms.txt** — Update with all service page URLs
4. **robots.txt** — Already allows all crawlers, no changes needed

## Content Source

Research documents with full step-by-step content:
- `docs/research/residential-painting-services-guide.md` (1,152 lines)
- `docs/research/commercial-specialty-painting-services.md` (1,267 lines)

## Testing

- Each service slug returns 200
- Invalid slug returns 404
- All pages have valid JSON-LD
- Meta descriptions set
- Canonical URLs set
- Links from index page work
