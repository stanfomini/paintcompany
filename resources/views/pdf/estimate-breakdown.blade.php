<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cost Breakdown - {{ $estimate->estimate_number }}</title>
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
            padding: 30px;
        }

        .header {
            margin-bottom: 30px;
            border-bottom: 2px solid #dc2626;
            padding-bottom: 15px;
        }

        .header-title {
            font-size: 20px;
            font-weight: bold;
            color: #dc2626;
            margin-bottom: 5px;
        }

        .header-subtitle {
            font-size: 12px;
            color: #666;
        }

        .warning-box {
            background-color: #fef3c7;
            border: 1px solid #f59e0b;
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 4px;
        }

        .warning-box strong {
            color: #92400e;
        }

        .summary-grid {
            display: table;
            width: 100%;
            margin-bottom: 20px;
        }

        .summary-card {
            display: table-cell;
            width: 25%;
            padding: 10px;
            border: 1px solid #e5e7eb;
            text-align: center;
            vertical-align: top;
        }

        .summary-label {
            font-size: 9px;
            color: #666;
            text-transform: uppercase;
        }

        .summary-value {
            font-size: 16px;
            font-weight: bold;
            margin: 5px 0;
        }

        .summary-sublabel {
            font-size: 8px;
            color: #888;
        }

        .positive { color: #16a34a; }
        .negative { color: #dc2626; }
        .warning { color: #d97706; }

        .section {
            margin-bottom: 20px;
            page-break-inside: avoid;
        }

        .section-title {
            font-size: 11px;
            font-weight: bold;
            color: #374151;
            margin-bottom: 8px;
            padding-bottom: 4px;
            border-bottom: 1px solid #e5e7eb;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 6px 8px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }

        th {
            font-size: 9px;
            text-transform: uppercase;
            color: #666;
            background-color: #f9fafb;
        }

        td.right, th.right {
            text-align: right;
        }

        .row-bold {
            font-weight: bold;
            background-color: #f3f4f6;
        }

        .row-total {
            font-weight: bold;
            border-top: 2px solid #333;
        }

        .highlight-box {
            background-color: #eff6ff;
            border: 1px solid #3b82f6;
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
        }

        .profit-positive {
            background-color: #f0fdf4;
            border-color: #16a34a;
        }

        .profit-negative {
            background-color: #fef2f2;
            border-color: #dc2626;
        }

        .footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 1px solid #e5e7eb;
            font-size: 8px;
            color: #666;
        }

        .page-break {
            page-break-before: always;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-title">COMPANY COST BREAKDOWN</div>
            <div class="header-subtitle">
                {{ $estimate->estimate_number }} | {{ ucfirst($estimate->project_type ?? 'Interior') }} | Generated: {{ now()->format('M j, Y g:i A') }}
            </div>
        </div>

        <div class="warning-box">
            <strong>INTERNAL ONLY</strong> - This breakdown reveals true costs and profit margins. Never share with customers.
        </div>

        {{-- Summary Cards --}}
        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-label">Customer Price</div>
                <div class="summary-value">${{ number_format($breakdown['pricing']['customer_price'], 2) }}</div>
                <div class="summary-sublabel">COGS ratio: {{ number_format($breakdown['pricing']['margin_percent'], 1) }}%</div>
            </div>
            <div class="summary-card">
                <div class="summary-label">Gross Profit</div>
                <div class="summary-value {{ $breakdown['gross_profit']['amount'] >= 0 ? 'positive' : 'negative' }}">
                    ${{ number_format($breakdown['gross_profit']['amount'], 2) }}
                </div>
                <div class="summary-sublabel">{{ number_format($breakdown['gross_profit']['percent'], 1) }}% of price</div>
            </div>
            <div class="summary-card">
                <div class="summary-label">Net Profit</div>
                <div class="summary-value {{ $breakdown['net_profit']['amount'] >= 0 ? 'positive' : 'negative' }}">
                    ${{ number_format($breakdown['net_profit']['amount'], 2) }}
                </div>
                <div class="summary-sublabel {{ $breakdown['net_profit']['meets_target'] ? 'positive' : 'warning' }}">
                    {{ number_format($breakdown['net_profit']['percent'], 1) }}% (target: {{ number_format($breakdown['net_profit']['target_percent'], 1) }}%)
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-label">Target Delta</div>
                <div class="summary-value {{ $breakdown['net_profit']['delta_percent'] >= 0 ? 'positive' : 'negative' }}">
                    {{ $breakdown['net_profit']['delta_percent'] >= 0 ? '+' : '' }}{{ number_format($breakdown['net_profit']['delta_percent'], 1) }}%
                </div>
                <div class="summary-sublabel">
                    {{ $breakdown['net_profit']['meets_target'] ? 'Meets target' : 'Below target' }}
                </div>
            </div>
        </div>

        {{-- Areas (from Estimate) --}}
        @if (count($breakdown['areas']) > 0)
        <div class="section">
            <div class="section-title">1. AREAS (From Estimate)</div>
            <table>
                <thead>
                    <tr>
                        <th>Area</th>
                        <th class="right">Hours</th>
                        <th class="right">COGS</th>
                        <th class="right">Margin</th>
                        <th class="right">Customer Price</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($breakdown['areas'] as $area)
                    <tr>
                        <td>{{ $area['name'] }} <span style="color: #888; font-size: 9px;">({{ ucfirst($area['scope_type']) }})</span></td>
                        <td class="right">{{ number_format($area['detail']['labor_hours'] + $area['detail']['cleanup_hours'], 1) }}</td>
                        <td class="right">${{ number_format($area['cogs'], 2) }}</td>
                        <td class="right">{{ number_format($area['margin_percent'], 0) }}%</td>
                        <td class="right">${{ number_format($area['customer_price'], 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    @if (($breakdown['items_total'] ?? 0) > 0)
                    <tr>
                        <td>Line Items (at cost)</td>
                        <td class="right"></td>
                        <td class="right">${{ number_format($breakdown['items_total'], 2) }}</td>
                        <td class="right">-</td>
                        <td class="right">${{ number_format($breakdown['items_total'], 2) }}</td>
                    </tr>
                    @endif
                    <tr class="row-total">
                        <td>TOTALS</td>
                        <td class="right">{{ number_format($breakdown['totals']['total_hours'], 1) }}</td>
                        <td class="right">${{ number_format($breakdown['cogs']['total'], 2) }}</td>
                        <td class="right">{{ number_format($breakdown['pricing']['margin_percent'], 0) }}%</td>
                        <td class="right">${{ number_format($breakdown['pricing']['customer_price'], 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        @endif

        {{-- COGS Detail --}}
        <div class="section">
            <div class="section-title">2. COGS DETAIL (Components from Area Surfaces)</div>
            <table>
                <tr>
                    <td>Labor: {{ number_format($breakdown['totals']['labor_hours'], 1) }} hrs</td>
                    <td class="right">${{ number_format($breakdown['cogs']['labor_cost'], 2) }}</td>
                </tr>
                <tr>
                    <td>Cleanup: {{ number_format($breakdown['totals']['cleanup_hours'], 2) }} hrs</td>
                    <td class="right">${{ number_format($breakdown['cogs']['cleanup_cost'], 2) }}</td>
                </tr>
                <tr>
                    <td>Sundries</td>
                    <td class="right">${{ number_format($breakdown['cogs']['sundries_cost'], 2) }}</td>
                </tr>
                <tr>
                    <td>Materials</td>
                    <td class="right">${{ number_format($breakdown['cogs']['material_cost'], 2) }}</td>
                </tr>
                @if ($breakdown['cogs']['material_tax'] > 0)
                <tr>
                    <td>Material Tax ({{ number_format($breakdown['settings_used']['material_tax_rate'], 1) }}%)</td>
                    <td class="right">${{ number_format($breakdown['cogs']['material_tax'], 2) }}</td>
                </tr>
                @endif
                @if ($breakdown['cogs']['labor_tax'] > 0)
                <tr>
                    <td>Labor Tax ({{ number_format($breakdown['settings_used']['labor_tax_rate'], 1) }}%)</td>
                    <td class="right">${{ number_format($breakdown['cogs']['labor_tax'], 2) }}</td>
                </tr>
                @endif
                @if ($breakdown['cogs']['addon_cost'] > 0)
                <tr>
                    <td>Addons (Nested Areas)</td>
                    <td class="right">${{ number_format($breakdown['cogs']['addon_cost'], 2) }}</td>
                </tr>
                @endif
                @if (($breakdown['cogs']['items_total'] ?? 0) > 0)
                <tr>
                    <td>Line Items ({{ count($breakdown['items'] ?? []) }})</td>
                    <td class="right">${{ number_format($breakdown['cogs']['items_total'], 2) }}</td>
                </tr>
                @endif
                <tr class="row-total">
                    <td>TOTAL COGS</td>
                    <td class="right">${{ number_format($breakdown['cogs']['total'], 2) }}</td>
                </tr>
            </table>
        </div>

        {{-- Customer Price --}}
        <div class="highlight-box">
            <div class="section-title" style="border: none; margin: 0;">3. CUSTOMER PRICE (Per-Area Margins Applied)</div>
            <table style="margin-top: 8px;">
                <tr>
                    <td>Formula per area: COGS / (margin% / 100)</td>
                    <td class="right"></td>
                </tr>
                @foreach ($breakdown['areas'] as $area)
                <tr>
                    <td>{{ $area['name'] }} ({{ ucfirst($area['scope_type']) }} {{ number_format($area['margin_percent'], 0) }}%): ${{ number_format($area['cogs'], 2) }} / {{ number_format($area['margin_percent'] / 100, 2) }}</td>
                    <td class="right">${{ number_format($area['customer_price'], 2) }}</td>
                </tr>
                @endforeach
                @if (($breakdown['items_total'] ?? 0) > 0)
                <tr>
                    <td>Line Items (at cost)</td>
                    <td class="right">${{ number_format($breakdown['items_total'], 2) }}</td>
                </tr>
                @endif
                <tr class="row-bold">
                    <td>CUSTOMER PRICE</td>
                    <td class="right">${{ number_format($breakdown['pricing']['customer_price'], 2) }}</td>
                </tr>
            </table>
        </div>

        {{-- Gross Profit --}}
        <div class="section">
            <div class="section-title">4. GROSS PROFIT (Before Allocations)</div>
            <table>
                <tr>
                    <td>Customer Price - COGS</td>
                    <td class="right">${{ number_format($breakdown['pricing']['customer_price'], 2) }} - ${{ number_format($breakdown['cogs']['total'], 2) }}</td>
                </tr>
                <tr class="row-total">
                    <td>GROSS PROFIT</td>
                    <td class="right {{ $breakdown['gross_profit']['amount'] >= 0 ? 'positive' : 'negative' }}">
                        ${{ number_format($breakdown['gross_profit']['amount'], 2) }} ({{ number_format($breakdown['gross_profit']['percent'], 1) }}%)
                    </td>
                </tr>
            </table>
        </div>

        {{-- Allocations --}}
        <div class="section">
            <div class="section-title">5. ALLOCATIONS (% of Revenue)</div>
            <table>
                <tr>
                    <td>Marketing ({{ number_format($breakdown['allocations']['marketing']['rate'], 1) }}%)</td>
                    <td class="right">${{ number_format($breakdown['allocations']['marketing']['amount'], 2) }}</td>
                </tr>
                <tr>
                    <td>Sales ({{ number_format($breakdown['allocations']['sales']['rate'], 1) }}%)</td>
                    <td class="right">${{ number_format($breakdown['allocations']['sales']['amount'], 2) }}</td>
                </tr>
                <tr>
                    <td>Production Mgmt ({{ number_format($breakdown['allocations']['production_management']['rate'], 1) }}%)</td>
                    <td class="right">${{ number_format($breakdown['allocations']['production_management']['amount'], 2) }}</td>
                </tr>
                @if (isset($breakdown['allocations']['overhead']))
                <tr style="color: #888;">
                    <td>General Overhead ({{ number_format($breakdown['allocations']['overhead']['rate'], 1) }}%) <em>informational</em></td>
                    <td class="right">${{ number_format($breakdown['allocations']['overhead']['amount'], 2) }}</td>
                </tr>
                @endif
                <tr class="row-total">
                    <td>TOTAL ALLOCATIONS ({{ number_format($breakdown['allocations']['total_percent'], 1) }}%)</td>
                    <td class="right">${{ number_format($breakdown['allocations']['total'], 2) }}</td>
                </tr>
            </table>
        </div>

        {{-- Net Profit --}}
        <div class="highlight-box {{ $breakdown['net_profit']['is_profitable'] ? 'profit-positive' : 'profit-negative' }}">
            <div class="section-title" style="border: none; margin: 0;">6. NET PROFIT (The Truth)</div>
            <table style="margin-top: 8px;">
                <tr>
                    <td>Gross Profit - Total Allocations</td>
                    <td class="right">${{ number_format($breakdown['gross_profit']['amount'], 2) }} - ${{ number_format($breakdown['allocations']['total'], 2) }}</td>
                </tr>
                <tr class="row-bold">
                    <td>NET PROFIT</td>
                    <td class="right {{ $breakdown['net_profit']['is_profitable'] ? 'positive' : 'negative' }}">
                        ${{ number_format($breakdown['net_profit']['amount'], 2) }} ({{ number_format($breakdown['net_profit']['percent'], 1) }}%)
                    </td>
                </tr>
                <tr>
                    <td>Target: {{ number_format($breakdown['net_profit']['target_percent'], 1) }}%</td>
                    <td class="right {{ $breakdown['net_profit']['meets_target'] ? 'positive' : 'warning' }}">
                        Delta: {{ $breakdown['net_profit']['delta_percent'] >= 0 ? '+' : '' }}{{ number_format($breakdown['net_profit']['delta_percent'], 1) }}%
                        {{ $breakdown['net_profit']['meets_target'] ? '(Meets target)' : '(Below target)' }}
                    </td>
                </tr>
            </table>
        </div>

        {{-- Line Items --}}
        @if (count($breakdown['items'] ?? []) > 0)
        <div class="section">
            <div class="section-title">LINE ITEMS</div>
            <table>
                <thead>
                    <tr>
                        <th>Description</th>
                        <th class="right">Qty</th>
                        <th class="right">Unit Price</th>
                        <th class="right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($breakdown['items'] as $item)
                    <tr>
                        <td>{{ $item['description'] }}</td>
                        <td class="right">{{ number_format($item['quantity'], 2) }} {{ $item['unit'] }}</td>
                        <td class="right">${{ number_format($item['unit_price'], 2) }}</td>
                        <td class="right">${{ number_format($item['total'], 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="row-total">
                        <td colspan="3">Total</td>
                        <td class="right">${{ number_format($breakdown['items_total'], 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        @endif

        {{-- Reconciliation --}}
        @if (isset($breakdown['reconciliation']) && abs($breakdown['reconciliation']['difference']) > 1)
        <div class="warning-box">
            <strong>PRICE RECONCILIATION</strong><br>
            Stored Subtotal: ${{ number_format($breakdown['reconciliation']['stored_subtotal'], 2) }}<br>
            Calculated Price: ${{ number_format($breakdown['reconciliation']['calculated_price'], 2) }}<br>
            Difference: {{ $breakdown['reconciliation']['difference'] > 0 ? '+' : '' }}${{ number_format($breakdown['reconciliation']['difference'], 2) }}
        </div>
        @endif

        {{-- Settings Reference --}}
        <div class="section">
            <div class="section-title">SETTINGS USED</div>
            <table>
                <tr>
                    <td>Scope</td>
                    <td>{{ ucfirst($breakdown['settings_used']['scope']) }}</td>
                    <td>Hourly Wage</td>
                    <td>${{ number_format($breakdown['settings_used']['hourly_wage'], 2) }}</td>
                </tr>
                <tr>
                    <td>Payroll Burden</td>
                    <td>{{ number_format($breakdown['settings_used']['payroll_burden_multiplier'], 2) }}x</td>
                    <td>Margin</td>
                    <td>{{ number_format($breakdown['settings_used']['margin_percent'], 1) }}%</td>
                </tr>
                <tr>
                    <td>Cleanup Ratio</td>
                    <td>{{ number_format($breakdown['settings_used']['cleanup_hours_ratio'], 0) }}:1</td>
                    <td>Sundries/Hr</td>
                    <td>${{ number_format($breakdown['settings_used']['sundries_cost_per_hour'], 2) }}</td>
                </tr>
                <tr>
                    <td>Material Tax</td>
                    <td>{{ number_format($breakdown['settings_used']['material_tax_rate'], 1) }}%</td>
                    <td>Target Net Margin</td>
                    <td>{{ number_format($breakdown['settings_used']['net_profit_margin_percent'], 1) }}%</td>
                </tr>
            </table>
        </div>

        <div class="footer">
            Generated by {{ config('app.name') }} | {{ now()->format('M j, Y g:i A') }} | CONFIDENTIAL - INTERNAL USE ONLY
        </div>
    </div>
</body>
</html>
