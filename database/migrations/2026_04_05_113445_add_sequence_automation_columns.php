<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospector_companies', function (Blueprint $table) {
            $table->json('default_disposition_sequences')->nullable()->after('default_rvm_template_id');
        });

        Schema::table('prospector_sequence_enrollments', function (Blueprint $table) {
            $table->string('enrolled_via', 20)->default('manual')->after('enrolled_by');
        });
    }

    public function down(): void
    {
        Schema::table('prospector_companies', function (Blueprint $table) {
            $table->dropColumn('default_disposition_sequences');
        });

        Schema::table('prospector_sequence_enrollments', function (Blueprint $table) {
            $table->dropColumn('enrolled_via');
        });
    }
};
