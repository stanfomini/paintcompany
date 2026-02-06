<?php

namespace App\Console\Commands;

use App\Models\CompanySetting;
use App\Models\Estimate;
use Illuminate\Console\Command;

class RecalculateEstimateTotals extends Command
{
    protected $signature = 'estimate:recalculate {--dry-run : Show what would be changed without making changes}';

    protected $description = 'Recalculate all estimate totals to fix double-margin issues';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $settings = CompanySetting::getInstance();

        $this->info('Starting estimate recalculation...');
        if ($dryRun) {
            $this->warn('DRY RUN - No changes will be made');
        }

        $count = 0;
        $errors = 0;

        Estimate::with(['areas.surfaces', 'packages.items'])->chunk(50, function ($estimates) use ($settings, $dryRun, &$count, &$errors) {
            foreach ($estimates as $estimate) {
                try {
                    $this->processEstimate($estimate, $settings, $dryRun);
                    $count++;
                } catch (\Exception $e) {
                    $this->error("Error processing estimate #{$estimate->id}: {$e->getMessage()}");
                    $errors++;
                }
            }
        });

        $this->newLine();
        $this->info("Processed {$count} estimates with {$errors} errors.");

        return self::SUCCESS;
    }

    protected function processEstimate(Estimate $estimate, CompanySetting $settings, bool $dryRun): void
    {
        $scope = $estimate->project_type ?? 'interior';
        $oldTotal = $estimate->total;

        // Sync paint products from estimate-level selections to surfaces missing them
        if (! $dryRun) {
            $synced = $estimate->syncPaintProductsToSurfaces();
            if ($synced > 0) {
                $this->line("    Synced paint products to {$synced} surfaces");
                $estimate->load('areas.surfaces'); // Reload after sync
            }
        }

        // Fix surfaces: ensure total = labor_cost + material_cost (raw COGS)
        // Cleanup and sundries are NOT stored in surface - they're calculated on-the-fly
        foreach ($estimate->areas as $area) {
            $areaScope = $area->scope_type ?? $scope;
            $hourlyWage = $settings->getHourlyWageForScope($areaScope);

            foreach ($area->surfaces as $surface) {
                // Recalculate labor cost from hours using company settings
                $laborCost = $surface->labor_hours * $hourlyWage;

                // Surface total = labor + material only (COGS base, no cleanup/sundries)
                // Cleanup and sundries are added at area level in getFullCOGS()
                $newTotal = $laborCost + $surface->material_cost;

                if (! $dryRun) {
                    $surface->labor_cost = round($laborCost, 2);
                    $surface->total = round($newTotal, 2);
                    $surface->saveQuietly();
                }
            }
        }

        // For template mode, the estimate subtotal/total are set by TemplateBuilder
        // and shouldn't be recalculated. For non-template mode, recalculate.
        if (! $dryRun) {
            if (! $estimate->isTemplateMode()) {
                $estimate->calculateTotals();
                $estimate->calculateDepositAndBalance();
                $estimate->saveQuietly();
            }

            // Recalculate packages - this will use getCustomerPrice() from items
            foreach ($estimate->packages as $package) {
                $package->calculateTotals();
                $package->saveQuietly();
            }
        }

        $newTotal = $estimate->fresh()->total ?? $estimate->total;
        $diff = $newTotal - $oldTotal;
        $diffPercent = $oldTotal > 0 ? round(($diff / $oldTotal) * 100, 1) : 0;

        $this->line(sprintf(
            '  Estimate #%d: $%s -> $%s (%s%s%%)',
            $estimate->id,
            number_format($oldTotal, 2),
            number_format($newTotal, 2),
            $diff >= 0 ? '+' : '',
            $diffPercent
        ));
    }
}
