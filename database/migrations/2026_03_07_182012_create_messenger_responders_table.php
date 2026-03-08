<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('messenger_responders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('role'); // 'appointment_booker', 'estimator', 'both'
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('last_assigned_at')->nullable();
            $table->timestamps();
        });

        // Migrate existing default_assigned_to user into the roster
        $defaultUserId = DB::table('messenger_settings')->value('default_assigned_to');
        if ($defaultUserId) {
            DB::table('messenger_responders')->insert([
                'user_id' => $defaultUserId,
                'role' => 'both',
                'is_active' => true,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messenger_responders');
    }
};
