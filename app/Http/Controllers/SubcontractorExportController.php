<?php

namespace App\Http\Controllers;

use App\Models\JobPayable;
use App\Models\Vendor;
use Illuminate\Http\Response;

class SubcontractorExportController extends Controller
{
    public function export(int $year): Response
    {
        abort_unless(auth()->user()?->can('ar.manage'), 403);

        $crewData = JobPayable::query()
            ->where('payee_type', JobPayable::PAYEE_TYPE_CREW)
            ->where('status', JobPayable::STATUS_PAID)
            ->whereYear('paid_at', $year)
            ->with('payeeUser.crewProfile')
            ->get()
            ->groupBy('payee_user_id')
            ->map(fn ($payables) => [
                'name' => $payables->first()->payeeUser?->name ?? $payables->first()->payee_name,
                'company' => $payables->first()->payeeUser?->crewProfile?->company_name ?? '',
                'total_paid' => $payables->sum('amount'),
                'type' => 'Crew',
                'w9_on_file' => $payables->first()->payeeUser?->crewProfile?->w9_received_date !== null ? 'Yes' : 'No',
            ])
            ->sortByDesc('total_paid')
            ->values();

        $vendorData = Vendor::query()
            ->whereHas('payables', function ($q) use ($year) {
                $q->where('status', JobPayable::STATUS_PAID)
                    ->whereYear('paid_at', $year);
            })
            ->withSum(['payables as total_paid' => function ($q) use ($year) {
                $q->where('status', JobPayable::STATUS_PAID)
                    ->whereYear('paid_at', $year);
            }], 'amount')
            ->orderByDesc('total_paid')
            ->get()
            ->map(fn (Vendor $vendor) => [
                'name' => $vendor->name,
                'company' => $vendor->contact_name ?? '',
                'total_paid' => (float) $vendor->total_paid,
                'type' => 'Vendor',
                'w9_on_file' => $vendor->w9_on_file ? 'Yes' : 'No',
            ]);

        $csv = "Name,Company,Type,Total Paid,W9 On File,1099 Required\n";

        foreach ($crewData->merge($vendorData)->sortByDesc('total_paid') as $row) {
            $required = $row['total_paid'] >= 600 ? 'Yes' : 'No';
            $csv .= "\"{$row['name']}\",\"{$row['company']}\",\"{$row['type']}\",{$row['total_paid']},{$row['w9_on_file']},{$required}\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"1099-report-{$year}.csv\"",
        ]);
    }
}
