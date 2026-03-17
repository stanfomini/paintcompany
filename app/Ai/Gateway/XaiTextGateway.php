<?php

namespace App\Ai\Gateway;

use Closure;
use Generator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
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
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;

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
        $apiMessages = $this->buildMessages($instructions, $messages);
        $apiTools = $this->buildTools($tools);

        $payload = array_filter([
            'model' => $model,
            'messages' => $apiMessages,
            'max_tokens' => $options?->maxTokens ?? 4096,
            'tools' => ! empty($apiTools) ? $apiTools : null,
        ]);

        $key = $provider->providerCredentials()['key'] ?? '';

        $response = Http::withToken($key)
            ->timeout($timeout ?? 120)
            ->post('https://api.x.ai/v1/chat/completions', $payload);

        if ($response->failed()) {
            $body = $response->json();
            \Illuminate\Support\Facades\Log::error('XAI API error', [
                'status' => $response->status(),
                'body' => $body,
                'model' => $model,
            ]);

            $errorMessage = $body['error']['message']
                ?? (is_string($body['error'] ?? null) ? $body['error'] : null)
                ?? json_encode($body['error'] ?? $body);

            throw new AiException("XAI Error [{$response->status()}]: {$errorMessage}");
        }

        $data = $response->json();
        $choice = $data['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        $content = $message['content'] ?? '';
        $toolCalls = $message['tool_calls'] ?? [];
        $usage = $data['usage'] ?? [];

        $responseToolCalls = collect($toolCalls)->map(function (array $tc) {
            return new ToolCall(
                id: $tc['id'],
                name: $tc['function']['name'],
                arguments: json_decode($tc['function']['arguments'], true) ?? [],
            );
        });

        $textResponse = new TextResponse(
            $content,
            new Usage(
                promptTokens: $usage['prompt_tokens'] ?? 0,
                completionTokens: $usage['completion_tokens'] ?? 0,
                reasoningTokens: $usage['completion_tokens_details']['reasoning_tokens'] ?? 0,
            ),
            new Meta($provider->name(), $data['model'] ?? $model),
        );

        // If the model made tool calls, execute them and continue the conversation
        if ($responseToolCalls->isNotEmpty() && ! empty($tools)) {
            return $this->handleToolCalls(
                provider: $provider,
                model: $model,
                instructions: $instructions,
                messages: $messages,
                tools: $tools,
                schema: $schema,
                options: $options,
                timeout: $timeout,
                content: $content,
                toolCalls: $responseToolCalls,
                textResponse: $textResponse,
                currentStep: 1,
            );
        }

        return $textResponse;
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
        // For now, fall back to non-streaming
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
     * Execute tool calls and continue the agentic loop.
     */
    protected function handleToolCalls(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        string $content,
        Collection $toolCalls,
        TextResponse $textResponse,
        int $currentStep,
    ): TextResponse {
        $maxSteps = $options?->maxSteps ?? 15;

        // Build tool lookup by the snake_case name the API sees
        $toolMap = collect($tools)->keyBy(function (Tool $tool) {
            return Str::snake(class_basename($tool));
        });

        // Execute each tool call
        $toolResults = new Collection;
        foreach ($toolCalls as $toolCall) {
            $tool = $toolMap->get($toolCall->name);

            if (! $tool) {
                $toolResults->push([
                    'tool_call_id' => $toolCall->id,
                    'role' => 'tool',
                    'content' => "Error: Tool '{$toolCall->name}' not found.",
                ]);

                continue;
            }

            // Dispatch invoking callback with the actual Tool object
            if ($this->invokingToolCallback) {
                ($this->invokingToolCallback)($tool, $toolCall->arguments);
            }

            try {
                $request = new \Laravel\Ai\Tools\Request($toolCall->arguments);
                $result = (string) $tool->handle($request);
            } catch (\Throwable $e) {
                $result = "Error: {$e->getMessage()}";
            }

            // Dispatch invoked callback with the actual Tool object
            if ($this->toolInvokedCallback) {
                ($this->toolInvokedCallback)($tool, $toolCall->arguments, $result);
            }

            $toolResults->push([
                'tool_call_id' => $toolCall->id,
                'role' => 'tool',
                'content' => $result,
            ]);
        }

        // Build updated message history with assistant response + tool results
        $updatedMessages = array_merge($messages, [
            new AssistantMessage($content, $toolCalls),
            new ToolResultMessage($toolResults->map(function (array $tr) use ($toolCalls) {
                $tc = $toolCalls->first(fn (ToolCall $tc) => $tc->id === $tr['tool_call_id']);

                return new \Laravel\Ai\Responses\Data\ToolResult(
                    id: $tr['tool_call_id'],
                    name: $tc?->name ?? 'unknown',
                    arguments: $tc?->arguments ?? [],
                    result: $tr['content'],
                );
            })),
        ]);

        // Check if we've hit max steps
        if ($currentStep >= $maxSteps) {
            return $textResponse;
        }

        // Continue the conversation with tool results
        return $this->continueWithToolResults(
            provider: $provider,
            model: $model,
            instructions: $instructions,
            messages: $updatedMessages,
            tools: $tools,
            schema: $schema,
            options: $options,
            timeout: $timeout,
            previousResponse: $textResponse,
            currentStep: $currentStep + 1,
        );
    }

    /**
     * Continue the conversation after tool results.
     */
    protected function continueWithToolResults(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        TextResponse $previousResponse,
        int $currentStep,
    ): TextResponse {
        $apiMessages = $this->buildMessages($instructions, $messages);
        $apiTools = $this->buildTools($tools);

        $payload = array_filter([
            'model' => $model,
            'messages' => $apiMessages,
            'max_tokens' => $options?->maxTokens ?? 4096,
            'tools' => ! empty($apiTools) ? $apiTools : null,
        ]);

        $key = $provider->providerCredentials()['key'] ?? '';

        $response = Http::withToken($key)
            ->timeout($timeout ?? 120)
            ->post('https://api.x.ai/v1/chat/completions', $payload);

        if ($response->failed()) {
            $body = $response->json();
            \Illuminate\Support\Facades\Log::error('XAI API error', [
                'status' => $response->status(),
                'body' => $body,
                'model' => $model,
            ]);

            $errorMessage = $body['error']['message']
                ?? (is_string($body['error'] ?? null) ? $body['error'] : null)
                ?? json_encode($body['error'] ?? $body);

            throw new AiException("XAI Error [{$response->status()}]: {$errorMessage}");
        }

        $data = $response->json();
        $choice = $data['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        $content = $message['content'] ?? '';
        $newToolCalls = $message['tool_calls'] ?? [];
        $usage = $data['usage'] ?? [];

        $responseToolCalls = collect($newToolCalls)->map(function (array $tc) {
            return new ToolCall(
                id: $tc['id'],
                name: $tc['function']['name'],
                arguments: json_decode($tc['function']['arguments'], true) ?? [],
            );
        });

        $newUsage = new Usage(
            promptTokens: $usage['prompt_tokens'] ?? 0,
            completionTokens: $usage['completion_tokens'] ?? 0,
            reasoningTokens: $usage['completion_tokens_details']['reasoning_tokens'] ?? 0,
        );

        $textResponse = new TextResponse(
            $content,
            $previousResponse->usage->add($newUsage),
            new Meta($provider->name(), $data['model'] ?? $model),
        );

        // If there are more tool calls, continue the loop
        if ($responseToolCalls->isNotEmpty() && ! empty($tools)) {
            return $this->handleToolCalls(
                provider: $provider,
                model: $model,
                instructions: $instructions,
                messages: $messages,
                tools: $tools,
                schema: $schema,
                options: $options,
                timeout: $timeout,
                content: $content,
                toolCalls: $responseToolCalls,
                textResponse: $textResponse,
                currentStep: $currentStep,
            );
        }

        return $textResponse;
    }

    /**
     * Convert Laravel AI messages to xAI API format.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function buildMessages(?string $instructions, array $messages): array
    {
        $apiMessages = [];

        if ($instructions) {
            $apiMessages[] = ['role' => 'system', 'content' => $instructions];
        }

        foreach ($messages as $message) {
            if ($message instanceof AssistantMessage) {
                $msg = ['role' => 'assistant', 'content' => $message->content ?? ''];

                if ($message->toolCalls->isNotEmpty()) {
                    $msg['tool_calls'] = $message->toolCalls->map(function (ToolCall $tc) {
                        return [
                            'id' => $tc->id,
                            'type' => 'function',
                            'function' => [
                                'name' => $tc->name,
                                'arguments' => json_encode($tc->arguments),
                            ],
                        ];
                    })->values()->all();
                }

                $apiMessages[] = $msg;
            } elseif ($message instanceof ToolResultMessage) {
                foreach ($message->toolResults as $result) {
                    $apiMessages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $result->id,
                        'content' => is_string($result->result) ? $result->result : json_encode($result->result),
                    ];
                }
            } elseif ($message instanceof Message) {
                $role = $message->role->value ?? (string) $message->role;
                if (in_array($role, ['user', 'assistant', 'system'])) {
                    $apiMessages[] = ['role' => $role, 'content' => $message->content ?? ''];
                }
            }
        }

        return $apiMessages;
    }

    /**
     * Convert Laravel AI tools to xAI API format.
     *
     * @param  Tool[]  $tools
     * @return array<int, array<string, mixed>>
     */
    protected function buildTools(array $tools): array
    {
        if (empty($tools)) {
            return [];
        }

        $schema = new \Illuminate\JsonSchema\JsonSchemaTypeFactory;

        return collect($tools)->map(function (Tool $tool) use ($schema) {
            $toolSchema = $tool->schema($schema);
            $properties = [];
            $required = [];

            foreach ($toolSchema as $name => $type) {
                $typeArray = $type->toArray();
                $properties[$name] = $typeArray;

                // Check if required — the type object has a isRequired or required property
                if (method_exists($type, 'isRequired') && $type->isRequired()) {
                    $required[] = $name;
                } elseif (isset($typeArray['required']) && $typeArray['required'] === true) {
                    $required[] = $name;
                    unset($properties[$name]['required']);
                }
            }

            return [
                'type' => 'function',
                'function' => [
                    'name' => Str::snake(class_basename($tool)),
                    'description' => (string) $tool->description(),
                    'parameters' => [
                        'type' => 'object',
                        'properties' => ! empty($properties) ? $properties : new \stdClass,
                        'required' => $required,
                    ],
                ],
            ];
        })->values()->all();
    }
}
