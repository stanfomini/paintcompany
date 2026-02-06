<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painter Financials - {{ $estimate->estimate_number }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 10px;
            line-height: 1.4;
            color: #333;
        }

        .container {
            padding: 25px;
        }

        .header {
            margin-bottom: 20px;
            border-bottom: 2px solid #f59e0b;
            padding-bottom: 12px;
        }

        .header-title {
            font-size: 18px;
            font-weight: bold;
            color: #b45309;
            margin-bottom: 3px;
        }

        .header-subtitle {
            font-size: 11px;
            color: #666;
        }

        .warning-box {
            background-color: #fef3c7;
            border: 1px solid #f59e0b;
            padding: 8px 10px;
            margin-bottom: 15px;
            font-size: 9px;
        }

        .warning-box strong {
            color: #92400e;
        }

        .info-grid {
            display: table;
            width: 100%;
            margin-bottom: 15px;
        }

        .info-cell {
            display: table-cell;
            width: 50%;
            padding: 8px;
            border: 1px solid #e5e7eb;
            vertical-align: top;
        }

        .info-label {
            font-size: 8px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 3px;
        }

        .info-value {
            font-size: 11px;
            font-weight: bold;
            color: #333;
        }

        .section {
            margin-bottom: 18px;
            page-break-inside: avoid;
        }

        .section-title {
            font-size: 11px;
            font-weight: bold;
            color: #1f2937;
            margin-bottom: 8px;
            padding: 5px 8px;
            background: #f3f4f6;
            border-left: 3px solid #f59e0b;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 5px 8px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }

        th {
            font-size: 8px;
            text-transform: uppercase;
            color: #6b7280;
            background-color: #f9fafb;
        }

        td.right, th.right {
            text-align: right;
        }

        .row-total {
            font-weight: bold;
            background-color: #f3f4f6;
            border-top: 2px solid #d1d5db;
        }

        .pay-grid {
            display: table;
            width: 100%;
            margin-bottom: 12px;
        }

        .pay-column {
            display: table-cell;
            width: 50%;
            padding: 0 8px;
            vertical-align: top;
        }

        .pay-column:first-child {
            padding-left: 0;
        }

        .pay-column:last-child {
            padding-right: 0;
        }

        .pay-section-title {
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            color: #6b7280;
            margin-bottom: 6px;
        }

        .pay-row {
            display: table;
            width: 100%;
            padding: 4px 0;
            border-bottom: 1px solid #e5e7eb;
        }

        .pay-label {
            display: table-cell;
            color: #6b7280;
            font-size: 10px;
        }

        .pay-value {
            display: table-cell;
            text-align: right;
            font-weight: bold;
            font-size: 10px;
        }

        .highlight-row {
            background: #eff6ff;
            padding: 5px 8px;
            margin: 0 -8px;
            border-radius: 3px;
        }

        .highlight-row .pay-label,
        .highlight-row .pay-value {
            color: #1e40af;
            font-weight: bold;
        }

        .pay-summary-grid {
            display: table;
            width: 100%;
            margin-top: 12px;
        }

        .pay-summary-box {
            display: table-cell;
            width: 50%;
            padding: 10px;
            text-align: center;
            vertical-align: top;
        }

        .pay-summary-box.green {
            background: #f0fdf4;
            border: 1px solid #16a34a;
        }

        .pay-summary-box.amber {
            background: #fefce8;
            border: 1px solid #d97706;
        }

        .pay-summary-label {
            font-size: 9px;
            color: #6b7280;
        }

        .pay-summary-value {
            font-size: 18px;
            font-weight: bold;
            margin: 4px 0;
        }

        .pay-summary-box.green .pay-summary-value {
            color: #16a34a;
        }

        .pay-summary-box.amber .pay-summary-value {
            color: #d97706;
        }

        .pay-summary-detail {
            font-size: 8px;
            color: #9ca3af;
        }

        .crew-note {
            font-size: 9px;
            color: #6b7280;
            margin-bottom: 10px;
        }

        .summary-box {
            background: #1f2937;
            color: white;
            padding: 15px;
            margin-top: 15px;
        }

        .summary-title {
            font-size: 11px;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .summary-grid {
            display: table;
            width: 100%;
        }

        .summary-cell {
            display: table-cell;
            text-align: center;
            padding: 5px;
        }

        .summary-label {
            font-size: 9px;
            color: #9ca3af;
        }

        .summary-value {
            font-size: 14px;
            font-weight: bold;
            margin-top: 3px;
        }

        .summary-highlight {
            color: #fbbf24;
        }

        .summary-note {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #4b5563;
            font-size: 8px;
            color: #9ca3af;
        }

        .footer {
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px solid #e5e7eb;
            font-size: 8px;
            color: #9ca3af;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-title">PAINTER FINANCIALS BREAKDOWN</div>
            <div class="header-subtitle">{{ $projectInfo['estimate_number'] }} | {{ $projectInfo['project_type'] }} Painting | Generated: {{ now()->format('M j, Y g:i A') }}</div>
        </div>

        <div class="warning-box">
            <strong>FOR SUBCONTRACTOR OWNER ONLY</strong> - This document contains cost and pay information. Do not share with crew.
        </div>

        <div class="info-grid">
            <div class="info-cell">
                <div class="info-label">Customer</div>
                <div class="info-value">{{ $customerInfo['name'] }}</div>
            </div>
            <div class="info-cell">
                <div class="info-label">Project</div>
                <div class="info-value">{{ $projectInfo['project_type'] }} Painting - {{ $projectInfo['estimate_number'] }}</div>
            </div>
        </div>

        {{-- Materials & Sundries with Profit --}}
        <div class="section">
            <div class="section-title">MATERIALS & SUNDRIES</div>
            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th class="right">Qty</th>
                        <th class="right">Catalog Cost</th>
                        <th class="right">Catalog Total</th>
                        @if ($profitData['has_paint_actuals'])
                            <th class="right">Your Cost</th>
                            <th class="right">Actual Total</th>
                            <th class="right">Profit</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($profitData['materials_profit'] as $material)
                        <tr>
                            <td>{{ $material['brand'] }} {{ $material['name'] }}@if ($material['sheen']) - {{ $material['sheen'] }}@endif</td>
                            <td class="right">{{ $material['gallons'] }} gal</td>
                            <td class="right">${{ number_format($material['catalog_price'], 2) }}/gal</td>
                            <td class="right">${{ number_format($material['catalog_total'], 2) }}</td>
                            @if ($profitData['has_paint_actuals'])
                                <td class="right">{{ $material['actual_price'] !== null ? '$' . number_format($material['actual_price'], 2) . '/gal' : '-' }}</td>
                                <td class="right">{{ $material['actual_total'] !== null ? '$' . number_format($material['actual_total'], 2) : '-' }}</td>
                                <td class="right" style="color: {{ ($material['profit'] ?? 0) >= 0 ? '#16a34a' : '#dc2626' }}; font-weight: bold;">{{ $material['profit'] !== null ? '$' . number_format($material['profit'], 2) : '-' }}</td>
                            @endif
                        </tr>
                    @endforeach
                    <tr>
                        <td>Recycle Fee</td>
                        <td class="right">{{ ceil($materialsWithCosts['total_gallons']) }} gal</td>
                        <td class="right">${{ number_format($materialsWithCosts['recycle_fee_rate'], 2) }}/gal</td>
                        <td class="right">${{ number_format($materialsWithCosts['total_recycle_fee'], 2) }}</td>
                        @if ($profitData['has_paint_actuals'])
                            <td class="right" colspan="3"></td>
                        @endif
                    </tr>
                    <tr>
                        <td>Sundries ({{ number_format($sundriesBreakdown['labor_hours'], 1) }} hrs × ${{ number_format($sundriesBreakdown['cost_per_hour'], 2) }}/hr)</td>
                        <td class="right">-</td>
                        <td class="right">-</td>
                        <td class="right">${{ number_format($sundriesBreakdown['total_cost'], 2) }}</td>
                        @if ($profitData['has_paint_actuals'])
                            <td class="right" colspan="3"></td>
                        @endif
                    </tr>
                    <tr class="row-total">
                        <td colspan="3">Total Materials & Sundries</td>
                        <td class="right">${{ number_format($materialsWithCosts['total_materials_cost'] + $sundriesBreakdown['total_cost'], 2) }}</td>
                        @if ($profitData['has_paint_actuals'])
                            <td class="right" colspan="3"></td>
                        @endif
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- Individual Painters --}}
        @if (count($profitData['painters']) > 0)
            <div class="section">
                <div class="section-title">INDIVIDUAL PAINTERS</div>
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th class="right">Rate/hr</th>
                            <th class="right">Hours</th>
                            <th class="right">Pay</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($profitData['painters'] as $painter)
                            <tr>
                                <td>{{ $painter['name'] ?: 'Unnamed' }}</td>
                                <td class="right">${{ number_format($painter['hourly_rate'], 2) }}/hr</td>
                                <td class="right">{{ number_format($painter['hours'], 1) }} hrs</td>
                                <td class="right">${{ number_format($painter['base_pay'], 2) }}</td>
                            </tr>
                        @endforeach
                        <tr class="row-total">
                            <td colspan="2">Crew Wages</td>
                            <td class="right">{{ number_format($profitData['total_hours'], 1) }} hrs</td>
                            <td class="right">${{ number_format($profitData['total_base_pay'], 2) }}</td>
                        </tr>
                        <tr>
                            <td colspan="3" style="color: #6b7280;">+ Payroll Burden ({{ round(($profitData['burden_multiplier'] - 1) * 100) }}%)</td>
                            <td class="right" style="color: #d97706;">${{ number_format($profitData['total_burden_cost'], 2) }}</td>
                        </tr>
                        <tr class="row-total">
                            <td colspan="3">Total Labor Cost</td>
                            <td class="right">${{ number_format($profitData['total_labor_cost'], 2) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Painter Pay Calculator --}}
        <div class="section">
            <div class="section-title">PAINTER PAY CALCULATOR</div>
            <div class="pay-grid">
                <div class="pay-column">
                    <div class="pay-section-title">Pay Rates ({{ ucfirst($painterPayBreakdown['scope']) }})</div>
                    <div class="pay-row">
                        <div class="pay-label">Base Hourly Wage</div>
                        <div class="pay-value">${{ number_format($painterPayBreakdown['base_hourly_wage'], 2) }}/hr</div>
                    </div>
                    <div class="pay-row">
                        <div class="pay-label">Payroll Burden</div>
                        <div class="pay-value">+{{ $painterPayBreakdown['payroll_burden_percent'] }}%</div>
                    </div>
                    <div class="pay-row highlight-row">
                        <div class="pay-label">Effective Rate (Loaded)</div>
                        <div class="pay-value">${{ number_format($painterPayBreakdown['effective_hourly_rate'], 2) }}/hr</div>
                    </div>
                </div>
                <div class="pay-column">
                    <div class="pay-section-title">Hours & Costs</div>
                    <div class="pay-row">
                        <div class="pay-label">Labor Hours</div>
                        <div class="pay-value">{{ number_format($painterPayBreakdown['labor_hours'], 1) }} hrs</div>
                    </div>
                    <div class="pay-row">
                        <div class="pay-label">Cleanup Hours</div>
                        <div class="pay-value">{{ number_format($painterPayBreakdown['cleanup_hours'], 1) }} hrs</div>
                    </div>
                    <div class="pay-row">
                        <div class="pay-label"><strong>Total Hours</strong></div>
                        <div class="pay-value"><strong>{{ number_format($painterPayBreakdown['total_hours'], 1) }} hrs</strong></div>
                    </div>
                </div>
            </div>

            <div class="pay-summary-grid">
                <div class="pay-summary-box green">
                    <div class="pay-summary-label">Painter Earns (Base)</div>
                    <div class="pay-summary-value">${{ number_format($painterPayBreakdown['painter_earns_base'], 2) }}</div>
                    <div class="pay-summary-detail">{{ number_format($painterPayBreakdown['total_hours'], 1) }} hrs × ${{ number_format($painterPayBreakdown['base_hourly_wage'], 2) }}</div>
                </div>
                <div class="pay-summary-box amber">
                    <div class="pay-summary-label">Company Pays (Loaded)</div>
                    <div class="pay-summary-value">${{ number_format($painterPayBreakdown['company_pays_loaded'], 2) }}</div>
                    <div class="pay-summary-detail">{{ number_format($painterPayBreakdown['total_hours'], 1) }} hrs × ${{ number_format($painterPayBreakdown['effective_hourly_rate'], 2) }}</div>
                </div>
            </div>
        </div>

        {{-- Crew Size Calculator --}}
        <div class="section">
            <div class="section-title">CREW SIZE CALCULATOR</div>
            <div class="crew-note">Total labor cost stays the same regardless of crew size. More painters = faster completion.</div>
            <table>
                <thead>
                    <tr>
                        <th>Painters</th>
                        <th class="right">6-hr Day</th>
                        <th class="right">8-hr Day</th>
                        <th class="right">10-hr Day</th>
                        <th class="right">Daily Pay Each</th>
                        <th class="right">Total Job Pay</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($crewScheduleGrid['grid'] as $row)
                        <tr>
                            <td>{{ $row['painters'] }} {{ $row['painters'] === 1 ? 'painter' : 'painters' }}</td>
                            <td class="right">{{ $row['days_needed'][6] }} {{ $row['days_needed'][6] == 1 ? 'day' : 'days' }}</td>
                            <td class="right">{{ $row['days_needed'][8] }} {{ $row['days_needed'][8] == 1 ? 'day' : 'days' }}</td>
                            <td class="right">{{ $row['days_needed'][10] }} {{ $row['days_needed'][10] == 1 ? 'day' : 'days' }}</td>
                            <td class="right">${{ number_format($row['daily_pay_each'], 0) }}</td>
                            <td class="right">${{ number_format($row['total_job_pay'], 0) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Job Profit Summary --}}
        <div class="summary-box">
            <div class="summary-title">JOB PROFIT SUMMARY{{ $profitData['has_actuals'] ? '' : ' (Estimated - No Actuals Entered)' }}</div>

            <table style="width: 100%; margin-bottom: 10px;">
                <tbody>
                    <tr style="border-bottom: 2px solid #4b5563;">
                        <td style="color: white; font-weight: bold; padding: 6px 0;">Job Price</td>
                        <td style="text-align: right; color: white; font-weight: bold; font-size: 12px; padding: 6px 0;">${{ number_format($profitData['profit_summary']['job_price'], 2) }}</td>
                    </tr>
                    <tr>
                        <td style="color: #6b7280; font-size: 8px; text-transform: uppercase; letter-spacing: 0.5px; padding: 8px 0 2px 0;" colspan="2">JOB COSTS</td>
                    </tr>
                    <tr style="border-bottom: 1px solid #374151;">
                        <td style="color: #d1d5db; padding: 4px 0;">Crew Wages</td>
                        <td style="text-align: right; color: #fca5a5; padding: 4px 0;">−${{ number_format($profitData['profit_summary']['crew_wages'], 2) }}</td>
                    </tr>
                    <tr style="border-bottom: 1px solid #374151;">
                        <td style="color: #d1d5db; padding: 4px 0;">Payroll Burden (insurance, WC, taxes)</td>
                        <td style="text-align: right; color: #fcd34d; padding: 4px 0;">−${{ number_format($profitData['profit_summary']['burden_cost'], 2) }}</td>
                    </tr>
                    <tr style="border-bottom: 1px solid #374151;">
                        <td style="color: #d1d5db; padding: 4px 0;">Paint Cost{{ $profitData['has_paint_actuals'] ? ' (actual)' : ' (catalog)' }}</td>
                        <td style="text-align: right; color: #fca5a5; padding: 4px 0;">−${{ number_format($profitData['profit_summary']['paint_cost'], 2) }}</td>
                    </tr>
                    <tr style="border-bottom: 1px solid #374151;">
                        <td style="color: #d1d5db; padding: 4px 0;">Sundries</td>
                        <td style="text-align: right; color: #fca5a5; padding: 4px 0;">−${{ number_format($profitData['profit_summary']['sundries_cost'], 2) }}</td>
                    </tr>
                    <tr style="border-bottom: 1px solid #374151;">
                        <td style="color: #d1d5db; padding: 4px 0;">Recycle Fees</td>
                        <td style="text-align: right; color: #fca5a5; padding: 4px 0;">−${{ number_format($profitData['profit_summary']['recycle_fee'], 2) }}</td>
                    </tr>
                    <tr style="border-bottom: 1px solid #4b5563;">
                        <td style="color: #9ca3af; font-weight: bold; padding: 6px 0;">Total Costs</td>
                        <td style="text-align: right; color: #9ca3af; font-weight: bold; font-size: 12px; padding: 6px 0;">−${{ number_format($profitData['profit_summary']['total_costs'], 2) }}</td>
                    </tr>
                    <tr>
                        <td style="color: white; font-weight: bold; font-size: 12px; padding: 8px 0;">Your Profit</td>
                        <td style="text-align: right; font-weight: bold; font-size: 14px; padding: 8px 0; color: {{ $profitData['profit_summary']['gross_profit'] >= 0 ? '#4ade80' : '#f87171' }};">
                            {{ $profitData['profit_summary']['gross_profit'] >= 0 ? '+' : '' }}${{ number_format($profitData['profit_summary']['gross_profit'], 2) }}
                            <span style="font-size: 10px; color: #9ca3af;">({{ number_format($profitData['profit_summary']['gross_margin_percent'], 1) }}%)</span>
                        </td>
                    </tr>
                </tbody>
            </table>

            @if ($profitData['snapshot'])
                <div class="summary-note">
                    Last snapshot saved: {{ \Carbon\Carbon::parse($profitData['snapshot']['captured_at'])->format('M j, Y g:i A') }}
                    | Profit at snapshot: ${{ number_format($profitData['snapshot']['gross_profit'] ?? 0, 2) }}
                </div>
            @else
                <div class="summary-note">
                    No profit snapshot saved yet. Save one from the web interface to track changes over time.
                </div>
            @endif
        </div>

        <div class="footer">
            Financials: {{ $projectInfo['estimate_number'] }} | Generated by {{ config('app.name') }} | {{ now()->format('M j, Y g:i A') }} | CONFIDENTIAL - FOR SUBCONTRACTOR OWNER ONLY
        </div>
    </div>
</body>
</html>
