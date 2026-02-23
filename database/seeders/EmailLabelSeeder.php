<?php

namespace Database\Seeders;

use App\Models\EmailLabel;
use Illuminate\Database\Seeder;

class EmailLabelSeeder extends Seeder
{
    public function run(): void
    {
        $systemLabels = [
            ['name' => 'Inbox', 'slug' => 'inbox', 'sort_order' => 1],
            ['name' => 'Sent', 'slug' => 'sent', 'sort_order' => 2],
            ['name' => 'Drafts', 'slug' => 'drafts', 'sort_order' => 3],
            ['name' => 'Starred', 'slug' => 'starred', 'sort_order' => 4],
            ['name' => 'Archive', 'slug' => 'archive', 'sort_order' => 5],
            ['name' => 'Trash', 'slug' => 'trash', 'sort_order' => 6],
        ];

        foreach ($systemLabels as $label) {
            EmailLabel::firstOrCreate(
                [
                    'email_account_id' => null,
                    'slug' => $label['slug'],
                ],
                [
                    'name' => $label['name'],
                    'is_system' => true,
                    'sort_order' => $label['sort_order'],
                ]
            );
        }
    }
}
