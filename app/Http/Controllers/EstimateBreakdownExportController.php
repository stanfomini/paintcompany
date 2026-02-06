<?php

namespace App\Http\Controllers;

use App\Models\Estimate;
use App\Services\EstimateCostBreakdownService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EstimateBreakdownExportController extends Controller
{
    public function __construct(
        protected EstimateCostBreakdownService $service
    ) {}

    public function pdf(Estimate $estimate): Response
    {
        $estimate->load(['areas.surfaces.productionRate', 'areas.addons', 'items']);

        $breakdown = $this->service->calculateBreakdown($estimate);

        $pdf = Pdf::loadView('pdf.estimate-breakdown', [
            'estimate' => $estimate,
            'breakdown' => $breakdown,
        ]);

        $filename = 'breakdown-'.$estimate->estimate_number.'.pdf';

        return $pdf->download($filename);
    }

    public function csv(Estimate $estimate): StreamedResponse
    {
        $estimate->load(['areas.surfaces.productionRate', 'areas.addons', 'items']);

        $breakdown = $this->service->calculateBreakdown($estimate);

        $filename = 'breakdown-'.$estimate->estimate_number.'.csv';

        return response()->streamDownload(function () use ($estimate, $breakdown) {
            $handle = fopen('php://output', 'w');

            // Header
            fputcsv($handle, ['COST BREAKDOWN - '.$estimate->estimate_number]);
            fputcsv($handle, ['Generated', now()->format('Y-m-d H:i:s')]);
            fputcsv($handle, ['Scope', ucfirst($breakdown['scope'])]);
            fputcsv($handle, []);

            // Summary
            fputcsv($handle, ['SUMMARY']);
            fputcsv($handle, ['Customer Price', '$'.number_format($breakdown['pricing']['customer_price'], 2)]);
            fputcsv($handle, ['Gross Profit', '$'.number_format($breakdown['gross_profit']['amount'], 2), number_format($breakdown['gross_profit']['percent'], 2).'%']);
            fputcsv($handle, ['Net Profit', '$'.number_format($breakdown['net_profit']['amount'], 2), number_format($breakdown['net_profit']['percent'], 2).'%']);
            fputcsv($handle, ['Target Net Margin', number_format($breakdown['net_profit']['target_percent'], 2).'%']);
            fputcsv($handle, ['Delta', number_format($breakdown['net_profit']['delta_percent'], 2).'%']);
            fputcsv($handle, []);

            // Hours
            fputcsv($handle, ['HOURS']);
            fputcsv($handle, ['Labor Hours', number_format($breakdown['totals']['labor_hours'], 2)]);
            fputcsv($handle, ['Cleanup Hours', number_format($breakdown['totals']['cleanup_hours'], 2)]);
            fputcsv($handle, ['Total Hours', number_format($breakdown['totals']['total_hours'], 2)]);
            fputcsv($handle, []);

            // COGS Detail
            fputcsv($handle, ['COGS DETAIL']);
            fputcsv($handle, ['Labor Cost', '$'.number_format($breakdown['cogs']['labor_cost'], 2)]);
            fputcsv($handle, ['Cleanup Cost', '$'.number_format($breakdown['cogs']['cleanup_cost'], 2)]);
            fputcsv($handle, ['Sundries Cost', '$'.number_format($breakdown['cogs']['sundries_cost'], 2)]);
            fputcsv($handle, ['Material Cost', '$'.number_format($breakdown['cogs']['material_cost'], 2)]);
            fputcsv($handle, ['Material Tax', '$'.number_format($breakdown['cogs']['material_tax'], 2)]);
            fputcsv($handle, ['Labor Tax', '$'.number_format($breakdown['cogs']['labor_tax'], 2)]);
            fputcsv($handle, ['Addon Cost', '$'.number_format($breakdown['cogs']['addon_cost'], 2)]);
            if (($breakdown['cogs']['items_total'] ?? 0) > 0) {
                fputcsv($handle, ['Line Items', '$'.number_format($breakdown['cogs']['items_total'], 2)]);
            }
            fputcsv($handle, ['TOTAL COGS', '$'.number_format($breakdown['cogs']['total'], 2)]);
            fputcsv($handle, []);

            // Allocations
            fputcsv($handle, ['ALLOCATIONS']);
            fputcsv($handle, ['Marketing ('.$breakdown['allocations']['marketing']['rate'].'%)', '$'.number_format($breakdown['allocations']['marketing']['amount'], 2)]);
            fputcsv($handle, ['Sales ('.$breakdown['allocations']['sales']['rate'].'%)', '$'.number_format($breakdown['allocations']['sales']['amount'], 2)]);
            fputcsv($handle, ['Production Mgmt ('.$breakdown['allocations']['production_management']['rate'].'%)', '$'.number_format($breakdown['allocations']['production_management']['amount'], 2)]);
            if (isset($breakdown['allocations']['overhead'])) {
                fputcsv($handle, ['General Overhead ('.$breakdown['allocations']['overhead']['rate'].'%) [informational]', '$'.number_format($breakdown['allocations']['overhead']['amount'], 2)]);
            }
            fputcsv($handle, ['Total Allocations', '$'.number_format($breakdown['allocations']['total'], 2)]);
            fputcsv($handle, []);

            // Settings Used
            fputcsv($handle, ['SETTINGS USED']);
            fputcsv($handle, ['Hourly Wage', '$'.number_format($breakdown['settings_used']['hourly_wage'], 2)]);
            fputcsv($handle, ['Payroll Burden Multiplier', number_format($breakdown['settings_used']['payroll_burden_multiplier'], 2).'x']);
            fputcsv($handle, ['Margin Percent', number_format($breakdown['settings_used']['margin_percent'], 2).'%']);
            fputcsv($handle, ['Cleanup Hours Ratio', number_format($breakdown['settings_used']['cleanup_hours_ratio'], 0).':1']);
            fputcsv($handle, ['Sundries Cost/Hour', '$'.number_format($breakdown['settings_used']['sundries_cost_per_hour'], 2)]);
            fputcsv($handle, ['Material Tax Rate', number_format($breakdown['settings_used']['material_tax_rate'], 2).'%']);
            fputcsv($handle, ['Labor Tax Rate', number_format($breakdown['settings_used']['labor_tax_rate'], 2).'%']);
            fputcsv($handle, []);

            // Per-Area Breakdown
            if (count($breakdown['areas']) > 0) {
                fputcsv($handle, ['PER-AREA BREAKDOWN']);
                fputcsv($handle, ['Area', 'Hours', 'COGS', 'Margin', 'Customer Price']);

                foreach ($breakdown['areas'] as $area) {
                    fputcsv($handle, [
                        $area['name'],
                        number_format($area['detail']['labor_hours'] + $area['detail']['cleanup_hours'], 2),
                        '$'.number_format($area['cogs'], 2),
                        number_format($area['margin_percent'], 0).'%',
                        '$'.number_format($area['customer_price'], 2),
                    ]);
                }
            }

            // Line Items
            if (count($breakdown['items'] ?? []) > 0) {
                fputcsv($handle, []);
                fputcsv($handle, ['LINE ITEMS']);
                fputcsv($handle, ['Description', 'Quantity', 'Unit', 'Unit Price', 'Total']);

                foreach ($breakdown['items'] as $item) {
                    fputcsv($handle, [
                        $item['description'],
                        number_format($item['quantity'], 2),
                        $item['unit'],
                        '$'.number_format($item['unit_price'], 2),
                        '$'.number_format($item['total'], 2),
                    ]);
                }

                fputcsv($handle, ['Total', '', '', '', '$'.number_format($breakdown['items_total'], 2)]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
