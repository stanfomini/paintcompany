<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('public_token', 64)->nullable()->unique()->after('default_expense_account_id');
            $table->dateTime('public_token_expires_at')->nullable()->after('public_token');
            $table->dateTime('onboarding_completed_at')->nullable()->after('public_token_expires_at');
        });

        Schema::create('vendor_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('type')->default('other');
            $table->string('file_path');
            $table->string('original_filename')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->text('notes')->nullable();
            $table->date('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_documents');

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['public_token', 'public_token_expires_at', 'onboarding_completed_at']);
        });
    }
};
