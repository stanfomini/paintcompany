<?php

use App\Models\TimeEntry;
use App\Services\TimeTrackingService;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public ?int $activeEntryId = null;

    public ?string $clockInTime = null;

    public bool $isOnBreak = false;

    public int $jobId = 0;

    public function mount(): void
    {
        $this->loadActiveEntry();
    }

    protected function loadActiveEntry(): void
    {
        $employee = auth()->user()?->employeeProfile;
        if (! $employee) {
            return;
        }

        $service = app(TimeTrackingService::class);
        $entry = $service->getActiveEntry($employee);

        if ($entry) {
            $this->activeEntryId = $entry->id;
            $this->clockInTime = $entry->clock_in->toISOString();
            $this->isOnBreak = $entry->isOnBreak();
        } else {
            $this->activeEntryId = null;
            $this->clockInTime = null;
            $this->isOnBreak = false;
        }
    }

    public function clockIn(?float $lat = null, ?float $lng = null): void
    {
        $employee = auth()->user()?->employeeProfile;
        if (! $employee) {
            return;
        }

        $service = app(TimeTrackingService::class);
        $entry = $service->clockIn($employee, $lat, $lng, $this->jobId ?: null);

        $this->activeEntryId = $entry->id;
        $this->clockInTime = $entry->clock_in->toISOString();
        $this->isOnBreak = false;
    }

    public function clockOut(?float $lat = null, ?float $lng = null): void
    {
        if (! $this->activeEntryId) {
            return;
        }

        $entry = TimeEntry::find($this->activeEntryId);
        if (! $entry) {
            return;
        }

        $service = app(TimeTrackingService::class);
        $service->clockOut($entry, $lat, $lng);

        $this->activeEntryId = null;
        $this->clockInTime = null;
        $this->isOnBreak = false;
    }

    public function startBreak(): void
    {
        if (! $this->activeEntryId) {
            return;
        }

        $entry = TimeEntry::find($this->activeEntryId);
        if (! $entry) {
            return;
        }

        $service = app(TimeTrackingService::class);
        $service->startBreak($entry);
        $this->isOnBreak = true;
    }

    public function endBreak(): void
    {
        if (! $this->activeEntryId) {
            return;
        }

        $entry = TimeEntry::find($this->activeEntryId);
        if (! $entry) {
            return;
        }

        $service = app(TimeTrackingService::class);
        $service->endBreak($entry);
        $this->isOnBreak = false;
    }

    #[Computed]
    public function isClockedIn(): bool
    {
        return $this->activeEntryId !== null;
    }

    #[Computed]
    public function jobs(): \Illuminate\Database\Eloquent\Collection
    {
        return \App\Models\Job::query()
            ->where('stage', 'in_progress')
            ->orderBy('title')
            ->get(['id', 'title', 'job_number']);
    }
};
?>

<div
    x-data="{
        timer: null,
        elapsed: '',
        clockInTime: @entangle('clockInTime'),
        init() {
            this.startTimer();
            this.$watch('clockInTime', () => this.startTimer());
        },
        startTimer() {
            if (this.timer) clearInterval(this.timer);
            if (!this.clockInTime) {
                this.elapsed = '';
                return;
            }
            const start = new Date(this.clockInTime);
            this.updateElapsed(start);
            this.timer = setInterval(() => this.updateElapsed(start), 1000);
        },
        updateElapsed(start) {
            const now = new Date();
            const diff = Math.floor((now - start) / 1000);
            const h = Math.floor(diff / 3600);
            const m = Math.floor((diff % 3600) / 60);
            const s = diff % 60;
            this.elapsed = String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
        },
        getLocation(callback) {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    (pos) => callback(pos.coords.latitude, pos.coords.longitude),
                    () => callback(null, null),
                    { enableHighAccuracy: true, timeout: 10000 }
                );
            } else {
                callback(null, null);
            }
        },
        doClockIn() {
            this.getLocation((lat, lng) => {
                $wire.clockIn(lat, lng);
            });
        },
        doClockOut() {
            this.getLocation((lat, lng) => {
                $wire.clockOut(lat, lng);
            });
        }
    }"
    class="px-3 py-2"
>
    @if($this->isClockedIn)
        {{-- Clocked In State --}}
        <div class="rounded-lg border border-green-500/30 bg-green-500/10 p-3 space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-green-400">Clocked In</span>
                <span class="text-xs font-mono text-green-300" x-text="elapsed"></span>
            </div>

            @if($this->isOnBreak)
                <flux:button
                    variant="filled"
                    size="sm"
                    class="w-full !bg-amber-600 hover:!bg-amber-700"
                    x-on:click="$wire.endBreak()"
                >
                    End Break
                </flux:button>
            @else
                <div class="flex gap-1">
                    <flux:button
                        variant="filled"
                        size="sm"
                        class="flex-1 !bg-amber-600 hover:!bg-amber-700"
                        x-on:click="$wire.startBreak()"
                    >
                        Break
                    </flux:button>
                    <flux:button
                        variant="filled"
                        size="sm"
                        class="flex-1 !bg-red-600 hover:!bg-red-700"
                        x-on:click="doClockOut()"
                    >
                        Clock Out
                    </flux:button>
                </div>
            @endif
        </div>
    @else
        {{-- Clocked Out State --}}
        <div class="space-y-2">
            <select wire:model="jobId" class="w-full rounded-md border-zinc-700 bg-zinc-800 text-xs text-zinc-300">
                <option value="0">No Job</option>
                @foreach($this->jobs as $job)
                    <option value="{{ $job->id }}">{{ $job->job_number }} - {{ Str::limit($job->title, 20) }}</option>
                @endforeach
            </select>
            <flux:button
                variant="primary"
                size="sm"
                class="w-full"
                x-on:click="doClockIn()"
            >
                Clock In
            </flux:button>
        </div>
    @endif
</div>
