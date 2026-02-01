# Laravel + Python Integration Plan

## Overview

Integrate Python capabilities into the Laravel CRM for:
1. **Real estate scraping** - Zillow, Realtor, MLS data collection
2. **Advanced image ML** - Enhancement, object removal, substrate detection, color changing
3. **Real-time media** - Walkie-talkie messaging, video streaming, speech-to-text

---

## Architecture

```
┌─────────────────────────────────────────────────────────────────────────┐
│                          Laravel Application                             │
│  ┌────────────┐   ┌────────────┐   ┌────────────┐   ┌────────────┐     │
│  │ Livewire   │   │ Services   │   │   Jobs     │   │ WebSocket  │     │
│  │ Components │   │            │   │            │   │ (Reverb)   │     │
│  └─────┬──────┘   └─────┬──────┘   └─────┬──────┘   └─────┬──────┘     │
└────────┼────────────────┼────────────────┼────────────────┼─────────────┘
         │                │                │                │
         │    HTTP/REST   │                │    WebSocket   │
         ▼                ▼                ▼                ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                         Python Service Layer                             │
│                                                                          │
│  ┌──────────────────┐  ┌──────────────────┐  ┌──────────────────┐      │
│  │   FastAPI        │  │  ML Processing   │  │  Media Server    │      │
│  │   REST API       │  │                  │  │                  │      │
│  │                  │  │  - SAM (segment) │  │  - RTMP ingest   │      │
│  │  /scrape         │  │  - LaMa (inpaint)│  │  - HLS output    │      │
│  │  /image/process  │  │  - ControlNet    │  │  - WebRTC relay  │      │
│  │  /image/detect   │  │  - Whisper (STT) │  │  - STT pipeline  │      │
│  └──────────────────┘  └──────────────────┘  └──────────────────┘      │
│         │                      │                      │                 │
│         └──────────────────────┼──────────────────────┘                 │
│                                ▼                                        │
│                    ┌──────────────────┐                                 │
│                    │   S3 Storage     │                                 │
│                    │   (Images/Media) │                                 │
│                    └──────────────────┘                                 │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## Directory Structure

```
/home/forge/apexnegotiations.com/
├── app/
│   ├── Jobs/
│   │   ├── Scraping/
│   │   │   └── ScrapeListingJob.php
│   │   └── ImageProcessing/
│   │       ├── ProcessImageMLJob.php
│   │       └── DetectSubstrateJob.php
│   ├── Services/
│   │   ├── PythonBridgeService.php      # HTTP client for Python API
│   │   ├── ImageMLService.php           # Image processing orchestration
│   │   ├── ScrapingService.php          # Scraping orchestration
│   │   └── MediaStreamService.php       # Real-time media handling
│   └── Livewire/
│       └── Media/
│           ├── WalkieTalkie.php         # Push-to-talk component
│           └── VideoStream.php          # Video streaming component
├── python/
│   ├── pyproject.toml                   # Modern Python packaging
│   ├── requirements.txt
│   ├── api/
│   │   ├── main.py                      # FastAPI entry point
│   │   ├── config.py                    # Configuration
│   │   ├── routers/
│   │   │   ├── scraping.py              # /scrape endpoints
│   │   │   ├── images.py                # /image/* endpoints
│   │   │   ├── media.py                 # /media/* endpoints
│   │   │   └── health.py                # /health endpoint
│   │   ├── services/
│   │   │   ├── scraper/
│   │   │   │   ├── base.py
│   │   │   │   ├── zillow.py
│   │   │   │   ├── realtor.py
│   │   │   │   └── mls.py
│   │   │   ├── ml/
│   │   │   │   ├── segmentation.py      # SAM model
│   │   │   │   ├── inpainting.py        # LaMa / SD inpaint
│   │   │   │   ├── substrate.py         # Surface detection
│   │   │   │   └── recolor.py           # Color manipulation
│   │   │   └── media/
│   │   │       ├── transcription.py     # Whisper STT
│   │   │       ├── streaming.py         # RTMP/HLS
│   │   │       └── audio.py             # Audio processing
│   │   ├── models/                      # ML model files (or download)
│   │   └── utils/
│   │       ├── s3.py                    # S3 operations
│   │       └── image.py                 # Image utilities
│   └── tests/
├── config/
│   └── python.php                       # Python service config
└── ...
```

---

## Component Details

### 1. Real Estate Scraping

**Challenge**: Zillow, Realtor, MLS sites use:
- JavaScript rendering (React/Angular)
- Anti-bot detection (Cloudflare, PerimeterX)
- Rate limiting
- CAPTCHA challenges

**Solution**: Playwright with stealth techniques

```python
# python/api/services/scraper/zillow.py
from playwright.async_api import async_playwright
from playwright_stealth import stealth_async

