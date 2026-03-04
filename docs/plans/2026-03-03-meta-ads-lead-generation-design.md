# Meta Ads + Lead Generation Strategy Design

**Date:** 2026-03-03
**Budget:** $1,500 over 3 weeks
**Target:** Homeowners, Fort Collins to Castle Rock (Front Range corridor)
**Starting point:** Zero current lead flow

## Strategy Overview

Three parallel workstreams executed over 3 weeks:

1. **System fixes** (Week 1) — SEO, landing page updates, Messenger bot wiring, booking system enablement
2. **Meta Ads** (Weeks 2-3) — Click-to-Messenger + traffic ads targeting Front Range homeowners
3. **Free channels** (Ongoing) — Google Business Profile, organic SEO via area pages

## Budget Allocation

| Channel | Amount | Timing |
|---------|--------|--------|
| Click-to-Messenger ads | ~$900 (60%) | Weeks 2-3 |
| Traffic ads to landing page | ~$450 (30%) | Weeks 2-3 |
| Retargeting visitors who didn't convert | ~$150 (10%) | Week 3 only |

## Week-by-Week Timeline

### Week 1: Foundation ($0 ad spend)

- Fix Messenger bot wiring (3 bugs blocking bot conversations)
- Build 2-3 Messenger bot flows in existing bot editor
- Update landing page hero section (more powerful, booking-focused)
- Add booking CTA bar after hero trust section
- Add "Join the Team" section above footer
- Enable and configure `/book` appointment booking system
- Fix sitemap.xml to include all area pages
- Install Meta Pixel on site
- Set up Google Business Profile (manual)
- Set up Meta Business Suite + ad account (manual)
- Connect Facebook Page to Messenger webhook

### Week 2: Launch ($500 ad spend)

- Launch 3 ad sets (one per Messenger bot flow + one traffic to landing page)
- ~$170 per ad set
- Monitor daily, kill underperformers at 48 hours
- Twilio SMS follow-up for leads that don't book

### Week 3: Scale ($1,000 ad spend)

- Double budget on winning ads
- Create lookalike audiences from Week 2 leads
- Activate retargeting for website visitors who didn't convert
- Continue monitoring and optimizing

## Landing Page Changes

### What stays as-is

Everything below the hero section: pain points, promo video, cleanliness pillar, real-time tracking pillar, preparation pillar, video sections, lead form (`#get-estimate`), agent signup modal, scarcity modal, FAQ section, all login buttons in header, mobile click-to-call.

### Hero Section Redesign

Replace the current hero CTA ("Get Your Free Estimate in 24 Hours" scrolling to form) with a more powerful header that includes:

1. **Stronger headline** — something that conveys the Front Range coverage
2. **Service area highlights** — show the cities we serve (Fort Collins to Castle Rock)
3. **Dual CTA buttons:**
   - Primary: "Book Your Free Estimate" → links to `/book`
   - Secondary: "Or tell us about your project" → scrolls to `#get-estimate` form
4. **Keep:** Trust bar (5-star, licensed, same-day, satisfaction) stays as-is below

### New Section: Booking CTA Bar (after trust bar)

Slim, high-visibility single-line section:

```
Skip the wait — book your free in-home estimate online  [Book a Time →]
```

### New Section: Join the Team (above footer)

```
WE'RE HIRING PAINTERS
Join a team that respects your craft and your time.
[View Open Positions →]  → /careers
```

## SEO Fixes (Free Traffic)

1. **Sitemap:** Add all `/areas/{slug}` pages with `priority=0.8` and `weekly` changefreq
2. **Area page CTAs:** Each area page links to `/book?area={slug}` for pre-filled booking
3. **Internal linking:** Landing page links to area pages in a service areas grid
4. **Google Business Profile:** Set up with correct NAP (name, address, phone), service areas, categories
5. **Schema markup:** Already good (LocalBusiness, Organization, Services, FAQPage)

## Messenger Bot System

### Existing Infrastructure (90% built)

- Facebook webhook controller (POST/GET endpoints working)
- FacebookMessengerService (Graph API v19.0, send/receive)
- MessengerBotFlow + MessengerBotStep + MessengerConversation models
- MessengerBotFlowService (start, advance, complete, timeout)
- MessengerLeadCreationService (auto-creates Customer + Deal)
- Bot flow editor UI in settings
- Notifications (NewMessengerLead, HumanTakeoverNeeded)
- Timeout checking scheduled job

### Bugs to Fix

1. **Bot response routing not wired:** In `FacebookMessengerService::handleMessage()`, when an active `MessengerConversation` exists, the code returns early without calling `MessengerBotFlowService::processResponse()`. The bot starts but never advances past step 1.

2. **Quick reply field mismatch:** Bot editor stores options as `{label, value}` but `sendQuickReply()` expects `{title, payload}`. Quick replies will fail to render.

