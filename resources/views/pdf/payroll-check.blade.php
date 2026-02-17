<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payroll Check - {{ $stub->check_number }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Courier New', 'Courier', monospace;
            font-size: 10px;
            line-height: 1.4;
            color: #333;
        }

        .page {
            width: 100%;
            padding: 20px 30px;
        }

        .check-section {
            border: 1px solid #ccc;
            padding: 20px;
            margin-bottom: 0;
            position: relative;
            min-height: 280px;
        }

        .check-number {
            position: absolute;
            top: 15px;
            right: 20px;
            font-size: 12px;
            font-weight: bold;
            color: #666;
        }

        .company-name {
            font-size: 14px;
            font-weight: bold;
            color: #1e40af;
            margin-bottom: 2px;
        }

        .company-address {
            font-size: 9px;
            color: #666;
            margin-bottom: 15px;
        }

        .date-line {
            text-align: right;
            font-size: 11px;
            margin-bottom: 15px;
        }

        .date-label {
            color: #666;
        }

        .pay-to-section {
            margin-bottom: 10px;
        }

        .pay-to-label {
            font-size: 9px;
            color: #666;
            text-transform: uppercase;
        }

        .pay-to-name {
            font-size: 14px;
            font-weight: bold;
            border-bottom: 1px solid #333;
            padding-bottom: 3px;
            display: inline-block;
            min-width: 70%;
        }

        .amount-box {
            border: 2px solid #333;
            padding: 5px 10px;
            display: inline-block;
            font-size: 14px;
            font-weight: bold;
            float: right;
            margin-top: -25px;
        }

        .amount-words {
            font-size: 10px;
            border-bottom: 1px solid #333;
            padding-bottom: 3px;
            margin-bottom: 10px;
            min-height: 16px;
        }

        .memo-line {
            margin-top: 20px;
            font-size: 9px;
        }

        .memo-label {
            color: #666;
        }

        .memo-value {
            border-bottom: 1px solid #ccc;
            display: inline-block;
            min-width: 60%;
            padding-bottom: 2px;
        }

        .signature-line {
            float: right;
            margin-top: -15px;
            text-align: center;
        }

        .signature-border {
            border-bottom: 1px solid #333;
            width: 200px;
            margin-bottom: 3px;
            height: 20px;
        }

        .signature-label {
            font-size: 8px;
            color: #666;
        }

        /* Perforated line */
        .perforation {
            border-top: 2px dashed #999;
            margin: 15px 0;
            position: relative;
        }

        .perforation-label {
            position: absolute;
            top: -8px;
            left: 50%;
            transform: translateX(-50%);
            background: white;
            padding: 0 10px;
            font-size: 7px;
            color: #999;
            text-transform: uppercase;
        }

        /* Stub section */
        .stub-section {
            padding: 15px 0;
        }

        .stub-header {
            display: table;
            width: 100%;
            margin-bottom: 10px;
        }

        .stub-header-left {
            display: table-cell;
            vertical-align: top;
            width: 50%;
        }

        .stub-header-right {
            display: table-cell;
            vertical-align: top;
            width: 50%;
            text-align: right;
        }

        .stub-label {
            font-size: 8px;
            color: #999;
            text-transform: uppercase;
        }

        .stub-value {
            font-size: 10px;
            font-weight: bold;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
        }

        th {
            background-color: #f1f5f9;
            padding: 4px 6px;
            text-align: left;
            font-size: 8px;
            text-transform: uppercase;
            color: #64748b;
            border-bottom: 1px solid #e2e8f0;
        }

        th.right {
            text-align: right;
        }

        td {
            padding: 3px 6px;
            font-size: 9px;
            border-bottom: 1px solid #f1f5f9;
        }

        td.right {
            text-align: right;
        }

        .total-row td {
            font-weight: bold;
            border-top: 1px solid #ccc;
        }

        .net-row {
            display: table;
            width: 100%;
            margin-top: 8px;
            padding: 6px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
        }

        .net-label {
            display: table-cell;
            font-weight: bold;
            font-size: 10px;
        }

        .net-amount {
            display: table-cell;
            text-align: right;
            font-weight: bold;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="page">
        {{-- Check Section --}}
        <div class="check-section">
            <div class="check-number"># {{ $stub->check_number ?? '----' }}</div>

            <div class="company-name">{{ $company->company_name ?? config('app.name') }}</div>
            @if($company->address_line_1)
                <div class="company-address">
                    {{ $company->address_line_1 }}
                    @if($company->city), {{ $company->city }}, {{ $company->state }} {{ $company->zip_code }}@endif
                </div>
            @endif

            <div class="date-line">
                <span class="date-label">Date: </span>
                {{ $period->processed_at ? $period->processed_at->format('m/d/Y') : $period->end_date->format('m/d/Y') }}
            </div>

            <div class="pay-to-section">
                <div class="pay-to-label">Pay to the order of</div>
                <div class="pay-to-name">{{ $employee->user->name }}</div>
                <div class="amount-box">${{ number_format($stub->net_pay, 2) }}</div>
            </div>

            <div style="clear: both;"></div>

            <div class="amount-words">
                {{ ucfirst(\NumberFormatter::create('en', \NumberFormatter::SPELLOUT)->format(floor($stub->net_pay))) }}
                and {{ str_pad(round(($stub->net_pay - floor($stub->net_pay)) * 100), 2, '0', STR_PAD_LEFT) }}/100 dollars
            </div>

            <div class="memo-line">
                <span class="memo-label">Memo: </span>
                <span class="memo-value">Pay period {{ $period->start_date->format('m/d/Y') }} - {{ $period->end_date->format('m/d/Y') }}</span>
            </div>

            <div class="signature-line">
                <div class="signature-border"></div>
                <div class="signature-label">Authorized Signature</div>
            </div>

            <div style="clear: both;"></div>
        </div>

        {{-- Perforation --}}
        <div class="perforation">
            <span class="perforation-label">Detach and retain for your records</span>
        </div>

        {{-- Detachable Stub --}}
        <div class="stub-section">
            <div class="stub-header">
                <div class="stub-header-left">
                    <div class="stub-label">Employee</div>
                    <div class="stub-value">{{ $employee->user->name }} ({{ $employee->employee_number }})</div>
                </div>
                <div class="stub-header-right">
                    <div class="stub-label">Pay Period</div>
                    <div class="stub-value">{{ $period->start_date->format('M j') }} - {{ $period->end_date->format('M j, Y') }}</div>
                    <div class="stub-label" style="margin-top: 3px;">Check #</div>
                    <div class="stub-value">{{ $stub->check_number ?? 'N/A' }}</div>
                </div>
            </div>

            {{-- Earnings Summary --}}
            <table>
                <thead>
                    <tr>
                        <th>Earnings</th>
                        <th class="right">Hours</th>
                        <th class="right">Rate</th>
                        <th class="right">Current</th>
                        <th class="right">YTD</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Regular</td>
                        <td class="right">{{ number_format($stub->hours_regular, 2) }}</td>
                        <td class="right">${{ number_format($stub->rate_regular, 2) }}</td>
                        <td class="right">${{ number_format($stub->hours_regular * $stub->rate_regular, 2) }}</td>
                        <td class="right">--</td>
                    </tr>
                    @if($stub->hours_overtime > 0)
                        <tr>
                            <td>Overtime</td>
                            <td class="right">{{ number_format($stub->hours_overtime, 2) }}</td>
                            <td class="right">${{ number_format($stub->rate_overtime, 2) }}</td>
                            <td class="right">${{ number_format($stub->hours_overtime * $stub->rate_overtime, 2) }}</td>
                            <td class="right">--</td>
                        </tr>
                    @endif
                    <tr class="total-row">
                        <td>Gross Pay</td>
                        <td></td>
                        <td></td>
                        <td class="right">${{ number_format($stub->gross_pay, 2) }}</td>
                        <td class="right">${{ number_format($stub->ytd_gross, 2) }}</td>
                    </tr>
                </tbody>
            </table>

            {{-- Taxes & Deductions Summary --}}
            <table>
                <thead>
                    <tr>
                        <th>Taxes &amp; Deductions</th>
                        <th class="right">Current</th>
                        <th class="right">YTD</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Federal Tax</td>
                        <td class="right">${{ number_format($stub->federal_tax, 2) }}</td>
                        <td class="right">${{ number_format($stub->ytd_federal_tax, 2) }}</td>
                    </tr>
                    <tr>
                        <td>State Tax (CO)</td>
                        <td class="right">${{ number_format($stub->state_tax, 2) }}</td>
                        <td class="right">${{ number_format($stub->ytd_state_tax, 2) }}</td>
                    </tr>
                    <tr>
                        <td>Social Security</td>
                        <td class="right">${{ number_format($stub->social_security, 2) }}</td>
                        <td class="right">${{ number_format($stub->ytd_social_security, 2) }}</td>
                    </tr>
                    <tr>
                        <td>Medicare</td>
                        <td class="right">${{ number_format($stub->medicare + $stub->additional_medicare, 2) }}</td>
                        <td class="right">${{ number_format($stub->ytd_medicare, 2) }}</td>
                    </tr>
                    @foreach($stub->pretax_deductions ?? [] as $ded)
                        <tr>
                            <td>{{ $ded['description'] }} (pre-tax)</td>
                            <td class="right">${{ number_format($ded['amount'], 2) }}</td>
                            <td class="right">--</td>
                        </tr>
                    @endforeach
                    @foreach($stub->posttax_deductions ?? [] as $ded)
                        <tr>
                            <td>{{ $ded['description'] }} (post-tax)</td>
                            <td class="right">${{ number_format($ded['amount'], 2) }}</td>
                            <td class="right">--</td>
                        </tr>
                    @endforeach
                    <tr class="total-row">
                        <td>Total Deductions</td>
                        <td class="right">${{ number_format($stub->total_deductions, 2) }}</td>
                        <td class="right">--</td>
                    </tr>
                </tbody>
            </table>

            <div class="net-row">
                <span class="net-label">NET PAY</span>
                <span class="net-amount">${{ number_format($stub->net_pay, 2) }}</span>
            </div>
        </div>
    </div>
</body>
</html>
