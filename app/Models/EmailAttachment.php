<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class EmailAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'email_message_id',
        'path',
        'filename',
        'original_filename',
        'mime_type',
        'file_size',
        'content_id',
        'is_inline',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'is_inline' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (EmailAttachment $attachment) {
            Storage::delete($attachment->path);
        });
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'email_message_id');
    }

    /**
     * Get the download URL for this attachment.
     */
    public function getUrl(): string
    {
        return Storage::url($this->path);
    }

    /**
     * Check if this attachment is an image.
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }
}