3. **`is_connected` never set:** The `MessengerSetting.is_connected` field is never written. Should be set to `true` after a successful webhook verification.

### Bot Flows to Build

**Flow 1: "Book or Be Booked"**
```
Greeting: "Hey! Thanks for reaching out about painting. Would you like to..."
Quick Reply: [Book Yourself] [Book It For Me]

If "Book Yourself":
  Text: "Here's our booking page — pick any time that works:"
  Text: {booking_url}
  → Complete conversation

If "Book It For Me":
  Text Question: "What's your name?" → maps to customer.first_name
  Text Question: "What's your email?" → maps to customer.email
  Text Question: "What's your phone number?" → maps to customer.phone
  Text Question: "What city are you in?" → maps to customer.city
  Quick Reply: "Interior, exterior, or both?" [Interior] [Exterior] [Both] → maps to deal.description
  Text: "Thanks {name}! We'll call you within 2 hours to schedule."
  → Complete → Creates Customer + Deal in CRM
```

**Flow 2: "Quick Quote Bot"**
```
Greeting: "Want a ballpark estimate in 60 seconds? Let's go!"
Quick Reply: "Interior or exterior?" [Interior] [Exterior] [Both]
Quick Reply: "How many rooms?" [1-2] [3-5] [6+]
Quick Reply: "House size?" [Small/Apartment] [Medium] [Large]
Text: "Based on similar projects, you're looking at roughly $X-$Y. Want a free exact estimate?"
Quick Reply: [Yes, Book Now] [Have Someone Call Me]

If "Book Now": send booking URL
If "Call Me": collect name + phone → create lead
```

**Flow 3: "Spring Booking" (seasonal urgency)**
```
Greeting: "Spring painting season fills up fast — we're booking now. Want to grab a spot?"
Quick Reply: [Yes, Book My Spot] [Tell Me More]

If "Book": send booking URL
If "Tell Me More":
  Text Question: "What are you thinking about painting?"
  Text Question: "What's your name?"
  Text Question: "Best phone number to reach you?"
  Text: "We'll have someone reach out today!"
  → Create lead
```

## Meta Ad Configuration

### Campaign Structure

- **Campaign:** "Apex Painting - Front Range Lead Gen"
- **Ad Sets:** 3 (one per bot flow + one traffic to landing page)
- **Optimization:** Advantage+ for audience and placements

### Targeting

- **Locations:** Fort Collins, Loveland, Longmont, Boulder, Broomfield, Westminster, Arvada, Lakewood, Denver, Littleton, Centennial, Castle Rock (+ 10mi radius each)
- **Age:** 28-65
- **Demographics:** Homeowners
- **Interests:** Home improvement, interior design, Sherwin-Williams, Benjamin Moore, HGTV, Zillow, Houzz

### Ad Creative (No Photos Needed)

1. **Text overlay graphics** (Canva or Meta tools) — Bold text on solid/gradient backgrounds
2. **AI-generated house images** — Use Midjourney/DALL-E for before/after concepts
3. **Python `recolorSurface()`** — Generate color transformation visuals from stock house photos
4. **Remotion video ads** (stretch goal) — Programmatic 15-second color transformation animations

### Example Ad Copy

- "Fort Collins → Castle Rock: Spring painting season is booking fast. Get your free estimate before crews fill up."
- "Your neighbors are repainting this spring. Book your free estimate — we come to you."
- "Licensed. Insured. Obsessed with clean lines. Book your free painting estimate today."

## Technical Implementation

### Meta Pixel Installation

Add to `layouts/guest.blade.php` and `layouts/public.blade.php`:
- Meta Pixel base code in `<head>`
- Track `PageView` on all pages
- Track `Lead` event on form submission and booking completion
- Track `Schedule` event on `/book` completion

### Conversion API (Server-Side)

For better tracking (iOS 14+ privacy), implement server-side Conversions API:
- Fire events from Laravel when Customer or Deal is created
- Deduplicate with browser pixel using `event_id`

### Booking System Configuration

- Enable `CompanySetting.booking_enabled`
- Configure slot duration, buffer time, available days
- Set up estimator availability
- Area pages link to `/book?area={slug}` for location pre-fill

## Success Metrics

| Metric | Target |
|--------|--------|
| Cost per Messenger conversation | < $5 |
| Cost per lead (all channels) | < $15 |
| Messenger → Lead conversion | > 40% |
| Landing page → Lead conversion | > 5% |
| Leads per week (Week 2) | 15-25 |
| Leads per week (Week 3) | 30-50 |
| Booked appointments per week | 5-10 |

## What This Does NOT Include

- A/B testing infrastructure (Meta handles this via ad optimization)
- Geo-spatial / Leaflet integration (deferred unless needed for outreach)
- Web scraping pipeline (deferred to phase 2)
- Direct mail / postcard sending
- Google Ads (focus on Meta first, expand later)
