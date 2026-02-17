# Media Hub & Video Platform Design

**Date**: 2026-02-17
**Status**: Approved

## Overview

A video management platform built on top of the existing `VideoRecording` infrastructure. Three core use cases:

1. **Loom-style quick videos** - Record, get a shareable link, send via email. Track views and engagement.
2. **Public video hub** (`/watch`) - YouTube-style channels organized by topic. Public, watch-only, branded.
3. **Internal training paths** - Structured video sequences for employee onboarding and skill development.

## Design Principles

- **Modular SFC components** - Each Livewire component does one thing, stays under ~150 lines. Extract early.
- **Additive only** - Existing VideoRecording model, upload pipeline, and Python processing are unchanged.
- **Separate from estimates/jobs** - Media Hub has its own UI, routes, and components. No cross-contamination with walk-around or estimate videos.
- **Core first** - Build the flexible foundation. Crew portal integration and Studio connection come later.

## Data Model

### New Models

#### `VideoChannel`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| user_id | foreignId | Creator/owner |
| name | string | "Our Jobs", "How-To Videos" |
| slug | string | unique, URL-friendly |
| description | text, nullable | Channel description |
| thumbnail_path | string, nullable | Channel cover image |
| visibility | enum: public, private | Public = /watch, Private = internal only |
| sort_order | integer, default 0 | Ordering on /watch |
| is_active | boolean, default true | Soft toggle |
| timestamps | | |

#### `VideoChannelItem`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| video_channel_id | foreignId | |
| video_recording_id | foreignId | |
| sort_order | integer, default 0 | Manual ordering within channel |
| published_at | timestamp, nullable | Allows scheduling |
| timestamps | | |

Unique constraint on `[video_channel_id, video_recording_id]`.

#### `VideoView`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| video_recording_id | foreignId | |
| viewer_id | foreignId, nullable | null for anonymous |
| viewer_email | string, nullable | For email sends |
| ip_address | string, nullable | |
| user_agent | string, nullable | |
| watch_duration_seconds | integer, default 0 | |
| watch_percentage | integer, default 0 | 0-100 |
| source | enum: link, channel, email, embed | How they found the video |
| timestamps | | |

#### `VideoEmail`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| video_recording_id | foreignId | |
| sent_by | foreignId (users) | Who sent it |
| recipient_email | string | |
| recipient_name | string, nullable | |
| subject | string | |
| message | text, nullable | |
| opened_at | timestamp, nullable | |
| clicked_at | timestamp, nullable | |
| timestamps | | |

#### `TrainingPath`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| name | string | "New Hire Onboarding" |
| description | text, nullable | |
| visibility | enum: employees, crews, all_internal | Who can see |
| is_required | boolean, default false | |
| sort_order | integer, default 0 | |
| is_active | boolean, default true | |
| timestamps | | |

#### `TrainingPathStep`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| training_path_id | foreignId | |
| video_recording_id | foreignId | |
| title | string, nullable | Override video title |
| description | text, nullable | |
| sort_order | integer, default 0 | |
| is_required | boolean, default true | |
| timestamps | | |

#### `TrainingProgress`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| user_id | foreignId | |
| training_path_step_id | foreignId | |
| watch_percentage | integer, default 0 | |
| completed_at | timestamp, nullable | |
| timestamps | | |

Unique constraint on `[user_id, training_path_step_id]`.

### Additive Changes to `VideoRecording`
| Column | Type | Notes |
|--------|------|-------|
| visibility | enum: unlisted, public, internal | Default 'unlisted' |
| description | text, nullable | |
| metadata | json, nullable | Flexible future data |

Existing walk-around videos default to `unlisted` and are untouched.

## UI Components (Livewire SFC)

### Architecture: Modular Components

Every component is small and focused. Parent components compose children.

### Internal Pages (`/media` routes, auth required)

#### Media Hub Dashboard (`/media`)
- `⚡media-hub.blade.php` - Tab container (My Videos | Channels | Training | Analytics)
- `⚡media-my-videos.blade.php` - Paginated grid of user's videos with filters
- `⚡media-video-card.blade.php` - Single video card (thumbnail, title, duration, views, actions)
- `⚡media-upload-modal.blade.php` - Upload video from file (not recorder)

