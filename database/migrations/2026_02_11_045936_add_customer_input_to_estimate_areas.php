<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_areas', function (Blueprint $table) {
            $table->text('customer_notes')->nullable()->after('notes');
            $table->json('customer_checklist')->nullable()->after('customer_notes');
        });
    }

    public function down(): void
    {
        Schema::table('estimate_areas', function (Blueprint $table) {
            $table->dropColumn(['customer_notes', 'customer_checklist']);
        });
    }
};
