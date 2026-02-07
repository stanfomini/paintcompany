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
        $driver = DB::connection()->getDriverName();

        // Convert existing customer_id values from users.id to customers.id
        if ($driver === 'mysql') {
            DB::statement('
                UPDATE invoices
                INNER JOIN customers ON customers.user_id = invoices.customer_id
                SET invoices.customer_id = customers.id
            ');
        } else {
            // SQLite: use subquery
            DB::statement('
                UPDATE invoices
                SET customer_id = (
                    SELECT customers.id FROM customers WHERE customers.user_id = invoices.customer_id
                )
                WHERE EXISTS (
                    SELECT 1 FROM customers WHERE customers.user_id = invoices.customer_id
                )
            ');
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        // Convert customer_id values back from customers.id to users.id
        if ($driver === 'mysql') {
            DB::statement('
                UPDATE invoices
                INNER JOIN customers ON customers.id = invoices.customer_id
                SET invoices.customer_id = customers.user_id
                WHERE customers.user_id IS NOT NULL
            ');
        } else {
            DB::statement('
                UPDATE invoices
                SET customer_id = (
                    SELECT customers.user_id FROM customers WHERE customers.id = invoices.customer_id
                )
                WHERE EXISTS (
                    SELECT 1 FROM customers WHERE customers.id = invoices.customer_id AND customers.user_id IS NOT NULL
                )
            ');
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->foreign('customer_id')->references('id')->on('users')->onDelete('cascade');
        });
    }
};
