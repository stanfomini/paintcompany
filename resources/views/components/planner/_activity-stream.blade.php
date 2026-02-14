{{-- Activity stream list with pagination --}}
<div class="border border-zinc-200 dark:border-zinc-700 rounded-lg divide-y divide-zinc-100 dark:divide-zinc-800 bg-white dark:bg-zinc-900">
    @forelse ($this->activities as $activity)
        @include('components.planner._activity-row', ['activity' => $activity, 'isInbox' => false])
    @empty
        <div class="p-8 text-center">
            <flux:icon name="inbox" class="w-10 h-10 mx-auto text-zinc-300 dark:text-zinc-600 mb-3" />
            <flux:text class="text-zinc-500">
                @if ($search)
                    {{ __('No results found for ":query".', ['query' => $search]) }}
                @elseif ($statusFilter === 'completed')
                    {{ __('No completed tasks yet.') }}
                @elseif ($statusFilter === 'snoozed')
                    {{ __('No snoozed tasks.') }}
                @elseif ($statusFilter === 'needs_attention')
                    {{ __('Nothing needs attention right now.') }}
                @else
                    {{ __('No tasks found. Create one to get started!') }}
                @endif
            </flux:text>
        </div>
    @endforelse
</div>

{{-- Pagination --}}
@if ($this->activities->hasPages())
    <div class="mt-4">
        {{ $this->activities->links() }}
    </div>
@endif
