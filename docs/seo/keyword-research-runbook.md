# SEO Keyword Research Runbook

This document is the **manual workflow** for taking keyword research out of Google Keyword Planner and Google Search Console and putting the results into the app where they can drive landing page content. There is **no Google API integration** — everything here is done by hand in the Google web UI plus copy/paste into the app at `/seo/keywords`.

**Audience:** the person managing SEO for apexnegotiations.com (currently solo).
**Recommended cadence:** weekly Search Console pull, monthly Keyword Planner refresh.

---

## Glossary

| Term | Meaning |
|---|---|
| **Combo page** | A `/areas/{area-slug}/{service-slug}` landing page (620 of these exist) |
| **Service page** | A `/services/{service-slug}` page (one per service) |
| **Area page** | A `/areas/{area-slug}` page (one per service area) |
| **Primary keyword** | The single keyword the page is most aggressively trying to rank for. Influences `<title>`, meta description, and the intro sentence on the page. Only one primary per page. |
| **Secondary keyword** | A supporting variant that informs internal copy and is shown in the keyword admin. |
| **Supporting keyword** | A long-tail or question variant — useful for content brainstorming, tracked but not directly injected into the page. |
| **Striking-distance keyword** | A query you currently rank in positions 8–20 for (page 1 or 2). Cheap wins — small content changes can move them up. |

---

## Part 1 — Google Keyword Planner

Use this tool to **discover keywords you don't already rank for** and **size search volume**.

### 1.1 Account setup

