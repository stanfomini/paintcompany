<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_threads', function (Blueprint $table) {
            $table->string('source')->nullable()->after('type');
            $table->index('source');
        });

        // Backfill existing support threads
        // Setter support threads: guest_chat_sessions with metadata->source = 'setter_support'
        $setterThreadIds = DB::table('guest_chat_sessions')
            ->whereNotNull('chat_thread_id')
            ->whereJsonContains('metadata->source', 'setter_support')
            ->pluck('chat_thread_id');

        if ($setterThreadIds->isNotEmpty()) {
            DB::table('chat_threads')
                ->whereIn('id', $setterThreadIds)
                ->update(['source' => 'setter_support']);
        }

        // Website lead threads: support type threads with a guest_chat_session but NOT setter_support
        $websiteThreadIds = DB::table('guest_chat_sessions')
            ->whereNotNull('chat_thread_id')
            ->where(function ($q) {
                $q->whereNull('metadata')
                    ->orWhere(function ($q2) {
                        $q2->whereJsonDoesntContain('metadata->source', 'setter_support');
                    });
            })
            ->pluck('chat_thread_id');

        if ($websiteThreadIds->isNotEmpty()) {
            DB::table('chat_threads')
                ->whereIn('id', $websiteThreadIds)
                ->where('type', 'support')
                ->update(['source' => 'website_lead']);
        }
    }

    public function down(): void
    {
        Schema::table('chat_threads', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn('source');
        });
    }
};
