<?php

namespace App\Console\Commands;

use App\Models\EstimateTemplate;
use App\Models\EstimateTemplateCategory;
use App\Services\EstimateTemplateService;
use Illuminate\Console\Command;

class SeedEstimateTemplatesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'estimate-templates:seed';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seed default estimate templates for the painting CRM';

    /**
     * Execute the console command.
     */
    public function handle(EstimateTemplateService $service): int
    {
        $this->info('Seeding estimate templates...');

        $service->seedDefaultTemplates();

        $categoryCount = EstimateTemplateCategory::count();
        $templateCount = EstimateTemplate::count();

        $this->info("Done! Created {$categoryCount} categories and {$templateCount} templates.");

        return Command::SUCCESS;
    }
}
