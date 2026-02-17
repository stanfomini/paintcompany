<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type')->default('standard'); // standard, role_specific
            $table->string('content_type')->default('rich_text'); // rich_text, pdf
            $table->longText('content')->nullable();
            $table->string('pdf_path')->nullable();
            $table->json('fields')->nullable(); // PDF field positions
            $table->json('merge_tags')->nullable();
            $table->string('role_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_templates');
    }
};
