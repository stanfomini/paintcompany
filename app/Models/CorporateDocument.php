<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CorporateDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'corporation_id',
        'parent_document_id',
        'uploaded_by',
        'title',
        'type',
        'original_file_path',
        'original_filename',
        'version',
        'status',
        'parsed_content',
        'edited_content',
        'parse_error',
        'file_size',
        'page_count',
    ];

    protected function casts(): array
    {
        return [
            'parsed_content' => 'array',
            'edited_content' => 'array',
        ];
    }

    public const TYPES = [
        'operating_agreement' => 'Operating Agreement',
        'articles_of_incorporation' => 'Articles of Incorporation',
        'bylaws' => 'Bylaws',
        'resolution' => 'Resolution',
        'amendment' => 'Amendment',
        'annual_report' => 'Annual Report',
        'other' => 'Other',
    ];

    public const STATUSES = [
        'processing' => 'Processing',
        'ready' => 'Ready',
        'error' => 'Error',
    ];

    public function corporation(): BelongsTo
    {
        return $this->belongsTo(Corporation::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_document_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_document_id');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getContentForExport(): ?array
    {
        return $this->edited_content ?? $this->parsed_content;
    }
}
