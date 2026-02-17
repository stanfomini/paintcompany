<?php

namespace App\Automation\Actions;

use App\Automation\Contracts\ActionInterface;
use App\Automation\Registry;
use App\Models\WorkflowRun;
use Illuminate\Support\Facades\Http;

class HTTPRequest implements ActionInterface
{
    public static function getName(): string
    {
        return 'HTTP Request';
    }

    public static function getCategory(): string
    {
        return 'Integrations';
    }

    public static function getDescription(): string
    {
        return 'Makes an HTTP request to an external URL. Supports dynamic values in URL, headers, and body.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'url' => [
                'type' => 'string',
                'label' => 'URL',
                'description' => 'The URL to send the request to. Supports {{dynamic.values}} placeholders.',
                'required' => true,
            ],
            'method' => [
                'type' => 'string',
                'label' => 'HTTP Method',
                'description' => 'The HTTP method to use.',
                'required' => false,
                'default' => 'POST',
                'options' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
            ],
            'headers' => [
                'type' => 'string',
                'label' => 'Headers (JSON)',
                'description' => 'JSON object of headers to send. Supports {{dynamic.values}} placeholders.',
                'required' => false,
            ],
            'body' => [
                'type' => 'string',
                'label' => 'Body (JSON)',
                'description' => 'JSON request body. Supports {{dynamic.values}} placeholders.',
                'required' => false,
            ],
            'timeout' => [
                'type' => 'integer',
                'label' => 'Timeout (seconds)',
                'description' => 'Request timeout in seconds.',
                'required' => false,
                'default' => 30,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function execute(WorkflowRun $run, array $config): array
    {
        $registry = app(Registry::class);

        $url = $registry->resolveDynamicValues($config['url'] ?? '', $run);
        $method = strtoupper($config['method'] ?? 'POST');
        $headersJson = $registry->resolveDynamicValues($config['headers'] ?? '{}', $run);
        $bodyJson = $registry->resolveDynamicValues($config['body'] ?? '{}', $run);
        $timeout = (int) ($config['timeout'] ?? 30);

        $headers = json_decode($headersJson, true) ?: [];
        $body = json_decode($bodyJson, true) ?: [];

        $request = Http::timeout($timeout)->withHeaders($headers);

        $response = match ($method) {
            'GET' => $request->get($url, $body),
            'POST' => $request->post($url, $body),
            'PUT' => $request->put($url, $body),
            'PATCH' => $request->patch($url, $body),
            'DELETE' => $request->delete($url, $body),
            default => $request->post($url, $body),
        };

        return [
            'status_code' => $response->status(),
            'successful' => $response->successful(),
        ];
    }
}
