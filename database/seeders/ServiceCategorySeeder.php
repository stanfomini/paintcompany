<?php

namespace Database\Seeders;

use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;

class ServiceCategorySeeder extends Seeder
{
    public function run(): void
    {
        ServiceCategory::updateOrCreate(
            ['slug' => 'painting'],
            [
                'name' => 'Painting',
                'description' => 'Interior and exterior painting services',
                'icon' => 'paint-brush',
                'color' => 'blue',
                'is_active' => true,
                'sort_order' => 1,
            ],
        );

        ServiceCategory::updateOrCreate(
            ['slug' => 'cleaning'],
            [
                'name' => 'Cleaning',
                'description' => 'Post-painting cleanup and pressure washing',
                'icon' => 'sparkles',
                'color' => 'green',
                'is_active' => true,
                'sort_order' => 2,
            ],
        );
    }
}
