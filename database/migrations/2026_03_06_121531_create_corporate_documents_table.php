<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporate_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('corporation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_document_id')->nullable()->constrained('corporate_documents')->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('type', 50);
            $table->string('original_file_path');
            $table->string('original_filename')->nullable();
            $table->integer('version')->default(1);
            $table->string('status', 20)->default('processing');
            $table->json('parsed_content')->nullable();
            $table->json('edited_content')->nullable();
            $table->text('parse_error')->nullable();
            $table->integer('file_size')->nullable();
            $table->integer('page_count')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_documents');
    }
};