class ZillowScraper:
    async def scrape_listing(self, url: str) -> dict:
        async with async_playwright() as p:
            browser = await p.chromium.launch(headless=True)
            context = await browser.new_context(
                user_agent="Mozilla/5.0...",
                viewport={"width": 1920, "height": 1080}
            )
            page = await context.new_page()
            await stealth_async(page)

            await page.goto(url, wait_until="networkidle")
            # Extract data...
```

**Alternative**: Consider third-party APIs as backup:
- Zillow API (limited access)
- RapidAPI real estate endpoints
- Web scraping services (ScrapingBee, Bright Data)

### 2. Advanced Image ML

**Required Models:**

| Model | Purpose | Size | GPU Required |
|-------|---------|------|--------------|
| **SAM** (Segment Anything) | Object detection & segmentation | ~2.5GB | Recommended |
| **LaMa** | Object removal (inpainting) | ~200MB | No |
| **Stable Diffusion Inpaint** | Advanced inpainting | ~4GB | Yes |
| **Custom CNN** | Substrate detection | ~100MB | No |
| **ControlNet** | Color manipulation with control | ~5GB | Yes |

**Substrate Detection** (paint surface identification):
```python
# Custom model to detect: drywall, wood, brick, stucco, metal, concrete
class SubstrateDetector:
    SUBSTRATES = ["drywall", "wood", "brick", "stucco", "metal", "concrete", "vinyl"]

    def detect(self, image_path: str) -> dict:
        # Returns: {"substrate": "drywall", "confidence": 0.94, "regions": [...]}
```

**Color Changing with Dynamic Prompts:**
```python
# Without LLM - using ControlNet or color transfer
def recolor_surface(
    image_path: str,
    mask_path: str,      # From SAM segmentation
    target_color: str,   # Hex or RGB
    preserve_texture: bool = True
) -> str:
    # Returns S3 URL of processed image
```

**With Optional LLM (for complex prompts):**
```python
# If dynamic prompts need interpretation
def process_with_prompt(
    image_path: str,
    prompt: str,  # "Change the living room walls to Sherwin-Williams Sea Salt"
    use_llm: bool = True
) -> str:
    if use_llm:
        # Parse prompt with local LLM (LLaMA) or API
        operations = parse_prompt(prompt)
    # Execute operations...
```

### 3. Real-Time Media System

**Walkie-Talkie Architecture:**

```
┌─────────────┐     WebSocket      ┌─────────────┐
│  Livewire   │◀──────────────────▶│   Reverb    │
│  Component  │                     │  (Laravel)  │
└──────┬──────┘                     └──────┬──────┘
       │                                   │
       │ Audio chunks                      │ Broadcast
       ▼                                   ▼
┌─────────────┐     HTTP/WS        ┌─────────────┐
│   Python    │◀──────────────────▶│   Other     │
│   Whisper   │                     │   Clients   │
│   (STT)     │                     │             │
└──────┬──────┘                     └─────────────┘
       │
       │ Transcription
       ▼
