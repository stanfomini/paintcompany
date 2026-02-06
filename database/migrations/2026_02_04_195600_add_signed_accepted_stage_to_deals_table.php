<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE deals MODIFY COLUMN stage ENUM('lead', 'quoted', 'scheduled', 'in_progress', 'completed', 'paid', 'signed_accepted', 'cancelled') DEFAULT 'lead'");
        } elseif (DB::getDriverName() === 'sqlite') {
            // Check if the constraint already includes signed_accepted (fresh migrations)
            $sql = DB::selectOne("SELECT sql FROM sqlite_master WHERE type='table' AND name='deals'");
            if ($sql && str_contains($sql->sql, 'signed_accepted')) {
                return;
            }

            DB::statement('PRAGMA foreign_keys=off');
            DB::statement('ALTER TABLE deals RENAME TO deals_old');
            DB::statement("
                CREATE TABLE deals (
                    id integer primary key autoincrement not null,
                    customer_id integer not null,
                    property_id integer,
                    agent_id integer,
                    assigned_to integer,
                    created_by integer not null,
                    title varchar not null,
                    description text,
                    stage varchar check (\"stage\" in ('lead', 'quoted', 'scheduled', 'in_progress', 'completed', 'paid', 'signed_accepted', 'cancelled')) not null default 'lead',
                    estimated_value numeric,
                    actual_value numeric,
                    expected_start_date date,
                    expected_end_date date,
                    actual_start_date date,
                    actual_end_date date,
                    sort_order integer not null default '0',
                    created_at datetime,
                    updated_at datetime,
                    deleted_at datetime,
                    foreign key(customer_id) references customers(id) on delete cascade,
                    foreign key(property_id) references properties(id) on delete set null,
                    foreign key(agent_id) references users(id) on delete set null,
                    foreign key(assigned_to) references users(id) on delete set null,
                    foreign key(created_by) references users(id)
                )
            ");
            DB::statement('INSERT INTO deals SELECT * FROM deals_old');
            DB::statement('DROP TABLE deals_old');
            DB::statement('CREATE INDEX deals_stage_index ON deals (stage)');
            DB::statement('CREATE INDEX deals_stage_sort_order_index ON deals (stage, sort_order)');
            DB::statement('CREATE INDEX deals_customer_id_index ON deals (customer_id)');
            DB::statement('CREATE INDEX deals_assigned_to_index ON deals (assigned_to)');
            DB::statement('PRAGMA foreign_keys=on');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::table('deals')->where('stage', 'signed_accepted')->update(['stage' => 'scheduled']);
            DB::statement("ALTER TABLE deals MODIFY COLUMN stage ENUM('lead', 'quoted', 'scheduled', 'in_progress', 'completed', 'paid', 'cancelled') DEFAULT 'lead'");
        }
    }
};
