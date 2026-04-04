<?php

namespace App\Models\Prospector;

use Illuminate\Database\Eloquent\Model;

class ExternalIdMap extends Model
{
    protected $table = 'prospector_external_id_maps';

    protected $fillable = [
        'prospector_type',
        'prospector_id',
        'external_model',
        'external_id',
        'sync_direction',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
        ];
    }

    public function scopeForProspector($query, string $type, int $id)
    {
        return $query->where('prospector_type', $type)->where('prospector_id', $id);
    }

    public function scopeForExternal($query, string $model, int $id)
    {
        return $query->where('external_model', $model)->where('external_id', $id);
    }
}
