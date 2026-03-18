<?php

namespace App\Ai\Gateway;

use Closure;
use Generator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;

/**
 * XAI gateway using the Responses API (/v1/responses).
 *
 * This enables web_search, x_search, and function tools together.
 * The Responses API uses `input`/`output` instead of `messages`/`choices`,
 * and supports `previous_response_id` for efficient multi-turn conversations.
 */
class XaiTextGateway implements TextGateway
{
    protected ?Closure $invokingToolCallback = null;

    protected ?Closure $toolInvokedCallback = null;

    /**
     * {@inheritdoc}
     */
    public function generateText(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): TextResponse {
        $inputMessages = $this->buildInput($instructions, $messages);
        $apiTools = $this->buildTools($tools);

        $payload = array_filter([
            'model' => $model,
            'input' => $inputMessages,
            'tools' => ! empty($apiTools) ? $apiTools : null,
        ]);

        return $this->sendRequest($provider, $payload, $tools, $options, $timeout, null, 1);
    }

    /**
     * {@inheritdoc}
     */
    public function streamText(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): Generator {
        $response = $this->generateText($provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout);

        yield $response;
    }

    /**
     * {@inheritdoc}
     */
    public function onToolInvocation(Closure $invoking, Closure $invoked): self
    {
        $this->invokingToolCallback = $invoking;
        $this->toolInvokedCallback = $invoked;

        return $this;
    }

    /**
     * Send a request to the Responses API and handle the agentic tool loop.
     */
    protected function sendRequest(
        TextProvider $provider,
        array $payload,
        array $tools,
        ?TextGenerationOptions $options,
        ?int $timeout,
        ?TextResponse $previousResponse,
        int $currentStep,
    ): TextResponse {
        $key = $provider->providerCredentials()['key'] ?? '';

        $response = Http::withToken($key)
            ->timeout($timeout ?? 120)
            ->post('https://api.x.ai/v1/responses', $payload);

        if ($response->failed()) {
            $body = $response->json();
            Log::error('XAI API error', [
                'status' => $response->status(),
                'body' => $body,
                'model' => $payload['model'] ?? 'unknown',
            ]);

            $errorMessage = $body['error']['message']
                ?? (is_string($body['error'] ?? null) ? $body['error'] : null)
                ?? json_encode($body['error'] ?? $body);

            throw new AiException("XAI Error [{$response->status()}]: {$errorMessage}");
        }

        $data = $response->json();
        $responseId = $data['id'] ?? null;
        $output = $data['output'] ?? [];
        $usage = $data['usage'] ?? [];

        // Extract text content and function calls from output
        $content = '';
        $functionCalls = [];

        foreach ($output as $item) {
            $type = $item['type'] ?? '';

            if ($type === 'message') {
                foreach ($item['content'] ?? [] as $block) {
                    if (($block['type'] ?? '') === 'output_text') {
                        $content .= $block['text'] ?? '';
                    }
                }
            } elseif ($type === 'function_call') {
                $functionCalls[] = $item;
            }
            // web_search_call results are handled server-side by XAI
        }

        $newUsage = new Usage(
            promptTokens: $usage['input_tokens'] ?? 0,
            completionTokens: $usage['output_tokens'] ?? 0,
            reasoningTokens: $usage['output_tokens_details']['reasoning_tokens'] ?? 0,
        );

        $textResponse = new TextResponse(
            $content,
            $previousResponse ? $previousResponse->usage->add($newUsage) : $newUsage,
            new Meta($provider->name(), $data['model'] ?? $payload['model'] ?? 'unknown'),
        );

        // If the model made function calls, execute them and continue
        if (! empty($functionCalls) && ! empty($tools)) {
            $maxSteps = $options?->maxSteps ?? 15;
            if ($currentStep >= $maxSteps) {
                return $textResponse;
            }

            return $this->handleFunctionCalls(
                provider: $provider,
                model: $payload['model'] ?? '',
                responseId: $responseId,
                functionCalls: $functionCalls,
                tools: $tools,
                options: $options,
                timeout: $timeout,
                textResponse: $textResponse,
                currentStep: $currentStep + 1,
            );
        }

        return $textResponse;
    }

