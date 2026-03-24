<?php

use Illuminate\Support\Facades\Config;

it('returns direct storage url when bunny cdn is disabled', function () {
    Config::set('services.bunny.enabled', false);
    Config::set('services.bunny.cdn_url', '');

    $url = cdn_image('photos/test.jpg');

    expect($url)->not->toContain('cdn.');
});

it('returns bunny cdn url with quality param when enabled', function () {
    Config::set('services.bunny.enabled', true);
    Config::set('services.bunny.cdn_url', 'https://cdn.example.com');

    $url = cdn_image('photos/test.jpg');

    expect($url)->toStartWith('https://cdn.example.com/photos/test.jpg');
    expect($url)->toContain('quality=80');
});

it('includes width param when specified', function () {
    Config::set('services.bunny.enabled', true);
    Config::set('services.bunny.cdn_url', 'https://cdn.example.com');

    $url = cdn_image('photos/test.jpg', 800);

    expect($url)->toContain('width=800');
    expect($url)->toContain('quality=80');
});

it('includes cache-bust param when updatedAt provided', function () {
    Config::set('services.bunny.enabled', true);
    Config::set('services.bunny.cdn_url', 'https://cdn.example.com');

    $ts = now();
    $url = cdn_image('photos/test.jpg', updatedAt: $ts);

    expect($url)->toContain('v='.$ts->timestamp);
});

it('generates srcset with multiple widths', function () {
    Config::set('services.bunny.enabled', true);
    Config::set('services.bunny.cdn_url', 'https://cdn.example.com');

    $srcset = cdn_srcset('photos/test.jpg', [400, 800]);

    expect($srcset)->toContain('400w');
    expect($srcset)->toContain('800w');
});

it('generates correct bunny token auth url', function () {
    Config::set('services.bunny.enabled', true);
    Config::set('services.bunny.cdn_url', 'https://cdn.example.com');
    Config::set('services.bunny.token_key', 'test-secret-key');

    $url = cdn_private('photos/private.jpg', 60);

    expect($url)->toStartWith('https://cdn.example.com/photos/private.jpg');
    expect($url)->toContain('token=');
    expect($url)->toContain('expires=');
});
