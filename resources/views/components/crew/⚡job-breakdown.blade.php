<?php

use App\Models\Job;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.crew', ['title' => 'Pay Breakdown'])] class extends Component
{
    public Job $job;

    public function mount(Job $job): void
    {
        $user = Auth::guard('crew')->user();
        $hasAccess = $job->crews()->where('crew_user_id', $user->id)->exists();

        if (! $hasAccess) {
            abort(404);
        }

        if (! $job->estimate) {
            abort(404);
        }

        $this->job = $job;
    }
};
?>

<div>
    <div class="mb-6 flex items-center gap-3">
        <flux:button :href="route('crew.jobs.show', $job)" variant="ghost" icon="arrow-left" size="sm">
            {{ __('Back to Job') }}
        </flux:button>
        <flux:heading size="xl">{{ __('Pay Breakdown') }}</flux:heading>
    </div>

    @livewire('estimates.painter-breakdown', ['estimate' => $this->job->estimate])
</div>
