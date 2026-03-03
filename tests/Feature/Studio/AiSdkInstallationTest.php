<?php

it('has Prism PHP installed and available', function () {
    expect(class_exists(\Prism\Prism\Prism::class))->toBeTrue();
    expect(class_exists(\Prism\Prism\Facades\Prism::class))->toBeTrue();
    expect(enum_exists(\Prism\Prism\Enums\Provider::class))->toBeTrue();
});

it('has Laravel AI SDK installed and available', function () {
    expect(class_exists(\Laravel\Ai\Ai::class))->toBeTrue();
    expect(class_exists(\Laravel\Ai\AiManager::class))->toBeTrue();
    expect(class_exists(\Laravel\Ai\AnonymousAgent::class))->toBeTrue();
});

it('has Anthropic configured as the default AI provider', function () {
    expect(config('ai.default'))->toBe('anthropic');
    expect(config('ai.providers.anthropic'))->toBeArray();
    expect(config('ai.providers.anthropic.driver'))->toBe('anthropic');
});

it('has Anthropic configured in Prism providers', function () {
    expect(config('prism.providers.anthropic'))->toBeArray();
    expect(config('prism.providers.anthropic.url'))->toBe('https://api.anthropic.com/v1');
});
