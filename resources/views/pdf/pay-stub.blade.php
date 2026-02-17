<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pay Stub - {{ $employee->employee_number }}</title>
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
            margin-bottom: 20px;
            border-bottom: 2px solid #2563eb;
            padding-bottom: 15px;
            display: table;
            width: 100%;
        }

        .header-left {
            display: table-cell;
            vertical-align: top;
            width: 50%;
        }

        .header-right {
            display: table-cell;
            vertical-align: top;
            width: 50%;
            text-align: right;
        }

        .company-name {
            font-size: 18px;
            font-weight: bold;
            color: #2563eb;
            margin-bottom: 3px;
        }

        .company-info {
            font-size: 9px;
            color: #666;
        }

        .stub-title {
            font-size: 14px;
            font-weight: bold;
            color: #333;
            margin-bottom: 3px;
        }

        .info-row {
            display: table;
            width: 100%;
            margin-bottom: 15px;
        }

        .info-col {
            display: table-cell;
            vertical-align: top;
            width: 50%;
        }

        .info-label {
            font-size: 8px;
            text-transform: uppercase;
            color: #999;
            margin-bottom: 2px;
        }

        .info-value {
            font-size: 10px;
            color: #333;
            font-weight: 500;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }

        th {
            background-color: #f1f5f9;
            padding: 6px 8px;
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
            padding: 5px 8px;
            font-size: 10px;
            border-bottom: 1px solid #f1f5f9;
        }

        td.right {
            text-align: right;
        }

        .section-title {
            font-size: 11px;
            font-weight: bold;
            color: #2563eb;
            margin-bottom: 8px;
            margin-top: 15px;
        }

        .total-row td {
            font-weight: bold;
            background-color: #f8fafc;
            border-top: 2px solid #e2e8f0;
        }

        .net-pay-box {
            background-color: #eff6ff;
            border: 2px solid #2563eb;
            border-radius: 6px;
            padding: 12px;
            margin-top: 15px;
            display: table;
            width: 100%;
        }

        .net-pay-label {
            display: table-cell;
            vertical-align: middle;
            font-size: 12px;
            font-weight: bold;
            color: #1e40af;
        }

        .net-pay-amount {
            display: table-cell;
            vertical-align: middle;
            text-align: right;
            font-size: 20px;
            font-weight: bold;
            color: #1e40af;
        }

        .ytd-section {
            margin-top: 20px;
            border-top: 1px solid #e2e8f0;
            padding-top: 10px;
        }

        .ytd-grid {
            display: table;
            width: 100%;
        }

        .ytd-item {
            display: table-cell;
            text-align: center;
            padding: 5px;
        }

        .ytd-label {
            font-size: 8px;
            text-transform: uppercase;
            color: #999;
        }

        .ytd-value {
            font-size: 10px;
            font-weight: bold;
            color: #333;
        }

        .footer {
            margin-top: 30px;
            padding-top: 10px;
            border-top: 1px solid #e2e8f0;
            font-size: 8px;
            color: #999;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        {{-- Header --}}
        <div class="header">
            <div class="header-left">
                <div class="company-name">{{ $company->company_name ?? config('app.name') }}</div>
                @if($company->address_line_1)
                    <div class="company-info">{{ $company->address_line_1 }}</div>
                @endif
                @if($company->city && $company->state)
                    <div class="company-info">{{ $company->city }}, {{ $company->state }} {{ $company->zip_code }}</div>
                @endif
                @if($company->company_phone)
                    <div class="company-info">{{ $company->company_phone }}</div>
                @endif
            </div>
            <div class="header-right">
                <div class="stub-title">Pay Stub</div>
                <div class="company-info">Pay Period: {{ $period->start_date->format('M j, Y') }} - {{ $period->end_date->format('M j, Y') }}</div>
                <div class="company-info">Pay Date: {{ $period->processed_at ? $period->processed_at->format('M j, Y') : $period->end_date->format('M j, Y') }}</div>
                @if($stub->check_number)
                    <div class="company-info">Check #: {{ $stub->check_number }}</div>
                @endif
            </div>
        </div>

        {{-- Employee Info --}}
        <div class="info-row">
            <div class="info-col">
                <div class="info-label">Employee Name</div>
                <div class="info-value">{{ $employee->user->name }}</div>
                <div class="info-label" style="margin-top: 5px;">Employee Number</div>
                <div class="info-value">{{ $employee->employee_number }}</div>
            </div>
            <div class="info-col">
                <div class="info-label">Department</div>
                <div class="info-value">{{ ucfirst($employee->department ?? 'N/A') }}</div>
                <div class="info-label" style="margin-top: 5px;">Pay Type</div>
                <div class="info-value">{{ ucfirst($employee->pay_type) }}</div>
            </div>
        </div>

        {{-- Earnings --}}
        <div class="section-title">Earnings</div>
        <table>
            <thead>
                <tr>
                    <th>Description</th>
                    <th class="right">Hours</th>
                    <th class="right">Rate</th>
                    <th class="right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Regular Pay</td>
                    <td class="right">{{ number_format($stub->hours_regular, 2) }}</td>
                    <td class="right">${{ number_format($stub->rate_regular, 2) }}</td>
                    <td class="right">${{ number_format($stub->hours_regular * $stub->rate_regular, 2) }}</td>
                </tr>
                @if($stub->hours_overtime > 0)
                    <tr>
                        <td>Overtime Pay (1.5x)</td>
                        <td class="right">{{ number_format($stub->hours_overtime, 2) }}</td>
                        <td class="right">${{ number_format($stub->rate_overtime, 2) }}</td>
                        <td class="right">${{ number_format($stub->hours_overtime * $stub->rate_overtime, 2) }}</td>
                    </tr>
                @endif
                @if($stub->hours_pto > 0)
                    <tr>
                        <td>PTO Pay</td>
                        <td class="right">{{ number_format($stub->hours_pto, 2) }}</td>
                        <td class="right">${{ number_format($stub->rate_regular, 2) }}</td>
                        <td class="right">${{ number_format($stub->hours_pto * $stub->rate_regular, 2) }}</td>
                    </tr>
                @endif
                <tr class="total-row">
                    <td>Gross Pay</td>
                    <td class="right">{{ number_format($stub->hours_regular + $stub->hours_overtime + $stub->hours_pto, 2) }}</td>
                    <td></td>
                    <td class="right">${{ number_format($stub->gross_pay, 2) }}</td>
                </tr>
            </tbody>
        </table>

        {{-- Tax Withholdings --}}
        <div class="section-title">Tax Withholdings</div>
        <table>
            <thead>
                <tr>
                    <th>Tax</th>
                    <th class="right">Current</th>
                    <th class="right">YTD</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Federal Income Tax</td>
                    <td class="right">${{ number_format($stub->federal_tax, 2) }}</td>
                    <td class="right">${{ number_format($stub->ytd_federal_tax, 2) }}</td>
                </tr>
                <tr>
                    <td>State Income Tax (CO 4.4%)</td>
                    <td class="right">${{ number_format($stub->state_tax, 2) }}</td>
                    <td class="right">${{ number_format($stub->ytd_state_tax, 2) }}</td>
                </tr>
                <tr>
                    <td>Social Security (6.2%)</td>
                    <td class="right">${{ number_format($stub->social_security, 2) }}</td>
                    <td class="right">${{ number_format($stub->ytd_social_security, 2) }}</td>
                </tr>
                <tr>
                    <td>Medicare (1.45%)</td>
                    <td class="right">${{ number_format($stub->medicare, 2) }}</td>
                    <td class="right">${{ number_format($stub->ytd_medicare, 2) }}</td>
                </tr>
                @if($stub->additional_medicare > 0)
                    <tr>
                        <td>Additional Medicare (0.9%)</td>
                        <td class="right">${{ number_format($stub->additional_medicare, 2) }}</td>
                        <td class="right">--</td>
                    </tr>
                @endif
            </tbody>
        </table>

        {{-- Deductions --}}
        @if(!empty($stub->pretax_deductions) || !empty($stub->posttax_deductions))
            <div class="section-title">Deductions</div>
            <table>
                <thead>
                    <tr>
                        <th>Description</th>
                        <th>Type</th>
                        <th class="right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($stub->pretax_deductions ?? [] as $ded)
                        <tr>
                            <td>{{ $ded['description'] }}</td>
                            <td>Pre-tax</td>
                            <td class="right">${{ number_format($ded['amount'], 2) }}</td>
                        </tr>
                    @endforeach
                    @foreach($stub->posttax_deductions ?? [] as $ded)
                        <tr>
                            <td>{{ $ded['description'] }}</td>
                            <td>Post-tax</td>
                            <td class="right">${{ number_format($ded['amount'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        {{-- Net Pay --}}
        <div class="net-pay-box">
            <div class="net-pay-label">Net Pay</div>
            <div class="net-pay-amount">${{ number_format($stub->net_pay, 2) }}</div>
        </div>

        {{-- YTD Summary --}}
        <div class="ytd-section">
            <div class="section-title">Year-to-Date Summary</div>
            <div class="ytd-grid">
                <div class="ytd-item">
                    <div class="ytd-label">YTD Gross</div>
                    <div class="ytd-value">${{ number_format($stub->ytd_gross, 2) }}</div>
                </div>
                <div class="ytd-item">
                    <div class="ytd-label">YTD Federal</div>
                    <div class="ytd-value">${{ number_format($stub->ytd_federal_tax, 2) }}</div>
                </div>
                <div class="ytd-item">
                    <div class="ytd-label">YTD State</div>
                    <div class="ytd-value">${{ number_format($stub->ytd_state_tax, 2) }}</div>
                </div>
                <div class="ytd-item">
                    <div class="ytd-label">YTD SS</div>
                    <div class="ytd-value">${{ number_format($stub->ytd_social_security, 2) }}</div>
                </div>
                <div class="ytd-item">
                    <div class="ytd-label">YTD Medicare</div>
                    <div class="ytd-value">${{ number_format($stub->ytd_medicare, 2) }}</div>
                </div>
            </div>
        </div>

        <div class="footer">
            This is a confidential document. {{ $company->company_name ?? config('app.name') }} - Generated {{ now()->format('M j, Y g:ia') }}
        </div>
    </div>
</body>
</html>
