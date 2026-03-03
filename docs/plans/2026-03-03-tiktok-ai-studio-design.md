# TikTok-Style AI Studio - Design Document

**Date:** 2026-03-03
**Status:** Approved
**Approach:** Hybrid — TikTok UI rebuild on existing data model + AI prompt layer (Approach 1 + 3)

## Vision

A TikTok-style video creation studio that lets painting company users quickly create short-form videos by combining system media (job photos, estimate walkrounds, property images) with recorded segments, enhanced by AI that can auto-generate, assist editing, and fill smart templates — all without leaving the app.

```
Browse deals → Tap photos → Record your commentary → AI adds captions → Done
```

## Architecture Decision: AI Integration

**Stack:** Laravel AI SDK (first-party, built on Prism PHP) → Claude Sonnet/Haiku → tool definitions wrapping Eloquent models.

**Why this over Claude Code tmux sessions:**
- Production-ready, concurrent-safe, scalable
- Fast (2-5s responses vs 30-60s for CLI sessions)
- Cost-effective (scoped context vs full codebase)
- Secure (sandboxed tool definitions vs shell access)

**Model selection:**
- Claude Sonnet for complex tasks (auto-generation, multi-step reasoning)
- Claude Haiku for quick assists (captions, trim suggestions, simple edits)

---

## Section 1: Studio Home — The Media Feed

**Route:** `/studio` (replaces current project list)

A deal-centric media browser, not a flat feed:

- **Top level:** Lists deals (with associated jobs and estimates)
- **Tap a deal:** See its job photos, estimate photos, walkthrough videos, property images
- **"My Videos" tab:** Video projects, recordings from the media hub
- **"Media Hub" tab:** Published hub videos (available_in_hub = true)
- **Filter pills:** All | Deals | Jobs | Estimates | Properties | Media Hub | My Videos
- **Search bar** with deal/customer name search
- **Top bar actions:**
  - `+ Create` button → opens Quick Creator blank
  - AI Prompt Bar → type natural language to auto-generate

**Tap any photo/video** → opens Quick Creator pre-loaded with that media.

---

## Section 2: Quick Creator (TikTok Editor)

**Route:** `/studio/{videoProject}/create` (or `/studio/create` for new)

Full-screen, vertical-first editor designed for speed:

```
┌─────────────────────────┐
│  AI Prompt Bar          │  ← "Add captions" / "Make it punchier"
├─────────────────────────┤
│                         │
│                         │
│    VIDEO PREVIEW        │  ← 9:16 vertical preview (or 16:9 toggle)
│    (what you're making) │
│                         │
│                         │
├─────────────────────────┤
│  ● REC  │ ■ STOP │ ▶   │  ← Record controls
├─────────────────────────┤
│ [clip][clip][clip][+]   │  ← Horizontal clip strip (mini timeline)
├─────────────────────────┤
│ Text  Transitions Speed │  ← Quick tool bar
└─────────────────────────┘
```

### Record & Stitch Workflow
1. Tap **REC** → camera records your face/screen
2. Tap **STOP** → segment saved as a clip in the strip
3. Tap **REC** again → records next segment
4. Clips auto-stitch seamlessly in the preview
5. Tap any clip in the strip to trim, delete, or reorder

### Adding System Media
- Tap **[+]** in the clip strip → opens the deal/media browser
- Selected photo/video becomes a clip in the strip
- Can set as **background** (plays behind recording) or **full clip** (takes over frame)

### Picture-in-Picture
- While recording, tap a clip to overlay it
- Your face in corner, job site video plays behind (TikTok duet/stitch style)

### Quick Tools
- **Text** — tap to add text overlays at specific timeline points
- **Transitions** — cut, fade, slide between clips
- **Speed** — speed up/slow down segments
- **Music** — add background audio track
- **Filters** — color grading presets
- **Aspect ratio toggle** — 9:16 (TikTok), 16:9 (landscape), 1:1 (square)

### Export
- Tap **"Done"** → renders via existing Python pipeline
- Options: Save to Media Hub | Download | Share Link
- Toggle: "Publish to Media Hub" for public gallery

---

## Section 3: AI Integration Layer

