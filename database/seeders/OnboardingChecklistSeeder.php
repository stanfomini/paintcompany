<?php

namespace Database\Seeders;

use App\Models\OnboardingChecklist;
use App\Models\OnboardingChecklistItem;
use Illuminate\Database\Seeder;

class OnboardingChecklistSeeder extends Seeder
{
    public function run(): void
    {
        if (OnboardingChecklist::query()->exists()) {
            $this->command->info('Onboarding checklists already exist. Skipping.');

            return;
        }

        $checklist = OnboardingChecklist::create([
            'name' => 'General Onboarding',
            'description' => 'Standard onboarding checklist for all new employees.',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $items = [
            // Documents
            ['title' => 'W-4 Form', 'type' => 'document', 'sort_order' => 1, 'description' => 'Federal tax withholding form.'],
            ['title' => 'I-9 Form', 'type' => 'document', 'sort_order' => 2, 'description' => 'Employment eligibility verification.'],
            ['title' => 'Direct Deposit Form', 'type' => 'document', 'sort_order' => 3, 'description' => 'Bank account information for payroll.'],
            ['title' => 'State Tax Form', 'type' => 'document', 'sort_order' => 4, 'description' => 'Colorado state tax withholding form.'],

            // Contracts
            ['title' => 'Employment Agreement', 'type' => 'contract', 'sort_order' => 5, 'description' => 'Signed employment agreement.'],
            ['title' => 'Background Check Consent', 'type' => 'contract', 'sort_order' => 6, 'description' => 'Authorization for background check.'],
            ['title' => 'NDA', 'type' => 'contract', 'sort_order' => 7, 'description' => 'Non-disclosure agreement.'],

            // Training
            ['title' => 'Safety Training', 'type' => 'training', 'sort_order' => 8, 'description' => 'OSHA safety training completion.'],
            ['title' => 'EPA Lead-Safe Certification Check', 'type' => 'training', 'sort_order' => 9, 'description' => 'Verify lead-safe work practices certification.'],
            ['title' => 'PPE Issuance', 'type' => 'task', 'sort_order' => 10, 'description' => 'Issue personal protective equipment.'],

            // Tasks
            ['title' => 'Equipment Assignment', 'type' => 'task', 'sort_order' => 11, 'description' => 'Assign tools and equipment to employee.'],
            ['title' => 'Company Handbook Acknowledgment', 'type' => 'task', 'sort_order' => 12, 'description' => 'Employee confirms reading the company handbook.'],
            ['title' => 'Emergency Contact Collection', 'type' => 'task', 'sort_order' => 13, 'description' => 'Collect emergency contact information.'],
        ];

        foreach ($items as $item) {
            OnboardingChecklistItem::create([
                'onboarding_checklist_id' => $checklist->id,
                'is_required' => true,
                ...$item,
            ]);
        }

        $this->command->info('Onboarding checklist seeded with '.count($items).' items.');
    }
}
