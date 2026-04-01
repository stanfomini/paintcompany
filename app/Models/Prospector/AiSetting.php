<?php

namespace App\Models\Prospector;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiSetting extends Model
{
    use HasFactory;

    protected $table = 'prospector_ai_settings';

    protected $fillable = [
        'provider',
        'model',
        'api_key',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'enabled' => 'boolean',
        ];
    }

    public static function current(): static
    {
        return static::firstOrCreate([], [
            'provider' => 'xai',
            'model' => 'grok-3-mini-fast',
            'enabled' => false,
        ]);
    }

    public function isConfigured(): bool
    {
        return $this->enabled
            && ! empty($this->provider)
            && ! empty($this->model)
            && ! empty($this->api_key);
    }

    public function getMaskedKey(): string
    {
        $key = $this->api_key;

        if (! $key || strlen($key) < 8) {
            return $key ? '••••••••' : '';
        }

        return substr($key, 0, 4).'••••••••'.substr($key, -4);
    }

    /**
     * @return array<string, string>
     */
    public static function providerOptions(): array
    {
        return [
            'xai' => 'xAI (Grok)',
            'anthropic' => 'Anthropic (Claude)',
            'openai' => 'OpenAI (GPT)',
            'groq' => 'Groq',
            'gemini' => 'Google Gemini',
            'deepseek' => 'DeepSeek',
            'mistral' => 'Mistral',
            'openrouter' => 'OpenRouter',
        ];
    }
}
