# Research: Laravel-Python Integration Options

*Generated: 2026-01-28*

## Requirements

The user needs to integrate Python capabilities with their Laravel 12 application for:
1. **Web Scraping Tool** - Scraping dynamic and static websites
2. **ML Photo Manipulation** - Machine learning-based image processing
3. **Audio/Video Processing** - Media conversion and manipulation

---

## Integration Architectures Evaluated

### Architecture 1: Direct Script Execution (Symfony Process)

**Description**: Execute Python scripts directly from Laravel using Symfony's Process component (already included in Laravel).

**How it Works**:
```php
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

$process = new Process(['python3', '/path/to/script.py', $argument]);
$process->run();

if (!$process->isSuccessful()) {
    throw new ProcessFailedException($process);
}

$output = $process->getOutput();
```

**Pros**:
- No additional infrastructure required
- Simple to implement for small tasks
- Already included in Laravel (no extra packages)
- Direct control over script execution
- Good for synchronous, quick operations

**Cons**:
- Blocking execution (unless combined with queues)
- Limited scalability
- Process timeout issues for long-running tasks
- Security concerns with command injection (must sanitize inputs)
- No persistent Python environment (cold start for each call)

**Best For**: Simple, quick Python tasks; validation scripts; one-off processing

**Source**: [Symfony Process Component](https://symfony.com/doc/current/components/process.html), [42coders Tutorial](https://42coders.com/symfony-process-with-laravel)

---

### Architecture 2: Python REST API Microservice (FastAPI/Flask)

**Description**: Create a separate Python microservice that exposes REST APIs, which Laravel calls via HTTP.

**How it Works**:
```
Laravel App  --HTTP-->  Python FastAPI Service  ---->  ML/Processing
     |                         |
     |<---JSON Response--------|
```

**FastAPI Example**:
```python
from fastapi import FastAPI, UploadFile
import cv2

app = FastAPI()

@app.post("/process-image")
async def process_image(file: UploadFile):
    # ML processing here
    return {"result": processed_data}
```

**Laravel Integration**:
```php
$response = Http::attach('file', $imageContent, 'image.jpg')
    ->post('http://python-service:8000/process-image');
```

**Pros**:
- Clean separation of concerns
- Independently scalable
- Python service can use async (FastAPI is very fast)
- Multiple Laravel apps can share the Python service
- Easier to test and maintain independently
- Can use Docker for isolation

**Cons**:
- Additional infrastructure (separate server/container)
- Network latency overhead
- More complex deployment
- Need to handle service discovery and health checks

**Best For**: Production ML workloads, reusable processing services, team separation

**Sources**: [FastAPI Docs](https://fastapi.tiangolo.com/), [NVIDIA ML Microservice Guide](https://developer.nvidia.com/blog/building-a-machine-learning-microservice-with-fastapi/)

---

### Architecture 3: Message Queue (RabbitMQ/Redis)

**Description**: Laravel dispatches jobs to a queue, Python workers consume and process them asynchronously.

**How it Works**:
```
Laravel  --dispatch-->  Redis/RabbitMQ Queue  <--consume--  Python Worker
                              |
                        Results stored in DB or returned via callback
```

**Laravel RabbitMQ Packages**:
- `vyuldashev/laravel-queue-rabbitmq` - Most popular, supports Laravel Horizon
- `iamfarhad/laravel-rabbitmq` - Connection pooling, async processing

**Python Worker Options**:
- `python-laravel-queue` - Direct Laravel queue integration (Redis only, beta)
- `pika` - RabbitMQ Python client
- `Celery` - Distributed task queue for Python

**Pros**:
- True asynchronous processing
- Highly scalable (add more workers)
- Fault-tolerant (messages persist in queue)
- Decoupled architecture
- Perfect for long-running tasks (video/audio processing)
- Works with Laravel Horizon for monitoring

**Cons**:
- Additional infrastructure (message broker)
- More complex architecture
- Need to handle message serialization/deserialization
- Result retrieval requires additional pattern (callbacks, polling, or pub/sub)

**Best For**: Video/audio processing, batch jobs, high-volume processing

**Sources**: [Laravel Queue Docs](https://laravel.com/docs/12.x/queues), [vyuldashev/laravel-queue-rabbitmq](https://github.com/vyuldashev/laravel-queue-rabbitmq), [Fly.io Laravel Queues with Other Languages](https://fly.io/laravel-bytes/queues-with-other-languages/)

---

### Architecture 4: Celery + Redis (Python Task Queue)

**Description**: Use Python's Celery distributed task queue with Redis as the broker, triggered from Laravel.

**How it Works**:
```php
// Laravel dispatches to Celery via Redis
use Massivescale\Celery\Celery;

$celery = new Celery('redis://localhost:6379/0');
$result = $celery->PostTask('tasks.process_video', [$videoPath]);
```

**Pros**:
- Production-proven Python task queue
- Excellent for ML/data science workloads
- Built-in retry, monitoring, scheduling
- Scales horizontally
- Used by Instagram, Robinhood, etc.

**Cons**:
- Requires Redis or RabbitMQ
- PHP Celery client is less maintained
- Need Python infrastructure expertise
- More complex than direct execution

**Best For**: Data-heavy Python workloads, ML pipelines, when Python is primary processing

**Source**: [Laravel + Celery Tutorial](https://blog.fraserreed.com/php/laravel/redis/python/celery/docker/2018/09/28/sending-laravel-task-messages-to-a-python-celery-worker.html)

---

### Architecture 5: Laravel Package (robertogallea/LaravelPython)

**Description**: A dedicated Laravel package for running Python scripts.

**Installation**:
```bash
composer require robertogallea/laravel-python
```

**Usage**:
```php
use Robertogallea\LaravelPython\Services\LaravelPython;

$python = new LaravelPython();
$result = $python->run('/path/to/script.py', ['arg1', 'arg2']);
```

**Package Status**:
- **GitHub Stars**: 72
- **Maintenance**: Appears inactive (no recent commits)
- **Laravel 12 Compatibility**: Not documented

**Pros**:
- Laravel-native integration
- Simple API
- Service container support

**Cons**:
- Questionable maintenance status
- Laravel 12 compatibility unknown
- Essentially a wrapper around Symfony Process
- Limited features compared to direct Process usage

**Best For**: Quick prototyping if compatibility confirmed

**Source**: [GitHub - LaravelPython](https://github.com/robertogallea/LaravelPython)

---

## Use Case Analysis

### 1. Web Scraping Tool

**Recommended Architecture**: REST API Microservice (FastAPI)

**Python Libraries to Use**:
- **Scrapy** - High-performance scraping framework (31 seconds for 1000 pages vs Selenium's 156 seconds)
- **Selenium** - For JavaScript-heavy dynamic sites
- **BeautifulSoup** - HTML parsing
- **Playwright** - Modern browser automation

**Why Microservice**:
- Scraping can be long-running (timeout issues with direct execution)
- Can queue multiple scrape jobs
- Python has superior scraping ecosystem
- Can scale scrapers independently

**Implementation Pattern**:
```
User Request --> Laravel --> Queue Job --> FastAPI Scraper Service --> Results to DB
                                |
                          Redis/RabbitMQ
```

**Sources**: [ZenRows Selenium Guide](https://www.zenrows.com/blog/selenium-python-web-scraping), [Scrapfly Scrapy-Selenium](https://scrapfly.io/blog/posts/web-scraping-dynamic-web-pages-with-scrapy-selenium)

---

### 2. ML Photo Manipulation

**Recommended Architecture**: REST API Microservice (FastAPI) or Direct Execution for simple tasks

**Python Libraries to Use**:
- **OpenCV** - Advanced image processing, object detection
- **Pillow (PIL)** - Simple image manipulation
- **TensorFlow/PyTorch** - Deep learning models
- **scikit-image** - Scientific image processing

**Why Microservice**:
- ML models need to stay loaded in memory (cold start is slow)
- FastAPI keeps models warm between requests
- GPU access is easier to configure on dedicated service
- Can use batch processing for multiple images

**Alternative - Direct Execution**:
For simple tasks (blur detection, resize, format conversion), direct Symfony Process execution works fine.

**Example - Blur Detection with Direct Execution**:
```php
$process = new Process(['python3', 'blur_detection.py', $imagePath]);
$process->run();
$isBlurry = json_decode($process->getOutput())->is_blurry;
```

**Sources**: [TechSolutionStuff Tutorial](https://techsolutionstuff.com/post/integrating-python-image-processing-with-laravel), [Blurry Image Detection in Laravel](https://dev.to/afiqiqmal/blurry-image-detection-in-laravel-4d8d)

---

### 3. Audio/Video Processing

**Recommended Architecture**: Message Queue + Laravel FFmpeg Package

**Option A - PHP-Native (Recommended for Most Cases)**:

Laravel has excellent FFmpeg integration without Python:

```bash
composer require pbmedia/laravel-ffmpeg
```

```php
FFMpeg::fromDisk('videos')
    ->open('input.mp4')
    ->export()
    ->toDisk('converted')
    ->inFormat(new X264)
    ->save('output.mp4');
```

**Why PHP-Native**:
- protonemedia/laravel-ffmpeg is actively maintained
- Full Laravel queue integration
- Supports HLS, encryption, complex filters
- No Python overhead needed

**Option B - Python for Advanced ML Processing**:

When you need ML-based audio/video processing (speech recognition, video analysis), use queue architecture:

**Python Libraries**:
- **MoviePy** - Video editing
- **Whisper** - Speech-to-text (OpenAI)
- **Librosa** - Audio analysis
- **PyDub** - Audio manipulation

**Sources**: [protonemedia/laravel-ffmpeg](https://github.com/protonemedia/laravel-ffmpeg), [Quantizd FFmpeg Tutorial](https://quantizd.com/transcoding-videos-using-ffmpeg-and-laravel-queues/)

---

## Recommendation Summary

| Use Case | Recommended Architecture | Complexity | Scalability |
|----------|-------------------------|------------|-------------|
| Web Scraping | FastAPI Microservice | Medium | High |
| Simple Image Processing | Direct Execution (Symfony Process) | Low | Low |
| ML Image Processing | FastAPI Microservice | Medium | High |
| Video Transcoding | Laravel FFmpeg + Queue | Low | High |
| ML Video/Audio | RabbitMQ + Python Workers | High | Very High |

---

## Recommended Stack for Apex Negotiations

Given the requirements, here is the recommended approach:

### Primary Recommendation: Hybrid Approach

1. **Web Scraping**: FastAPI microservice with Scrapy/Selenium
2. **ML Photo Manipulation**: FastAPI microservice with OpenCV/TensorFlow
3. **Audio/Video Processing**: Laravel FFmpeg package (PHP-native)

### Infrastructure Requirements

```
apexnegotiations.com/
├── Laravel Application (main)
├── python-services/
│   ├── scraper-service/      # FastAPI + Scrapy
│   │   ├── Dockerfile
│   │   ├── requirements.txt
│   │   └── app/
│   └── ml-service/           # FastAPI + OpenCV/TensorFlow
│       ├── Dockerfile
│       ├── requirements.txt
│       └── app/
└── docker-compose.yml        # Orchestration
```

### Communication Pattern

```
Laravel App
    |
    |---> HTTP (sync) --> FastAPI ML Service (quick image ops)
    |
    |---> Queue Job --> RabbitMQ --> Python Scraper Worker (long scrapes)
    |
    |---> Queue Job --> Laravel Worker --> FFmpeg (video processing)
```

---

## Additional Laravel AI/ML Packages to Consider

| Package | Purpose | Status |
|---------|---------|--------|
| [Prism](https://prismphp.com/) | LLM Integration (OpenAI, Anthropic) | Active, Production-ready |
| [Rubix ML](https://github.com/RubixML) | PHP-native ML (40+ algorithms) | Active |
| [openai-php/laravel](https://github.com/openai-php/laravel) | OpenAI API wrapper | Active |
| Laravel MCP | AI tooling from Laravel team | Official |

---

## Sources

- [Developer Guide: Integrating Laravel with Python](https://medium.com/@khouloud.haddad/developer-guide-integrating-laravel-with-python-fed560afd38e)
- [Symfony Process Component](https://symfony.com/doc/current/components/process.html)
- [Integrating Python Machine Learning with Laravel 11](https://techsolutionstuff.com/post/integrating-python-machine-learning-with-laravel-11)
- [Laravel Queues with Other Languages](https://fly.io/laravel-bytes/queues-with-other-languages/)
- [GitHub - LaravelPython](https://github.com/robertogallea/LaravelPython)
- [FastAPI Documentation](https://fastapi.tiangolo.com/)
- [vyuldashev/laravel-queue-rabbitmq](https://github.com/vyuldashev/laravel-queue-rabbitmq)
- [protonemedia/laravel-ffmpeg](https://github.com/protonemedia/laravel-ffmpeg)
- [Laravel + Celery Tutorial](https://blog.fraserreed.com/php/laravel/redis/python/celery/docker/2018/09/28/sending-laravel-task-messages-to-a-python-celery-worker.html)
- [Transcoding Videos with FFmpeg using Laravel Queues](https://quantizd.com/transcoding-videos-using-ffmpeg-and-laravel-queues/)
- [ZenRows Selenium Web Scraping Guide](https://www.zenrows.com/blog/selenium-python-web-scraping)
- [python-laravel-queue on GitHub](https://github.com/sinanbekar/python-laravel-queue)
- [RabbitMQ with Laravel Guide](https://medium.com/@samsonojugo/using-rabbitmq-in-a-laravel-app-the-ultimate-guide-for-scalable-background-jobs-ebbe88f4a0d2)
- [The Best AI Packages for Laravel (2025)](https://medium.com/@khouloud.haddad/the-best-ai-packages-for-laravel-2025-edition-e8e6da368e04)
