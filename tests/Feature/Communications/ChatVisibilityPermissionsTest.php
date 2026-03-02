<?php

use App\Models\ChatThread;

it('has source constants on ChatThread', function () {
    expect(ChatThread::SOURCE_WEBSITE_LEAD)->toBe('website_lead');
    expect(ChatThread::SOURCE_SETTER_SUPPORT)->toBe('setter_support');
});

it('allows source in fillable', function () {
    $thread = ChatThread::factory()->support()->create(['source' => 'website_lead']);
    expect($thread->source)->toBe('website_lead');
});
