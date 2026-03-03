<?php

namespace App\AI\Tools;

use App\Models\Deal;
use App\Models\User;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Tool as PrismTool;

class SearchDeals
{
    public function __construct(private User $user) {}

    public static function definition(User $user): PrismTool
    {
        $instance = new self($user);

        return Tool::as('search_deals')
            ->for('Search deals by name, customer, or description')
            ->withStringParameter('query', 'Search term to match against deal title')
            ->withStringParameter('status', 'Filter by deal stage (lead, quoted, signed, etc.)', required: false)
            ->using(fn (string $query, ?string $status = null): string => $instance->handle($query, $status));
    }

    public function handle(string $query, ?string $status = null): string
    {
        $results = Deal::query()
            ->where('title', 'like', "%{$query}%")
            ->when($status, fn ($q) => $q->where('stage', $status))
            ->with('customer:id,first_name,last_name')
            ->limit(10)
            ->get()
            ->map(fn (Deal $deal) => [
                'id' => $deal->id,
                'title' => $deal->title,
                'customer' => $deal->customer?->full_name,
                'stage' => $deal->stage,
                'has_job' => $deal->job()->exists(),
                'has_estimates' => $deal->estimates()->exists(),
            ])
            ->toArray();

        return json_encode($results);
    }
}
