<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_message_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('filename');
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('file_size');
            $table->string('content_id')->nullable(); // for inline CID references
            $table->boolean('is_inline')->default(false);
            $table->timestamps();

            $table->index('email_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_attachments');
    }
};
