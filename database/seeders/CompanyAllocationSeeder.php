<?php

namespace Database\Seeders;

use App\Models\CompanySetting;
use Illuminate\Database\Seeder;

class CompanyAllocationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Creates system allocations for all existing company settings.
     */
    public function run(): void
    {
        // Ensure there's at least one company setting
        $settings = CompanySetting::getInstance();

        // Create system allocations
        $settings->ensureSystemAllocations();

        $this->command->info('System allocations created successfully.');
        $this->command->table(
            ['Name', 'Type', 'Value', 'System', 'Informational'],
            $settings->allocations()->get()->map(fn ($a) => [
                $a->name,
                $a->type_label,
                $a->formatted_value,
                $a->is_system ? 'Yes' : 'No',
                $a->is_informational ? 'Yes' : 'No',
            ])->toArray()
        );
    }
}
