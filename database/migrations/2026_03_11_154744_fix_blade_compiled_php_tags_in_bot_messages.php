<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Fix bot messages corrupted by Blade double-compilation.
     * Replaces <?php echo e(variable_name); ?> back to {{ variable_name }}.
     */
    public function up(): void
    {
        DB::table('messenger_bot_steps')
            ->where('message_text', 'like', '%<?php%')
            ->orderBy('id')
            ->each(fn ($step) => DB::table('messenger_bot_steps')
                ->where('id', $step->id)
                ->update(['message_text' => preg_replace(
                    '/\<\?php echo e\((\w+)\); \?\>/',
                    '{{ $1 }}',
                    $step->message_text
                )]));

        DB::table('messenger_bot_flows')
            ->where('greeting_message', 'like', '%<?php%')
            ->orderBy('id')
            ->each(fn ($flow) => DB::table('messenger_bot_flows')
                ->where('id', $flow->id)
                ->update(['greeting_message' => preg_replace(
                    '/\<\?php echo e\((\w+)\); \?\>/',
                    '{{ $1 }}',
                    $flow->greeting_message
                )]));
    }

    /**
     * No reversal needed — the data was corrupted and this restores it.
     */
    public function down(): void
    {
        // Intentionally left empty
    }
};
