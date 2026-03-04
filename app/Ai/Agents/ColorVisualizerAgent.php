<?php

namespace App\Ai\Agents;

use App\Ai\Tools\PaintColorLookupTool;
use App\Models\CompanySetting;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider(Lab::OpenAI)]
#[MaxSteps(5)]
#[Temperature(0.3)]
#[Timeout(60)]
class ColorVisualizerAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return CompanySetting::getInstance()->visualizer_agent_prompt
            ?? config('services.color_visualizer.default_agent_prompt');
    }

    /**
     * Get the tools available to the agent.
     *
     * @return \Laravel\Ai\Contracts\Tool[]
     */
    public function tools(): iterable
    {
        return [
            new PaintColorLookupTool,
        ];
    }
}