1. Go to [ads.google.com](https://ads.google.com).
2. Sign in with the Google account that owns apexnegotiations.com.
3. If you don't have a Google Ads account yet, click **Start now** and follow the setup. **You do not need to spend money** — Keyword Planner works without active campaigns, but volumes will be shown as bucketed ranges (e.g. `100–1K`) instead of exact numbers until your account has spend.
4. To skip the campaign creation flow, look for **"Switch to Expert Mode"** at the bottom of the setup wizard, then **"Create an account without a campaign."**
5. Once inside, in the top nav: **Tools & Settings → Planning → Keyword Planner**.

### 1.2 Seeding keywords by service

For each service (Interior Painting, Exterior Painting, Cabinet Painting, Deck & Fence Staining, etc.):

1. Click **Discover new keywords**.
2. **Start with keywords** tab. Enter 2–3 seed phrases for the service. Examples for Interior Painting:
   - `interior painting`
   - `house painters`
   - `wall painting`
3. **Location:** click the location field and switch to **Colorado** (or just the Denver metro). Default is the entire US which dilutes results.
4. **Language:** English.
5. **Search networks:** Google.
6. Click **Get results.**
7. The tool shows ~700 related keyword ideas. Sort by **Avg. monthly searches** descending.
8. Apply filters at the top:
   - **Avg. monthly searches:** ≥ 10
   - **Competition:** Low or Medium (skip High — too competitive for an SMB without big spend)
   - **Keyword text:** exclude `near me` if you want, exclude brand names of competitors if any show up
9. Note the keywords that look relevant. You'll export them in step 1.6.

### 1.3 Seeding by city (for combo pages)

For each of the 34 service areas, run a session with the city in the seed:

1. **Discover new keywords → Start with keywords.**
2. Enter a city-specific seed. Examples for Aurora:
   - `painters aurora co`
   - `house painting aurora colorado`
   - `interior painters aurora`
3. Same filters as 1.2 (Colorado, low/medium competition, volume ≥ 10).
4. Pay attention to long-tail like `aurora colorado painting cost` and question variants — these are gold for combo pages and FAQ content.

### 1.4 Discover new ideas

1. Inside any session, click **Refine keywords** at the top.
2. Use the filter chips Google suggests (intent, attributes, modifiers) to expand the list.
3. The **Related searches** at the bottom of the page also surfaces real query patterns Google sees — copy any that match offerings.

### 1.5 Filtering — what to keep, what to drop

**Keep:**
- Local intent (`painters denver`, `aurora house painters`, etc.)
- Service-specific commercial intent (`cabinet painting cost`, `interior painters near me`)
- Question variants for FAQ content (`how much do painters cost in denver`)
- Striking-distance keywords from Search Console (Part 2)

**Drop:**
- Pure informational with no buying intent unless you have a content article planned (`how to paint a wall yourself`)
- Branded competitor terms unless you're explicitly running a comparison campaign
- Junk single-word terms (`paint`, `painting`)
- Anything where the volume is `< 10` AND the keyword isn't local (low-volume local long-tail is fine)

### 1.6 Exporting

1. With your filtered list visible, click **⤓ Download keyword ideas** at the top right.
2. Choose **Plan historical metrics** → **CSV.**
3. The CSV will include columns like:
   - `Keyword`
   - `Avg. monthly searches`
   - `Competition`
   - `Top of page bid (low range)` / `Top of page bid (high range)`

⚠ **Google sometimes renames these columns** ("Avg. monthly searches" vs "Average monthly searches"). Always inspect the header row before importing to make sure you know which column is which.

4. Save the CSV somewhere local with a clear name like `keyword-planner-aurora-2026-04-09.csv`.
5. Move on to Part 3 to import.

---

## Part 2 — Google Search Console

Use this tool to **see what queries already drive impressions and clicks to your live pages**. Free, fast, and the data is real — not estimates. You'll find your strongest opportunities here, not in Keyword Planner.

### 2.1 Verifying the property (one-time setup)

1. Go to [search.google.com/search-console](https://search.google.com/search-console).
2. Sign in with the Google account that owns the domain.
3. If apexnegotiations.com isn't already a property, click **Add property → Domain** (preferred over URL prefix because it covers https/http and www/non-www in one shot).
4. Verify via DNS TXT record (most reliable). Cloudflare/your DNS provider has a one-click flow.
5. Wait 2–3 days for data to appear after first verification. Existing verified properties skip this step.

### 2.2 Performance report basics

1. In the left nav, click **Performance**.
2. Set the **date range** to **Last 28 days** (the default 90 days has more data but recent trends matter more for SEO).
3. **Filter to country = United States** (the **+ New** button at the top → Country → United States).
4. The four checkboxes above the chart (Total clicks, Total impressions, Avg CTR, Avg position) — turn all of them on. You'll need the position column to find striking-distance keywords.

### 2.3 Filtering by combo URL pattern

This is the **non-obvious trick** for scoping to combo pages. By default, GSC shows queries across the whole site. To see only combo pages:

1. Click the **Pages** tab.
2. Click **+ New → Page → Custom (regex)**.
3. Enter this regex: `^https://apexnegotiations\.com/areas/[^/]+/[^/]+$`
4. Click **Apply.**

You're now looking only at combo pages. Switch back to the **Queries** tab to see what queries land on those combo pages specifically.

To scope to standalone area pages: regex `^https://apexnegotiations\.com/areas/[^/]+$`
To scope to standalone service pages: regex `^https://apexnegotiations\.com/services/[^/]+$`

### 2.4 Striking-distance keywords

These are your **fastest wins** — queries you already rank for in positions 8–20. Tiny content tweaks can move them onto page 1.

1. With the filter applied (or not), in the **Queries** tab, click the **Position** column header to sort ascending.
2. Look for queries where:
   - **Position** is between 8 and 20
   - **Impressions** is > 50 (so you know there's actual traffic potential)
   - **CTR** is ≤ 3%
3. These are exactly the keywords you should set as **primary** on the most relevant page in the app.
4. Each query has a small expand arrow — click it to see which **specific page** that query lands on. That tells you which combo/service/area page to assign it to.

### 2.5 Keyword gaps (high-impression, low-CTR)

These mean Google is showing your page for the query but users aren't clicking — usually because the title or description doesn't match intent.

1. Filter: **Impressions > 50** AND **CTR < 2%**.
2. For each gap query, identify the page it lands on, then:
   - In the app, assign that query as a **secondary** keyword on that page.
   - Consider whether the **primary** keyword should be changed to better match the gap query.
   - Rewriting the meta description (which the app does automatically once a primary keyword is set) often fixes the CTR.

### 2.6 Exporting

1. Click the **⤓ Export** button at the top right of the Performance page.
2. Choose **Download CSV.**
3. You'll get a zip with `Queries.csv` (just the queries) and `Pages.csv` (just the pages). For the keyword work you want **Queries.csv**.
4. The columns are: `Top queries`, `Clicks`, `Impressions`, `CTR`, `Position`.

⚠ **GSC exports do NOT include service/area slugs.** You'll need to hand-edit the CSV to add `service_slug` and `area_slug` columns matching the page each query lands on. This is tedious — for now, the in-app **paste flow** (Part 3.2) is faster than CSV import for GSC data.

---

## Part 3 — Importing into the app

The app has a single import flow: **`/seo/keywords/import`** with a textarea paste.

### 3.1 CSV column spec (future)

When the CSV importer ships, the canonical column format will be:

```
keyword,search_volume,competition,cpc,source,intent,position,impressions,clicks,service_slug,area_slug,role
```

For now this format only matters as a target — the paste flow (3.2) handles the MVP.

### 3.2 Paste flow (MVP)

1. Visit `/seo/keywords/import` while logged in as an internal user.
2. **Paste keywords**, one per line. Strip headers, prices, brackets — just the keyword text.
3. Set the **defaults**:
   - **Status:** `researched` for new lists, `targeted` if you've already decided to act on them.
   - **Source:** `keyword_planner` or `search_console` so you know where it came from.
   - **Service:** the service these keywords are about (or leave blank for area-only assignment).
   - **Service Area:** the area these keywords are about (or leave blank for service-only assignment).
   - **Role:** `secondary` is the safe default. Use `primary` only when you've decided this is THE keyword for that page (it will demote any existing primary on the same page).
4. Click **Import Keywords.** The app:
   - Lowercases each line and dedupes.
   - Skips blank lines and lines longer than 255 chars.
   - Creates new keyword rows; updates the assignment if the keyword already exists.
5. The result panel shows: created / existed / assigned / skipped counts.

### 3.3 Primary vs secondary — what the difference does on the page

| Role | What it does |
|---|---|
| **Primary** | The keyword is woven into the page's `<title>` tag, meta description, and an intro paragraph in the body. Only one primary per page. Strongest SEO signal. |
| **Secondary** | Tracked in the admin and visible per-page; does not currently appear in rendered HTML but informs your manual content writing decisions. Future: may be used for automatic body keyword density. |
| **Supporting** | Long-tail / question variant. Tracked, never auto-injected. Use for content ideas and FAQ writing. |

### 3.4 Custom sentence overrides

The app auto-generates an intro sentence using the primary keyword:

> "When homeowners search for {keyword} in {city}, they want a team that knows the neighborhood."

This is a safety-net default. **It will read clunky for some keywords.** When it does:

1. Open the keyword in `/seo/keywords/{keyword}/edit`.
2. Either:
   - Set the **Default sentence** at the top of the form (applies everywhere this keyword is used)
   - Or scroll to **Page assignments**, find the row for the specific page, and set the **Custom sentence** in the Add Assignment form (only applies to that one page)
3. The cascade is: per-page custom > keyword global default > auto-generated.

### 3.5 Recommended cadence

| Frequency | Task |
|---|---|
| **Weekly** | Pull GSC striking-distance and gap keywords. Update primaries and secondaries on the affected pages. |
| **Monthly** | Run Keyword Planner research for 1–2 new services or areas. Import the new keywords as `researched`. Promote the best ones to `targeted`. |
| **Quarterly** | Review keywords with status `targeted` for at least a month. Move to `ranking` if GSC shows position improvement. Move to `won` if position is ≤ 5. |

### 3.6 Troubleshooting

**Duplicates appear after import.** The dedup is case-insensitive on the `keyword` column. If you see duplicates, check for trailing whitespace or non-breaking spaces.

**"Unknown service slug" or "unknown area slug" errors.** The paste flow doesn't accept slugs in the CSV — it only accepts the dropdown selection. If you have a CSV with slugs, hand-paste the lines for each (service, area) combo separately for now.

**A primary keyword you set isn't appearing on the page.** Things to check, in order:
1. The keyword has `status` = anything except `dropped`.
2. The assignment role is `primary` (not secondary or supporting).
3. The assignment targets the right (service, area). For combo pages, both must be set.
4. Cache: assignments flush their own cache on save, but if you tinkered in the database directly, run `php artisan cache:clear`.

**GSC exports only show partial data.** GSC samples queries below ~10 impressions to protect user privacy. This is normal — the gap is real, not a bug. Use Keyword Planner to fill the volume gap.

**Title tag is getting cut off in Google search results.** The app caps `<title>` at 60 characters. If your keyword + company name exceeds that, the company suffix is dropped first. To shorten further, edit the keyword to be more concise — e.g. `interior painters in denver colorado` (35 chars) instead of `the best interior painters in denver colorado area` (54 chars).

---

## Where the data lives

| Thing | Path |
|---|---|
| Admin index | `/seo/keywords` |
| Import | `/seo/keywords/import` |
| Edit | `/seo/keywords/{id}/edit` |
| `keywords` table | DB |
| `keyword_page_assignments` table | DB |
| Resolver service | `app/Services/Seo/KeywordResolver.php` |
| Page rendering integration | `resources/views/components/public/⚡area-service-page.blade.php`, `⚡service-page.blade.php`, `⚡area-page.blade.php` |
| Tests | `tests/Feature/Seo/` |