    /**
     * Execute function calls and continue the conversation via previous_response_id.
     */
    protected function handleFunctionCalls(
        TextProvider $provider,
        string $model,
        ?string $responseId,
        array $functionCalls,
        array $tools,
        ?TextGenerationOptions $options,
        ?int $timeout,
        TextResponse $textResponse,
        int $currentStep,
    ): TextResponse {
        // Build tool lookup by the snake_case name the API sees
        $toolMap = collect($tools)->keyBy(function (Tool $tool) {
            return Str::snake(class_basename($tool));
        });

        // Execute each function call and collect results
        $toolOutputs = [];

        foreach ($functionCalls as $fc) {
            $callId = $fc['call_id'] ?? $fc['id'] ?? '';
            $name = $fc['name'] ?? '';
            $arguments = json_decode($fc['arguments'] ?? '{}', true) ?? [];

            $tool = $toolMap->get($name);

            if (! $tool) {
                $toolOutputs[] = [
                    'type' => 'function_call_output',
                    'call_id' => $callId,
                    'output' => "Error: Tool '{$name}' not found.",
                ];

                continue;
            }

            if ($this->invokingToolCallback) {
                ($this->invokingToolCallback)($tool, $arguments);
            }

            try {
                $request = new \Laravel\Ai\Tools\Request($arguments);
                $result = (string) $tool->handle($request);
            } catch (\Throwable $e) {
                $result = "Error: {$e->getMessage()}";
            }

            if ($this->toolInvokedCallback) {
                ($this->toolInvokedCallback)($tool, $arguments, $result);
            }

            $toolOutputs[] = [
                'type' => 'function_call_output',
                'call_id' => $callId,
                'output' => $result,
            ];
        }

        // Build the API tools list again for the continuation
        $apiTools = $this->buildTools($tools);

        // Continue conversation with tool results using previous_response_id
        $payload = array_filter([
            'model' => $model,
            'input' => $toolOutputs,
            'tools' => ! empty($apiTools) ? $apiTools : null,
            'previous_response_id' => $responseId,
        ]);

        return $this->sendRequest($provider, $payload, $tools, $options, $timeout, $textResponse, $currentStep);
    }

    /**
     * Convert Laravel AI messages to Responses API input format.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function buildInput(?string $instructions, array $messages): array
    {
        $input = [];

        if ($instructions) {
            $input[] = ['role' => 'developer', 'content' => $instructions];
        }

        foreach ($messages as $message) {
            if ($message instanceof AssistantMessage) {
                $input[] = ['role' => 'assistant', 'content' => $message->content ?? ''];
            } elseif ($message instanceof ToolResultMessage) {
                foreach ($message->toolResults as $result) {
                    $input[] = [
                        'type' => 'function_call_output',
                        'call_id' => $result->id,
                        'output' => is_string($result->result) ? $result->result : json_encode($result->result),
                    ];
                }
            } elseif ($message instanceof Message) {
                $role = $message->role->value ?? (string) $message->role;
                if ($role === 'system') {
                    $role = 'developer';
                }
                if (in_array($role, ['user', 'assistant', 'developer'])) {
                    $input[] = ['role' => $role, 'content' => $message->content ?? ''];
                }
            }
        }

        return $input;
    }

    /**
     * Convert Laravel AI tools to Responses API format.
     *
     * Includes web_search as a built-in tool alongside function tools.
     *
     * @param  Tool[]  $tools
     * @return array<int, array<string, mixed>>
     */
    protected function buildTools(array $tools): array
    {
        // Always include web_search so the model can look things up
        $apiTools = [['type' => 'web_search']];

        if (empty($tools)) {
            return $apiTools;
        }

        $schema = new \Illuminate\JsonSchema\JsonSchemaTypeFactory;

        foreach ($tools as $tool) {
            $toolSchema = $tool->schema($schema);
            $properties = [];
            $required = [];

            foreach ($toolSchema as $name => $type) {
                $typeArray = $type->toArray();
                $properties[$name] = $typeArray;

                if (method_exists($type, 'isRequired') && $type->isRequired()) {
                    $required[] = $name;
                } elseif (isset($typeArray['required']) && $typeArray['required'] === true) {
                    $required[] = $name;
                    unset($properties[$name]['required']);
                }
            }

            // Responses API uses top-level name/description, not nested under "function"
            $apiTools[] = [
                'type' => 'function',
                'name' => Str::snake(class_basename($tool)),
                'description' => (string) $tool->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => ! empty($properties) ? $properties : new \stdClass,
                    'required' => $required,
                ],
            ];
        }

        return $apiTools;
    }
}