### 3A: Auto-Generate from Prompt

From studio home or inside editor, natural language prompts:

- **"Make a before/after video of the Johnson kitchen"**
  - AI queries: Deals → finds "Johnson" → finds kitchen job → pulls before/after photos
  - Auto-creates VideoProject with clips arranged chronologically
  - Adds text overlays (customer name, "Before"/"After" labels)
  - Opens in Quick Creator for review/tweaks

- **"Create a 30-second reel showcasing our last 5 completed jobs"**
  - AI queries recent completed jobs → pulls hero photos
  - Arranges as slideshow with transitions, adds company branding

**Under the hood:** Laravel AI SDK → Claude with tool calling → queries Eloquent models → builds VideoProject programmatically.

### 3B: AI Assist During Editing

While in the Quick Creator with clips assembled:

- **"Add captions"** → Python Whisper transcription → text overlays timed to speech
- **"Trim the dead air"** → AI analyzes audio levels, auto-trims silence
- **"Make it more energetic"** → suggests speed changes, adds transitions, adjusts pacing
- **"Add background music that fits"** → selects from library based on content mood

### 3C: Smart Templates

Pre-built project skeletons that auto-fill with system data:

| Template | Auto-fills | You Add |
|----------|-----------|---------|
| Before/After | Before & after photos from job | Your voiceover commentary |
| Job Walkthrough | Property photos, job details | Your talking head recording |
| Customer Testimonial | Customer name, job photos | Customer's recorded testimonial |
| Quick Estimate Tour | Estimate photos, area descriptions | Your walkthrough narration |
| Team Highlight | Crew photos, recent completions | Your intro/outro |

**Flow:** Pick template → AI pre-fills with system data → record your segments → done.

---

## Section 4: Data Model Changes

### Modified: `VideoProject`
```php
// New fields:
'creation_mode'  // enum: quick, template, ai_generated, classic
'aspect_ratio'   // enum: 9:16, 16:9, 1:1 (default: 9:16)
'ai_prompt'      // text, nullable — original prompt that generated it
'template_id'    // FK to studio_templates, nullable
```

### New: `StudioTemplate`
```php
'name'            // string
'description'     // text
'category'        // enum: before_after, walkthrough, testimonial, estimate_tour, team_highlight
'thumbnail_path'  // string, nullable
'structure_json'  // json — skeleton definition (segments, auto-fill targets, overlay positions)
'system_prompt'   // text — instructions for AI when filling this template
'is_active'       // boolean
```

### New: AI Tool Definitions (PHP classes, not DB models)

| Tool Class | Purpose |
|------------|---------|
| `SearchDeals` | Query deals by name, customer, status |
| `GetDealMedia` | Get photos/videos from a deal's jobs + estimates |
| `GetJobPhotos` | Get before/after and progress photos for a job |
| `GetEstimatePhotos` | Get photos from an estimate |
| `GetPropertyPhotos` | Get photos from a property |
| `CreateVideoProject` | Create a new project with scenes and clips |
| `AddClipToProject` | Add a photo/video as a clip to a scene |
| `AddTextOverlay` | Add text overlay to a scene |
| `TranscribeAudio` | Call Python Whisper service for auto-captions |

### Unchanged Models
- VideoProjectScene, VideoProjectClip, VideoProjectVoiceover, VideoRecording, VideoProjectOverlay — all work as-is.

---

## Section 5: Component Architecture

### New Components (Livewire SFC)

| Component | Purpose |
|-----------|---------|
| `⚡studio-feed.blade.php` | Studio home — deal browser, my videos, media hub, AI prompt bar |
| `⚡quick-creator.blade.php` | TikTok-style editor — record, stitch, arrange, preview, export |
| `⚡media-browser.blade.php` | Modal — browse deals → jobs → photos/videos + media hub tab |
| `⚡ai-prompt-bar.blade.php` | Reusable AI prompt input with streaming response display |
| `⚡template-picker.blade.php` | Grid of smart templates to choose from |
| `⚡clip-strip.blade.php` | Horizontal scrollable clip timeline at bottom of creator |

### Retire (replace with new)

