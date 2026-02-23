<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_crm_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_message_id')->constrained()->cascadeOnDelete();
            $table->morphs('linkable');
            $table->enum('link_type', ['auto', 'manual']);
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['email_message_id', 'linkable_type', 'linkable_id'], 'email_crm_link_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_crm_links');
    }
};
