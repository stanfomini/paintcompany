<?php

namespace App\Ai\Gateway;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Gateway\Concerns\HandlesRateLimiting;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\ImageResponse;

class XaiImageEditGateway implements ImageGateway
{
    use HandlesRateLimiting;

    /**
     * Generate or edit an image via the xAI API.
     *
     * Uses /v1/images/edits with JSON body when attachments are present.
     * Uses /v1/images/generations for text-to-image when no attachments.
     * Note: OpenAI SDK's images.edit() is NOT supported (uses multipart),
     * but the xAI native /v1/images/edits endpoint accepts JSON.
     *
     * @param  array<ImageFile>  $attachments
     * @param  '3:2'|'2:3'|'1:1'  $size
     * @param  'low'|'medium'|'high'  $quality
     */
    public function generateImage(
        ImageProvider $provider,
        string $model,
        string $prompt,
        array $attachments = [],
        ?string $size = null,
        ?string $quality = null,
        ?int $timeout = null,
    ): ImageResponse {
        $hasAttachments = count($attachments) > 0;

        Log::info('XaiImageEditGateway::generateImage', [
            'endpoint' => $hasAttachments ? 'edits' : 'generations',
            'mode' => $hasAttachments ? 'edit' : 'generate',
            'has_attachments' => $hasAttachments,
            'attachment_count' => count($attachments),
            'model' => $model,
        ]);

        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'response_format' => 'b64_json',
        ];

        if ($hasAttachments) {
            // xAI uses the same /generations endpoint for editing — just add image field.
            // Single image: {"image": {"url": "data:...", "type": "image_url"}}
            // Multiple: {"images": [{"url": "...", "type": "image_url"}, ...]}
            if (count($attachments) === 1) {
                $payload['image'] = [
                    'url' => $this->toDataUri($attachments[0]),
                    'type' => 'image_url',
                ];
            } else {
                $payload['images'] = array_map(
                    fn (ImageFile $img) => [
                        'url' => $this->toDataUri($img),
                        'type' => 'image_url',
                    ],
                    $attachments
                );
            }
        }

        // xAI uses /v1/images/edits for editing (JSON, not multipart)
        // and /v1/images/generations for text-to-image generation
        $endpoint = $hasAttachments
            ? 'https://api.x.ai/v1/images/edits'
            : 'https://api.x.ai/v1/images/generations';

        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => Http::withToken($provider->providerCredentials()['key'])
                ->timeout($timeout ?? 120)
                ->post($endpoint, $payload)
                ->throw()
        );

        $response = $response->json();

        Log::info('XaiImageEditGateway::response', [
            'has_data' => isset($response['data']),
            'data_count' => count($response['data'] ?? []),
            'has_b64' => isset($response['data'][0]['b64_json']),
            'has_url' => isset($response['data'][0]['url']),
        ]);

        // Handle both b64_json and url response formats
        $imageData = $response['data'][0];

        if (isset($imageData['b64_json'])) {
            $content = $imageData['b64_json'];
        } elseif (isset($imageData['url'])) {
            // If API returns a URL, download it and convert to base64
            $content = base64_encode(Http::timeout(60)->get($imageData['url'])->body());
        } else {
            throw new \RuntimeException('xAI image response contained neither b64_json nor url');
        }

        return new ImageResponse(
            new Collection([
                new GeneratedImage($content, 'image/png'),
            ]),
            new Usage,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Convert an Image file object to a base64 data URI.
     */
    protected function toDataUri(ImageFile $image): string
    {
        $content = $image->content();
        $mime = $image->mimeType() ?? 'image/jpeg';

        return 'data:'.$mime.';base64,'.base64_encode($content);
    }
}
