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
        Schema::table('invoices', function (Blueprint $table) {
            $table->index('status', 'invoices_status_index');
            $table->index(['status', 'due_date'], 'invoices_status_due_date_index');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['status', 'received_at'], 'payments_status_received_at_index');
        });

        Schema::table('painting_jobs', function (Blueprint $table) {
            $table->index(['stage', 'scheduled_date'], 'painting_jobs_stage_scheduled_date_index');
        });

        Schema::table('schedules', function (Blueprint $table) {
            $table->index(['employee_profile_id', 'date', 'status'], 'schedules_employee_date_status_index');
            $table->index(['date', 'status'], 'schedules_date_status_index');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['notifiable_type', 'notifiable_id', 'read_at'], 'notifications_notifiable_read_at_index');
        });

        Schema::table('employee_contracts', function (Blueprint $table) {
            $table->index(['employee_profile_id', 'status'], 'employee_contracts_profile_status_index');
            $table->index('expires_at', 'employee_contracts_expires_at_index');
        });

        Schema::table('employee_documents', function (Blueprint $table) {
            $table->index(['employee_profile_id', 'expires_at'], 'employee_documents_profile_expires_at_index');
            $table->index('type', 'employee_documents_type_index');
        });

        Schema::table('job_crew', function (Blueprint $table) {
            $table->index(['crew_user_id', 'status'], 'job_crew_crew_status_index');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('customers', function (Blueprint $table) {
                $table->fullText(['first_name', 'last_name', 'email', 'phone'], 'customers_fulltext_search');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_status_index');
            $table->dropIndex('invoices_status_due_date_index');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_status_received_at_index');
        });

        Schema::table('painting_jobs', function (Blueprint $table) {
            $table->dropIndex('painting_jobs_stage_scheduled_date_index');
        });

        Schema::table('schedules', function (Blueprint $table) {
            $table->dropIndex('schedules_employee_date_status_index');
            $table->dropIndex('schedules_date_status_index');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_notifiable_read_at_index');
        });

        Schema::table('employee_contracts', function (Blueprint $table) {
            $table->dropIndex('employee_contracts_profile_status_index');
            $table->dropIndex('employee_contracts_expires_at_index');
        });

        Schema::table('employee_documents', function (Blueprint $table) {
            $table->dropIndex('employee_documents_profile_expires_at_index');
            $table->dropIndex('employee_documents_type_index');
        });

        Schema::table('job_crew', function (Blueprint $table) {
            $table->dropIndex('job_crew_crew_status_index');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropIndex('customers_fulltext_search');
            });
        }
    }
};