| Component | Replaced By |
|-----------|-------------|
| `⚡editor.blade.php` (1538 LOC, broken) | `⚡quick-creator.blade.php` |
| `⚡remotion-builder.blade.php` | AI generation absorbed into `⚡ai-prompt-bar.blade.php` |
| `⚡media-picker.blade.php` | `⚡media-browser.blade.php` (deal-centric) |

### Keep (untouched or minor refactor)

| Component | Reason |
|-----------|--------|
| `⚡project-list.blade.php` | Absorbed into `⚡studio-feed.blade.php` (can remove after) |
| `⚡voiceover-recorder.blade.php` | Embedded in quick creator |
| `⚡studio.blade.php` | Live streaming — separate feature |
| `⚡share-view.blade.php` | Public sharing — still needed |
| `⚡public-recorder.blade.php` | Guest recording — still needed |
| `⚡invite-manager.blade.php` | Guest collaboration — still needed |
| `⚡guest-view.blade.php` | Guest join — still needed |
| `⚡landing-page-videos.blade.php` | Media hub public gallery |
| `⚡video-card.blade.php` | Media hub video cards |
| `⚡video-show.blade.php` | Media hub video detail |

### JavaScript/Frontend
- **Alpine.js** for clip strip interactions, drag-to-reorder, recording controls
- **MediaRecorder API** for browser-based recording (already used)
- **Canvas API** for video preview compositing (PIP, overlays)
- **Existing Remotion pipeline** stays for template-based AI renders

---

## Section 6: Media Hub Integration

The media hub is both a **source** and **destination**:

**As Source:**
- Studio feed has a "Media Hub" tab showing published videos
- Media browser modal includes media hub videos for pulling into creations
- Hub videos can be used as clips, backgrounds, or starting points

**As Destination:**
- Quick Creator "Done" flow includes "Publish to Media Hub" toggle
- AI-generated videos can auto-publish to hub
- Existing hub features (channels, public watch pages, view tracking) work with studio-created content

**Existing hub components untouched:** landing-page-videos, video-card, video-show — they continue serving the public gallery with external export already built in.

---

## Section 7: Phased Delivery

| Phase | What Ships | Can Parallelize |
|-------|-----------|-----------------|
| **Phase 1: Foundation** | Studio feed (deal browser + media hub tab), media browser modal, basic quick creator (add clips, reorder, preview) | — |
| **Phase 2: Record & Stitch** | Multi-segment recording, seamless stitching, PIP mode | With Phase 3 |
| **Phase 3: Quick Tools** | Text overlays, transitions, speed control, aspect ratio toggle, music | With Phase 2 |
| **Phase 4: AI Layer** | Install Laravel AI SDK, define tool classes, AI prompt bar, auto-generate from prompt | With Phase 1-3 |
| **Phase 5: Smart Templates** | StudioTemplate model, template picker, auto-fill from system data | After Phase 4 |
| **Phase 6: AI Assists** | Auto-captions (Whisper), trim dead air, smart suggestions in editor | After Phase 4 |
| **Phase 7: Polish & Export** | Export improvements, music library, filters, social sharing presets | After Phase 3 |

**Parallel execution strategy:**
- Phases 1-3 (UI/frontend) can run simultaneously with Phase 4 (AI backend)
- Phase 5 and 6 both depend on Phase 4 but can run in parallel with each other
- Phase 7 is independent polish work

---

## Technical Notes

### Video Processing Pipeline (Existing — Reused)
1. Recording created → VideoRecording with `status: processing`
2. ProcessVideoRecordingJob queued → Python service
3. Python: FFmpeg compression (1080p max, CRF 28), thumbnail generation, duration detection
4. Status updated to `ready`

### Remotion Pipeline (Existing — Reused for Templates)
1. AI or template creates RemotionBuildRequest
2. Artisan command renders via tmux: `npx remotion render {composition}`
3. Callback webhook creates VideoRecording
4. Available in Quick Creator or Media Hub

### Recording (Browser-Based — Existing Pattern)
- MediaRecorder API (used in public-recorder.blade.php)
- Chunked upload for large files (existing infrastructure)
- Works on desktop and mobile browsers
