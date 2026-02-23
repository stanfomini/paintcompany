<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('color')->nullable();
            $table->boolean('is_system')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['email_account_id', 'slug']);
        });

        Schema::create('email_message_label', function (Blueprint $table) {
            $table->foreignId('email_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('email_label_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['email_message_id', 'email_label_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_message_label');
        Schema::dropIfExists('email_labels');
    }
};