#### Channel Management
- `⚡media-channel-list.blade.php` - List of all channels with create button
- `⚡media-channel-editor.blade.php` - Edit channel details (name, slug, description, visibility)
- `⚡media-channel-videos.blade.php` - Manage videos in a channel (add, remove, reorder)

#### Video Detail (`/media/{video}`)
- `⚡media-video-show.blade.php` - Video player + details layout
- `⚡media-video-edit.blade.php` - Edit title, description, visibility, channel assignments
- `⚡media-video-analytics.blade.php` - View stats, watch percentage chart, viewer list
- `⚡media-video-send-email.blade.php` - Email send form

#### Training Management
- `⚡media-training-list.blade.php` - List of training paths
- `⚡media-training-editor.blade.php` - Create/edit path with ordered steps
- `⚡media-training-step-row.blade.php` - Single step in the editor (pick video, set required)
- `⚡media-training-dashboard.blade.php` - Completion stats per employee per path

#### Quick Recorder
- `⚡media-quick-recorder.blade.php` - Floating button + recording overlay
  - Lives in the sidebar layout, always available
  - Uses existing chunked upload API
  - Post-record: copy link, send email, or add to channel

#### Analytics
- `⚡media-analytics-overview.blade.php` - Top-level stats (total views, top videos, recent activity)

### Public Pages (`/watch` routes, no auth)

#### Watch Hub (`/watch`)
- `⚡watch-hub.blade.php` - Landing page layout
- `⚡watch-channel-card.blade.php` - Channel preview card (thumbnail, name, video count)
- `⚡watch-search.blade.php` - Search videos by title/description

#### Channel View (`/watch/{slug}`)
- `⚡watch-channel.blade.php` - Channel page with video grid
- `⚡watch-video-card.blade.php` - Public video card (thumbnail, title, duration, views)

#### Video Player (`/watch/{slug}/{video}`)
- `⚡watch-player.blade.php` - Full video player page
- `⚡watch-up-next.blade.php` - Sidebar with next videos from channel

#### Shared Video (`/v/{shareToken}` - enhanced existing)
- `⚡watch-shared.blade.php` - Enhanced share page with branding and tracking

### Employee Pages (authenticated internal)

#### Training Center (embedded in employee dashboard)
- `⚡media-training-center.blade.php` - "My Training" with assigned paths and progress
- `⚡media-training-path-viewer.blade.php` - Watch videos in sequence, track progress
- `⚡media-training-step-player.blade.php` - Single video player with completion tracking

## Permission Matrix (Core Build)

| Feature | Owner | Internal/Employee |
|---------|-------|-------------------|
| Quick Recorder | Yes | Yes |
| Media Hub - all tabs | Yes | My Videos tab only |
| Create/manage channels | Yes | No |
| Create/manage training paths | Yes | No |
| Send video emails | Yes | Yes |
| View assigned training | N/A | Yes |

## Routes

### Internal (auth:web middleware)
- `GET /media` - Media Hub dashboard
- `GET /media/{recording}` - Video detail/edit
- `GET /media/channels/{channel}` - Channel management
- `GET /media/training/{path}` - Training path management

### Public (no auth)
- `GET /watch` - Public watch hub
- `GET /watch/{slug}` - Channel view
- `GET /watch/{slug}/{recording}` - Video player
- `GET /v/{shareToken}` - Shareable video (existing, enhanced)

### API (existing pattern, auth:web)
- Video upload/chunked upload - already exists
- `POST /api/video-views` - Track watch engagement (JS beacon)
- `POST /api/video-emails` - Send video email

## Analytics / View Tracking

- JS fires `video-view:progress` events at 25%, 50%, 75%, 100% watch marks
- Creates/updates `VideoView` record via lightweight API endpoint
- For email tracking: pixel tracking for opens, redirect tracking for clicks
- Aggregated stats computed on-demand (or cached) for dashboard display

## Email System

- `VideoSharedNotification` - Laravel notification/mailable
- Branded email template with video thumbnail, play button overlay, message
- Links to `/v/{shareToken}?ref=email&eid={emailId}` for click tracking
- Tracking pixel for open detection

## What's NOT in Scope (Future)

- Crew portal training integration
- Studio <-> Media Hub connection
- Comments, likes, social features
- Custom domain for /watch
- External upload (mobile app)
- Video transcription/captions
- Playlist auto-play
