<?php

namespace App\Services;

use App\Models\ColorVisualization;

class ColorVisualizationService
{
    public function create(array $data): ColorVisualization
    {
        return ColorVisualization::create($data);
    }

    public function canGenerateForSession(string $sessionId): bool
    {
        return $this->getRemainingFreeUses($sessionId) > 0;
    }

    public function getRemainingFreeUses(string $sessionId): int
    {
        $limit = $this->getFreeLimit();
        $used = $this->getSessionUsageCount($sessionId);

        return max(0, $limit - $used);
    }

    public function getSessionUsageCount(string $sessionId): int
    {
        return ColorVisualization::forSession($sessionId)->count();
    }

    public function getFreeLimit(): int
    {
        return (int) config('services.color_visualizer.free_limit', 2);
    }
}
