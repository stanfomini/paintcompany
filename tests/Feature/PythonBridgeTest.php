<?php

use App\Services\PythonBridgeService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['python.enabled' => true]);
    config(['python.service_url' => 'http://127.0.0.1:8000']);
});

describe('PythonBridgeService', function () {
    describe('configuration', function () {
        it('reports disabled when python is not enabled', function () {
            config(['python.enabled' => false]);

            $service = new PythonBridgeService;

            expect($service->isEnabled())->toBeFalse();
            expect($service->healthCheck())->toBeFalse();
        });

        it('reports enabled when python is enabled', function () {
            $service = new PythonBridgeService;

            expect($service->isEnabled())->toBeTrue();
        });
    });

    describe('health check', function () {
        it('returns true when service is healthy', function () {
            Http::fake([
                '*/health' => Http::response([
                    'status' => 'healthy',
                    'service' => 'apex-python-service',
                    'version' => '0.1.0',
                ]),
            ]);

            $service = new PythonBridgeService;
            $result = $service->healthCheck();

            expect($result)->toBeTrue();
        });

        it('returns false when service is unhealthy', function () {
            Http::fake([
                '*/health' => Http::response([
                    'status' => 'unhealthy',
                ]),
            ]);

            $service = new PythonBridgeService;
            $result = $service->healthCheck();

            expect($result)->toBeFalse();
        });

        it('returns false when service is unreachable', function () {
            Http::fake([
                '*/health' => Http::response(null, 500),
            ]);

            $service = new PythonBridgeService;
            $result = $service->healthCheck();

            expect($result)->toBeFalse();
        });

        it('returns detailed health information', function () {
            Http::fake([
                '*/health/detailed' => Http::response([
                    'status' => 'healthy',
                    'service' => 'apex-python-service',
                    'version' => '0.1.0',
                    'components' => [
                        'ml' => ['status' => 'available'],
                        'scraping' => ['status' => 'available'],
                        's3' => ['status' => 'configured'],
                    ],
                ]),
            ]);

            $service = new PythonBridgeService;
            $result = $service->detailedHealthCheck();

            expect($result['status'])->toBe('healthy');
            expect($result['components'])->toHaveKey('ml');
            expect($result['components'])->toHaveKey('scraping');
        });
    });

    describe('image processing', function () {
        it('sends process image request', function () {
            Http::fake([
                '*/images/process' => Http::response([
                    'success' => true,
                    'result_url' => 'https://s3.example.com/processed.jpg',
                    'operations_applied' => ['enhance', 'detect_substrate'],
                ]),
            ]);

            $service = new PythonBridgeService;
            $result = $service->processImage(
                'https://example.com/image.jpg',
                ['enhance', 'detect_substrate']
            );

            expect($result['success'])->toBeTrue();
            expect($result['operations_applied'])->toContain('enhance');

            Http::assertSent(function ($request) {
                return $request->url() === 'http://127.0.0.1:8000/images/process'
                    && $request['image_url'] === 'https://example.com/image.jpg'
                    && $request['operations'] === ['enhance', 'detect_substrate'];
            });
        });

        it('sends segment image request', function () {
            Http::fake([
                '*/images/segment' => Http::response([
                    'success' => true,
                    'result_url' => 'https://s3.example.com/segmented.jpg',
                ]),
            ]);

            $service = new PythonBridgeService;
            $result = $service->segmentImage(
                'https://example.com/image.jpg',
                'wall surfaces'
            );

            expect($result['success'])->toBeTrue();

            Http::assertSent(function ($request) {
                return str_contains($request->url(), '/images/segment')
                    && $request['prompt'] === 'wall surfaces';
            });
        });

        it('sends detect substrate request', function () {
            Http::fake([
                '*/images/detect-substrate' => Http::response([
                    'success' => true,
                    'substrate' => 'drywall',
                    'confidence' => 0.94,
                ]),
            ]);

            $service = new PythonBridgeService;
            $result = $service->detectSubstrate('https://example.com/wall.jpg');

            expect($result['success'])->toBeTrue();
            expect($result['substrate'])->toBe('drywall');
            expect($result['confidence'])->toBe(0.94);
        });

        it('sends recolor request', function () {
            Http::fake([
                '*/images/recolor' => Http::response([
                    'success' => true,
                    'result_url' => 'https://s3.example.com/recolored.jpg',
                ]),
            ]);

            $service = new PythonBridgeService;
            $result = $service->recolorSurface(
                'https://example.com/room.jpg',
                '#4A90A4',
                'https://example.com/mask.png',
                true
            );

            expect($result['success'])->toBeTrue();

            Http::assertSent(function ($request) {
                return str_contains($request->url(), '/images/recolor')
                    && $request['target_color'] === '#4A90A4'
                    && $request['preserve_texture'] === true;
            });
        });
    });

    describe('scraping', function () {
        it('sends scrape listing request', function () {
            Http::fake([
                '*/scrape/listing' => Http::response([
                    'success' => true,
                    'data' => [
                        'address' => '123 Main St',
                        'price' => 450000,
                        'bedrooms' => 3,
                    ],
                ]),
            ]);

            $service = new PythonBridgeService;
            $result = $service->scrapeListing(
                'https://www.zillow.com/homedetails/123-main-st',
                'zillow'
            );

            expect($result['success'])->toBeTrue();
            expect($result['data']['address'])->toBe('123 Main St');

            Http::assertSent(function ($request) {
                return str_contains($request->url(), '/scrape/listing')
                    && $request['source'] === 'zillow';
            });
        });

        it('sends scrape search request', function () {
            Http::fake([
                '*/scrape/search' => Http::response([
                    'success' => true,
                    'listings' => [
                        ['address' => '123 Main St'],
                        ['address' => '456 Oak Ave'],
                    ],
                    'total_found' => 2,
                ]),
            ]);

            $service = new PythonBridgeService;
            $result = $service->scrapeSearch(
                'zillow',
                'Austin, TX',
                ['min_price' => 300000],
                25
            );

            expect($result['success'])->toBeTrue();
            expect($result['listings'])->toHaveCount(2);

            Http::assertSent(function ($request) {
                return str_contains($request->url(), '/scrape/search')
                    && $request['location'] === 'Austin, TX'
                    && $request['max_results'] === 25;
            });
        });
    });

    describe('media', function () {
        it('sends transcribe audio request', function () {
            Http::fake([
                '*/media/transcribe' => Http::response([
                    'success' => true,
                    'text' => 'Hello, this is a test transcription.',
                    'language' => 'en',
                    'duration' => 5.2,
                ]),
            ]);

            $service = new PythonBridgeService;
            $result = $service->transcribeAudio(
                'https://example.com/audio.mp3',
                'base',
                'en'
            );

            expect($result['success'])->toBeTrue();
            expect($result['text'])->toContain('test transcription');

            Http::assertSent(function ($request) {
                return str_contains($request->url(), '/media/transcribe')
                    && $request['model'] === 'base';
            });
        });

        it('sends start stream request', function () {
            Http::fake([
                '*/media/stream/start' => Http::response([
                    'success' => true,
                    'stream_id' => 'abc123',
                    'rtmp_url' => 'rtmp://localhost/live/abc123',
                    'hls_url' => 'https://example.com/hls/abc123.m3u8',
                ]),
            ]);

            $service = new PythonBridgeService;
            $result = $service->startStream([
                'resolution' => '1080p',
                'bitrate' => 4000,
            ]);

            expect($result['success'])->toBeTrue();
            expect($result['stream_id'])->toBe('abc123');
        });
    });

    describe('error handling', function () {
        it('handles service errors gracefully', function () {
            Http::fake([
                '*/images/process' => Http::response([
                    'detail' => 'Model not loaded',
                ], 500),
            ]);

            $service = new PythonBridgeService;
            $result = $service->processImage('https://example.com/image.jpg', ['enhance']);

            expect($result['success'])->toBeFalse();
            expect($result)->toHaveKey('error');
        });

        it('throws exception when service is disabled', function () {
            config(['python.enabled' => false]);

            $service = new PythonBridgeService;

            expect(fn () => $service->processImage('url', ['enhance']))
                ->toThrow(\RuntimeException::class);
        });
    });
});