┌─────────────┐
│   S3 +      │
│   Database  │
└─────────────┘
```

**Video Streaming Pipeline:**

```
Livewire → WebRTC/MediaRecorder → Python RTMP Server → HLS → CDN → Viewers
```

```python
# python/api/services/media/streaming.py
import asyncio
from aiortc import RTCPeerConnection, VideoStreamTrack

class VideoStreamProcessor:
    async def process_stream(self, offer: dict) -> dict:
        pc = RTCPeerConnection()

        @pc.on("track")
        def on_track(track):
            if track.kind == "video":
                # Process frames, apply ML, output to RTMP
                self.process_video_track(track)

        await pc.setRemoteDescription(offer)
        answer = await pc.createAnswer()
        await pc.setLocalDescription(answer)

        return {"sdp": pc.localDescription.sdp, "type": "answer"}
```

---

## Implementation Phases

### Phase 1: Foundation (Week 1)

**Tasks:**
1. Create `python/` directory structure
2. Set up Python virtual environment and dependencies
3. Create FastAPI skeleton with health endpoint
4. Create `config/python.php` configuration
5. Create `PythonBridgeService.php` HTTP client
6. Set up systemd service for FastAPI
7. Write basic integration tests

**Files to Create:**
- `python/pyproject.toml`
- `python/requirements.txt`
- `python/api/main.py`
- `python/api/routers/health.py`
- `config/python.php`
- `app/Services/PythonBridgeService.php`
- `tests/Feature/PythonBridgeTest.php`

### Phase 2: Image ML Core (Week 2-3)

**Tasks:**
1. Set up SAM model for segmentation
2. Implement LaMa for basic inpainting
3. Create substrate detection model/endpoint
4. Implement color manipulation
5. Create Laravel job classes
6. S3 integration for image storage

**Files to Create:**
- `python/api/routers/images.py`
- `python/api/services/ml/segmentation.py`
- `python/api/services/ml/inpainting.py`
- `python/api/services/ml/substrate.py`
- `python/api/services/ml/recolor.py`
- `app/Services/ImageMLService.php`
- `app/Jobs/ImageProcessing/ProcessImageMLJob.php`

### Phase 3: Scraping System (Week 3-4)

**Tasks:**
1. Set up Playwright with stealth
2. Implement Zillow scraper
3. Implement Realtor.com scraper
4. Create proxy rotation system
5. Add rate limiting and retry logic
6. Create Laravel orchestration

**Files to Create:**
- `python/api/routers/scraping.py`
- `python/api/services/scraper/base.py`
- `python/api/services/scraper/zillow.py`
- `python/api/services/scraper/realtor.py`
- `app/Services/ScrapingService.php`
- `app/Jobs/Scraping/ScrapeListingJob.php`

### Phase 4: Media System (Week 4-5)

**Tasks:**
1. Set up Whisper for speech-to-text
2. Create walkie-talkie Livewire component
3. Implement WebSocket audio streaming
4. Set up RTMP/HLS video pipeline
5. Create video streaming Livewire component

**Files to Create:**
- `python/api/routers/media.py`
- `python/api/services/media/transcription.py`
- `python/api/services/media/streaming.py`
- `app/Livewire/Media/WalkieTalkie.php`
- `app/Livewire/Media/VideoStream.php`
- `app/Services/MediaStreamService.php`

---

## Configuration

**`.env` additions:**
```env
# Python Service
PYTHON_SERVICE_URL=http://127.0.0.1:8000
PYTHON_SERVICE_TIMEOUT=60
PYTHON_ENABLED=true

# ML Models (optional - for model download locations)
ML_MODELS_PATH=/home/forge/apexnegotiations.com/python/models
SAM_MODEL_PATH=${ML_MODELS_PATH}/sam_vit_h.pth

# Scraping
SCRAPING_PROXY_URL=
SCRAPING_RATE_LIMIT=10

# Media
RTMP_SERVER_URL=rtmp://127.0.0.1:1935
HLS_OUTPUT_PATH=/var/www/hls
```

**`config/python.php`:**
```php
<?php

