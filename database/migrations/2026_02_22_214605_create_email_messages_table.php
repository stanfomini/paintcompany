<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('email_account_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('direction', ['inbound', 'outbound', 'draft']);

            // Addresses
            $table->string('from_address');
            $table->string('from_name')->nullable();
            $table->json('to_addresses');
            $table->json('cc_addresses')->nullable();
            $table->json('bcc_addresses')->nullable();

            // Content
            $table->string('subject');
            $table->longText('body_html');
            $table->longText('body_text');
            $table->text('snippet')->nullable(); // ~200 char preview

            // Threading headers
            $table->string('message_id')->nullable()->unique();
            $table->string('in_reply_to')->nullable();
            $table->json('references')->nullable();

            // Mailgun
            $table->string('mailgun_message_id')->nullable();
            $table->json('raw_headers')->nullable();

            // Status
            $table->boolean('is_read')->default(false);
            $table->boolean('is_starred')->default(false);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index('email_thread_id');
            $table->index('email_account_id');
            $table->index('direction');
            $table->index('is_read');
            $table->index('sent_at');
            if (DB::connection()->getDriverName() === 'mysql') {
                $table->fullText(['subject', 'body_text']);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_messages');
    }
};
