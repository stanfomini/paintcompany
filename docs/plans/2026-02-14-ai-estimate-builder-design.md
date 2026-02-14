# AI Estimate Builder - Design Document

**Date**: 2026-02-14
**Status**: Approved

## Overview

Automated estimate building pipeline. A button on the production app triggers Claude Code on the dev server to read blueprints, photos, and notes, then build out estimate areas with surfaces and generate packages - all without human intervention.

## Architecture

```
Production Server                          Dev Server (fancy-desert)
(paintingmadeeasydenver.com)               (apexnegotiations.com)

  "AI Build" Button                        Webhook Endpoint
  in Estimate UI ──── HTTP POST ────────►  POST /api/ai/estimate-build
                      {estimate_id,         │
                       api_secret}          │
                                           ▼
  Database ◄──── SSH (read/write) ────── Artisan Command
  Storage  ◄──── SCP (download PDFs) ──── estimate:ai-build {id}
                                           │
                                           ▼
  Callback Endpoint ◄── HTTP POST ────── Claude Code CLI
  POST /api/ai/build-complete              (runs in tmux session)
       │                                   Uses blueprint-estimator skill
       ▼                                   Logs to storage/logs/ai-builds/
  Reverb broadcast
  to user's browser
```

## Two Servers

- **Dev server (fancy-desert, 192.241.152.133)**: Code lives here. Claude Code runs here. Push to repo, deploy via Forge.
- **Production server (paintingmadeeasy-prod, paintingmadeeasydenver.com)**: Live app. Only interact via SSH for data reads and record inserts. Never modify source files on prod.

**SSH**: `ssh -i /home/forge/.ssh/id_rsa forge@paintingmadeeasydenver.com`
**Prod app path**: `/home/forge/paintingmadeeasydenver.com`

## Data Flow

### Happy Path

1. User clicks "AI Build" button on production estimate page
2. Production sends `POST https://apexnegotiations.com/api/ai/estimate-build` with `{estimate_id, api_secret}`
3. Production UI shows "AI is building your estimate..." with spinner
4. Dev server validates secret, creates `ai_build_requests` record (status: building)
5. Dev server spawns artisan command in background
6. Artisan command creates tmux session `ai-build-{estimateId}`, spawns Claude Code with prompt
7. Claude Code triages (checks for blueprints/photos/notes)
8. Claude Code SSHes into prod: reads estimate data, downloads PDFs via SCP
9. Claude Code processes blueprints locally with Python (pdfplumber)
10. Claude Code reviews photos locally
11. Claude Code SSHes into prod: creates areas and surfaces via artisan tinker
12. Claude Code SSHes into prod: generates good/better/best packages via artisan tinker
13. Artisan command sends callback to `POST paintingmadeeasydenver.com/api/ai/build-complete`
14. Production receives callback, broadcasts via Reverb
15. User's browser updates: "AI built 25 areas with 89 surfaces! Review now."
16. tmux session closes

### Triage Phase (Validation Before Building)

Claude Code's first step is investigating what materials are available:

1. Query estimate for: blueprint PDFs, photos, videos, estimator notes, customer notes, photo comments
2. Decision tree:
   - **NOTHING found** -> callback `nothing_to_build`, close session
   - **PDF found, text extractable** -> full build
   - **PDF found, can't read** -> callback `unreadable_blueprints`, close session
   - **Photos/videos + notes (no PDF)** -> limited build from visual/notes only
   - **Only notes** -> minimal build, flag for manual review

### Callback Status Codes

| Status | Meaning |
|--------|---------|
| `building` | AI is working |
| `completed` | Successfully built areas and packages |
| `nothing_to_build` | No blueprints, photos, or notes found |
| `unreadable_blueprints` | PDFs exist but text extraction failed |
| `failed` | Unexpected error during build |
| `partial` | Built some areas but had issues |

## Components

### Migration: `ai_build_requests` table

```
id, estimate_id, status, areas_created, surfaces_created,
started_at, completed_at, log_path, error_message,
callback_sent_at, created_at, updated_at
```

### Model: `AiBuildRequest`

Tracks each AI build attempt with status, counts, and log file path.

### Artisan Command: `estimate:ai-build {estimateId}`

- Creates tmux session: `tmux new-session -d -s ai-build-{id}`
- Spawns Claude Code: `claude --dangerously-skip-permissions -p "prompt here"`
- Logs to: `storage/logs/ai-builds/{id}-{timestamp}.log`
- Detects completion and sends callback
- Viewable in VS Code: `tmux attach -t ai-build-{id}`

### Webhook Controller (Dev Server): `AiBuildWebhookController`

- `POST /api/ai/estimate-build` - receives trigger from production
- Validates shared API secret
- Dispatches artisan command in background
- Returns `{ status: "building", request_id: "..." }`

### Callback Controller (Production): `AiBuildCallbackController`

- `POST /api/ai/build-complete` - receives status from dev server
- Validates shared API secret
- Updates estimate / broadcasts via Reverb
- Shows real-time notification to user

### Config: `config/ai-build.php`

```php
return [
    'api_secret' => env('AI_BUILD_SECRET'),
    'dev_server_url' => env('AI_BUILD_DEV_URL', 'https://apexnegotiations.com'),
    'prod_server_url' => env('AI_BUILD_PROD_URL', 'https://paintingmadeeasydenver.com'),
    'ssh_key' => env('AI_BUILD_SSH_KEY', '/home/forge/.ssh/id_rsa'),
    'ssh_user' => env('AI_BUILD_SSH_USER', 'forge'),
    'ssh_host' => env('AI_BUILD_SSH_HOST', 'paintingmadeeasydenver.com'),
    'prod_app_path' => env('AI_BUILD_PROD_PATH', '/home/forge/paintingmadeeasydenver.com'),
    'timeout_minutes' => env('AI_BUILD_TIMEOUT', 10),
];
```

### Prompt Template: `storage/app/ai-prompts/estimate-build.md`

Dynamic prompt generated by the artisan command with:
- Estimate ID and context
- SSH connection details
- Instructions to use blueprint-estimator skill
- Steps: triage -> read -> build -> packages -> callback

### UI: "AI Build" Button

Added to `⚡template-builder.blade.php`:
- Shows when estimate has uploaded media but no areas yet
- Disabled while a build is in progress
- Shows real-time status via Reverb channel
- On completion: refreshes the page to show built areas

## Security

- Shared API secret in `.env` on both servers (validated on every request)
- SSH key access is read + insert only (no destructive operations)
- Rate limiting: one active build per estimate
- Timeout: 10-minute max for Claude Code session
- All builds logged for audit trail

## Files to Create/Modify

### New Files (Dev Server)
- `database/migrations/..._create_ai_build_requests_table.php`
- `app/Models/AiBuildRequest.php`
- `app/Console/Commands/EstimateAiBuildCommand.php`
- `app/Http/Controllers/Api/AiBuildWebhookController.php`
- `app/Http/Controllers/Api/AiBuildCallbackController.php`
- `config/ai-build.php`
- `storage/app/ai-prompts/estimate-build.md`

### Modified Files
- `routes/api.php` - Add webhook + callback routes
- `resources/views/components/estimates/⚡template-builder.blade.php` - Add AI Build button
- `.env` / `.env.example` - Add AI_BUILD_SECRET and related vars
