<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('deal_id')->nullable()->constrained('deals')->nullOnDelete();

            if (DB::getDriverName() === 'sqlite') {
                $table->string('status')->default('lead');
            } else {
                $table->enum('status', ['lead', 'quoted', 'signed', 'deposit_paid', 'reward_sent'])->default('lead');
            }

            $table->decimal('reward_amount', 8, 2)->nullable();
            $table->dateTime('reward_sent_at')->nullable();
            $table->text('reward_notes')->nullable();
            $table->timestamps();

            $table->index(['referrer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
