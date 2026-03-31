<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospector_campaigns', function (Blueprint $table) {
            $table->boolean('auto_vm_drop')->default(false)->after('calls_connected');
            $table->unsignedBigInteger('default_rvm_template_id')->nullable()->after('auto_vm_drop');

            $table->foreign('default_rvm_template_id')
                ->references('id')
                ->on('prospector_rvm_templates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('prospector_campaigns', function (Blueprint $table) {
            $table->dropForeign(['default_rvm_template_id']);
            $table->dropColumn(['auto_vm_drop', 'default_rvm_template_id']);
        });
    }
};
