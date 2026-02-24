<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('messenger_settings', function (Blueprint $table) {
            $table->id();
            $table->string('page_id')->nullable();
            $table->text('page_access_token')->nullable();
            $table->text('app_secret')->nullable();
            $table->string('verify_token')->nullable();
            $table->string('webhook_url', 500)->nullable();
            $table->foreignId('default_assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('website_widget_enabled')->default(false);
            $table->text('widget_greeting_logged_in')->nullable();
            $table->text('widget_greeting_logged_out')->nullable();
            $table->string('widget_theme_color', 7)->default('#0084ff');
            $table->boolean('is_connected')->default(false);
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messenger_settings');
    }
};
