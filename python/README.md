# Apex Python Service

FastAPI-based ML and processing service for Painting Made Easy Denver CRM.

## Features

- **Image ML Processing**: Segmentation, inpainting, substrate detection, recoloring
- **Web Scraping**: Zillow, Realtor.com, MLS listings
- **Media Processing**: Audio transcription (Whisper), video streaming (RTMP/HLS)

## Setup

### 1. Create Virtual Environment

```bash
cd python
python -m venv venv
source venv/bin/activate  # Linux/Mac
# or
.\venv\Scripts\activate   # Windows
```

### 2. Install Dependencies

```bash
# Core only (minimal)
pip install -r requirements.txt

# Or with specific features
pip install -e ".[scraping]"   # Add scraping
pip install -e ".[ml]"         # Add ML
pip install -e ".[media]"      # Add media
pip install -e ".[all]"        # Everything
```

### 3. Install Playwright (for scraping)

```bash
playwright install chromium
```

### 4. Download ML Models (for image processing)

Models will be downloaded automatically on first use, or you can pre-download:

```bash
# SAM model
wget https://dl.fbaipublicfiles.com/segment_anything/sam_vit_b_01ec64.pth -O models/sam_vit_b.pth

# Whisper model (downloaded automatically by openai-whisper)
```

## Running

### Development

```bash
cd python
uvicorn api.main:app --reload --host 127.0.0.1 --port 8000
```

### Production (systemd)

Create `/etc/systemd/system/apex-python.service`:

```ini
[Unit]
Description=Apex Python Service
After=network.target

[Service]
User=forge
WorkingDirectory=/home/forge/apexnegotiations.com/python
Environment="PATH=/home/forge/apexnegotiations.com/python/venv/bin"
ExecStart=/home/forge/apexnegotiations.com/python/venv/bin/uvicorn api.main:app --host 127.0.0.1 --port 8000
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Then:

```bash
sudo systemctl daemon-reload
sudo systemctl enable apex-python
sudo systemctl start apex-python
```

## API Endpoints

### Health
- `GET /health` - Basic health check
- `GET /health/detailed` - Detailed status with component info

### Images
- `POST /images/process` - Process image with multiple operations
- `POST /images/segment` - SAM segmentation
- `POST /images/inpaint` - LaMa inpainting (object removal)
- `POST /images/recolor` - Surface recoloring
- `POST /images/detect-substrate` - Detect paint surface type

### Scraping
- `POST /scrape/listing` - Scrape single listing
- `POST /scrape/search` - Scrape search results
- `POST /scrape/listing/async` - Queue async scraping job
- `GET /scrape/job/{job_id}` - Check job status

### Media
- `POST /media/transcribe` - Transcribe audio (Whisper)
- `POST /media/stream/start` - Start video stream
- `WS /media/ws/audio` - WebSocket for real-time audio
- `WS /media/ws/video` - WebSocket for WebRTC signaling

## Testing

```bash
cd python
pytest
```

## Laravel Integration

Enable in Laravel `.env`:

```env
PYTHON_ENABLED=true
PYTHON_SERVICE_URL=http://127.0.0.1:8000
```

Use in Laravel:

```php
use App\Services\PythonBridgeService;

$python = app(PythonBridgeService::class);

// Check health
$python->healthCheck(); // true/false

// Process image
$result = $python->processImage($imageUrl, ['enhance', 'detect_substrate']);

// Scrape listing
$data = $python->scrapeListing('https://www.zillow.com/...');

// Transcribe audio
$text = $python->transcribeAudio($audioUrl);
```
