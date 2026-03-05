<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
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
        if (config('filesystems.default') === 's3') {
            return Storage::temporaryUrl($this->path, now()->addHour());
        }

        return Storage::url($this->path);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    public function isVideo(): bool
    {
        return str_starts_with($this->mime_type, 'video/');
    }

    public function isAudio(): bool
    {
        return str_starts_with($this->mime_type, 'audio/');
    }

    public function isText(): bool
    {
        return in_array($this->mime_type, ['text/plain', 'text/csv']);
    }

    protected function formattedSize(): Attribute
    {
        return Attribute::get(function () {
            $bytes = $this->file_size;
            if ($bytes >= 1048576) {
                return round($bytes / 1048576, 1).'MB';
            }

            return round($bytes / 1024).'KB';
        });
    }

    protected function extension(): Attribute
    {
        return Attribute::get(fn () => pathinfo($this->original_filename, PATHINFO_EXTENSION));
    }
}
