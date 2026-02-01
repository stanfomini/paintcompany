<?php

namespace App\Livewire\Estimates;

use Illuminate\Contracts\View\View;
use Livewire\Component;

class ModeSelector extends Component
{
    public const MODE_SIMPLE = 'simple';

    public const MODE_TEMPLATE = 'template';

    public const MODE_DETAILED = 'detailed';

    public string $selectedMode = self::MODE_TEMPLATE;

    public bool $showDescriptions = true;

    public function selectMode(string $mode): void
    {
        if (in_array($mode, [self::MODE_SIMPLE, self::MODE_TEMPLATE, self::MODE_DETAILED])) {
            $this->selectedMode = $mode;
            $this->dispatch('mode-selected', mode: $mode);
        }
    }

    /**
     * @return array<string, array{icon: string, name: string, description: string, time: string, recommended?: bool}>
     */
    public function getModes(): array
    {
        return [
            self::MODE_SIMPLE => [
                'icon' => 'bolt',
                'name' => 'Quick Estimate',
                'description' => '$/sqft pricing',
                'time' => '~30 seconds',
            ],
            self::MODE_TEMPLATE => [
                'icon' => 'squares-2x2',
                'name' => 'Template Builder',
                'description' => 'Pre-made cards',
                'time' => '~5 minutes',
                'recommended' => true,
            ],
            self::MODE_DETAILED => [
                'icon' => 'table-cells',
                'name' => 'Detailed Builder',
                'description' => 'Full production rates',
                'time' => '~15+ minutes',
            ],
        ];
    }

    public function render(): View
    {
        return view('livewire.estimates.mode-selector', [
            'modes' => $this->getModes(),
        ]);
    }
}
