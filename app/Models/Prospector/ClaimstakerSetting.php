<?php

namespace App\Models\Prospector;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class ClaimstakerSetting extends Model
{
    protected $table = 'prospector_settings';

    protected $fillable = ['key', 'value', 'type'];

    /**
     * Get a setting value by key with optional default.
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        $setting = static::where('key', $key)->first();

        if (! $setting) {
            return $default;
        }

        return $setting->getCastValue();
    }

    /**
     * Set a setting value (creates or updates).
     */
    public static function setValue(string $key, mixed $value, string $type = 'string'): void
    {
        $storeValue = match ($type) {
            'boolean' => $value ? '1' : '0',
            'json' => is_string($value) ? $value : json_encode($value),
            'encrypted' => $value ? Crypt::encryptString((string) $value) : null,
            default => (string) $value,
        };

        static::updateOrCreate(
            ['key' => $key],
            ['value' => $storeValue, 'type' => $type],
        );
    }

    /**
     * Get the properly cast value.
     */
    public function getCastValue(): mixed
    {
        if ($this->value === null) {
            return null;
        }

        return match ($this->type) {
            'boolean' => (bool) $this->value,
            'integer' => (int) $this->value,
            'json' => json_decode($this->value, true),
            'encrypted' => Crypt::decryptString($this->value),
            default => $this->value,
        };
    }
}
