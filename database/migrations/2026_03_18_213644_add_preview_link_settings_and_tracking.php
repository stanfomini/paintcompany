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
        Schema::table('company_settings', function (Blueprint $table) {
            $table->boolean('preview_link_auto_send')->default(true);
            $table->text('preview_link_sms_message')->nullable();
            $table->string('preview_link_email_subject', 255)->nullable();
            $table->text('preview_link_email_body')->nullable();
        });

        Schema::table('project_previews', function (Blueprint $table) {
            $table->timestamp('preview_link_sent_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn([
                'preview_link_auto_send',
                'preview_link_sms_message',
                'preview_link_email_subject',
                'preview_link_email_body',
            ]);
        });

        Schema::table('project_previews', function (Blueprint $table) {
            $table->dropColumn('preview_link_sent_at');
        });
    }
};