return [
    'enabled' => env('PYTHON_ENABLED', false),
    'service_url' => env('PYTHON_SERVICE_URL', 'http://127.0.0.1:8000'),
    'timeout' => env('PYTHON_SERVICE_TIMEOUT', 60),
    'retry_attempts' => 3,

    'ml' => [
        'models_path' => env('ML_MODELS_PATH'),
        'gpu_enabled' => env('ML_GPU_ENABLED', false),
    ],

    'scraping' => [
        'proxy_url' => env('SCRAPING_PROXY_URL'),
        'rate_limit' => env('SCRAPING_RATE_LIMIT', 10),
    ],

    'media' => [
        'rtmp_url' => env('RTMP_SERVER_URL'),
        'hls_path' => env('HLS_OUTPUT_PATH'),
    ],
];
```

---

## Python Dependencies

**`requirements.txt`:**
```txt
# Core
fastapi>=0.109.0
uvicorn[standard]>=0.27.0
python-multipart>=0.0.9
httpx>=0.27.0
pydantic>=2.6.0
python-dotenv>=1.0.0

# AWS/Storage
boto3>=1.34.0
aioboto3>=12.0.0

# Scraping
playwright>=1.41.0
playwright-stealth>=1.0.6
beautifulsoup4>=4.12.0
lxml>=5.1.0

# Image ML
torch>=2.2.0
torchvision>=0.17.0
pillow>=10.2.0
opencv-python>=4.9.0
numpy>=1.26.0
segment-anything>=1.0  # SAM
simple-lama-inpainting>=0.1.0  # LaMa

# Audio/Video
openai-whisper>=20231117
pydub>=0.25.1
aiortc>=1.6.0  # WebRTC

# Utilities
loguru>=0.7.0
tenacity>=8.2.0
```

---

## Server Requirements

**Minimum (same server, CPU-only ML):**
- 8GB RAM (16GB recommended)
- 4 CPU cores
- 50GB storage (for models)

**Recommended (GPU acceleration):**
- 16GB RAM
- GPU with 8GB+ VRAM (RTX 3060 or better)
- 100GB SSD storage

**Note:** Heavy ML operations (Stable Diffusion, large SAM model) benefit significantly from GPU. Consider:
1. Start with smaller models (SAM ViT-B instead of ViT-H)
2. Use cloud GPU for heavy operations (Lambda Labs, RunPod)
3. Upgrade server later if needed

---

## Verification Plan

1. **Health Check Test:**
   ```bash
   curl http://127.0.0.1:8000/health
   ```

2. **Laravel Integration Test:**
   ```php
   it('can reach python service', function () {
       $service = app(PythonBridgeService::class);
       expect($service->healthCheck())->toBeTrue();
   });
   ```

3. **Image Processing Test:**
   ```php
   it('processes image through python ML', function () {
       $photo = EstimatePhoto::factory()->create();
       ProcessImageMLJob::dispatch($photo, ['enhance', 'detect_substrate']);
       // Assert job completes and photo is updated
   });
   ```

4. **Scraping Test:**
   ```php
   it('scrapes zillow listing', function () {
       $result = app(ScrapingService::class)->scrapeZillow($testUrl);
       expect($result)->toHaveKeys(['address', 'price', 'bedrooms']);
   });
   ```

---

## Risk Mitigation

| Risk | Mitigation |
|------|------------|
| Real estate sites block scraping | Use proxies, stealth, rate limits; have API backup |
| ML models too large for server | Start with smaller models; use cloud GPU |
| Real-time video latency | Optimize encoding; consider CDN |
| Python service crashes | Supervisor restart; health monitoring |
| High memory usage | Queue large jobs; process sequentially |

---

## Next Steps

After approval:
1. Create Phase 1 foundation files
2. Set up Python environment
3. Test basic Laravel ↔ Python communication
4. Incrementally add ML capabilities
