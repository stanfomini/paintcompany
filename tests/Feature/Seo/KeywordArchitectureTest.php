<?php

arch('keyword resolver depends on the cache facade')
    ->expect('App\Services\Seo\KeywordResolver')
    ->toUse(['Illuminate\Support\Facades\Cache']);

arch('keyword actions live under App\Actions\Seo')
    ->expect('App\Actions\Seo')
    ->toBeClasses();

arch('observer registered via attribute')
    ->expect('App\Observers\KeywordPageAssignmentObserver')
    ->toBeClass();

test('there is no class-based App\Livewire\Seo namespace (SFC pattern enforced)', function () {
    expect(is_dir(app_path('Livewire/Seo')))->toBeFalse();
});
