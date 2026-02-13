<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('Invoice') }} {{ $invoice->invoice_number }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 12px;
            line-height: 1.5;
            color: #333;
        }

        .container {
            padding: 40px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 40px;
            border-bottom: 2px solid #3b82f6;
            padding-bottom: 20px;
        }

        .company-info {
            text-align: left;
        }

        .company-name {
            font-size: 24px;
            font-weight: bold;
            color: #1e40af;
        }

        .invoice-info {
            text-align: right;
        }

        .invoice-title {
            font-size: 28px;
            font-weight: bold;
            color: #1e40af;
            text-transform: uppercase;
        }

        .invoice-number {
            font-size: 14px;
            color: #666;
            margin-top: 5px;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            margin-top: 5px;
        }

        .status-draft { background: #f3f4f6; color: #6b7280; }
        .status-sent { background: #dbeafe; color: #2563eb; }
        .status-partially_paid { background: #fef3c7; color: #d97706; }
        .status-paid { background: #d1fae5; color: #059669; }
        .status-void { background: #fee2e2; color: #dc2626; }
        .status-overdue { background: #fee2e2; color: #dc2626; }

        .details {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
        }

        .details-section {
            width: 48%;
        }

        .details-section h3 {
            font-size: 11px;
            text-transform: uppercase;
            color: #6b7280;
            margin-bottom: 5px;
            letter-spacing: 0.5px;
        }

        .details-section p {
            margin: 2px 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        table th {
            background: #f8fafc;
            color: #6b7280;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 10px;
            text-align: left;
            border-bottom: 2px solid #e5e7eb;
        }

        table td {
            padding: 10px;
            border-bottom: 1px solid #f3f4f6;
        }

        .text-right { text-align: right; }

        .totals {
            width: 300px;
            margin-left: auto;
        }

        .totals-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
        }

        .totals-row.total {
            border-top: 2px solid #333;
            font-size: 16px;
            font-weight: bold;
            padding-top: 10px;
            margin-top: 5px;
        }

        .totals-row.balance-due {
            border-top: 2px solid #3b82f6;
            font-size: 18px;
            font-weight: bold;
            color: #1e40af;
            padding-top: 10px;
            margin-top: 5px;
        }

        .notes {
            margin-top: 30px;
            padding: 15px;
            background: #f8fafc;
            border-radius: 8px;
        }

        .notes h3 {
            font-size: 11px;
            text-transform: uppercase;
            color: #6b7280;
            margin-bottom: 5px;
        }

        .payments-section {
            margin-top: 20px;
        }

        .payments-section h3 {
            font-size: 13px;
            font-weight: bold;
            margin-bottom: 10px;
            color: #374151;
        }

        .footer {
            margin-top: 40px;
            padding-top: 15px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            color: #9ca3af;
            font-size: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        {{-- Header --}}
        <div class="header">
            <div class="company-info">
                <div class="company-name">{{ $invoice->companySetting->company_name ?? config('app.name') }}</div>
                @if ($invoice->companySetting->address)
                    <p>{{ $invoice->companySetting->address }}</p>
                @endif
                @if ($invoice->companySetting->phone)
                    <p>{{ $invoice->companySetting->phone }}</p>
                @endif
                @if ($invoice->companySetting->email)
                    <p>{{ $invoice->companySetting->email }}</p>
                @endif
            </div>
            <div class="invoice-info">
                <div class="invoice-title">{{ __('Invoice') }}</div>
                <div class="invoice-number">{{ $invoice->invoice_number }}</div>
                <div class="status-badge status-{{ $invoice->status }}">
                    {{ \App\Models\Invoice::STATUSES[$invoice->status] ?? ucfirst($invoice->status) }}
                </div>
            </div>
        </div>

        {{-- Details --}}
        <div class="details">
            <div class="details-section">
                <h3>{{ __('Bill To') }}</h3>
                @if ($invoice->customer)
                    <p><strong>{{ $invoice->customer->full_name }}</strong></p>
                    @if ($invoice->customer->email)
                        <p>{{ $invoice->customer->email }}</p>
                    @endif
                @endif
            </div>
            <div class="details-section" style="text-align: right;">
                <h3>{{ __('Invoice Details') }}</h3>
                <p><strong>{{ __('Type:') }}</strong> {{ \App\Models\Invoice::TYPES[$invoice->type] ?? ucfirst($invoice->type) }}</p>
                <p><strong>{{ __('Date:') }}</strong> {{ $invoice->created_at->format('M j, Y') }}</p>
                @if ($invoice->due_date)
                    <p><strong>{{ __('Due Date:') }}</strong> {{ $invoice->due_date->format('M j, Y') }}</p>
                @endif
                @if ($invoice->estimate)
                    <p><strong>{{ __('Estimate:') }}</strong> {{ $invoice->estimate->estimate_number }}</p>
                @endif
            </div>
        </div>

        {{-- Line Items --}}
        <table>
            <thead>
                <tr>
                    <th>{{ __('Description') }}</th>
                    <th class="text-right">{{ __('Amount') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        {{ \App\Models\Invoice::TYPES[$invoice->type] ?? __('Invoice') }}
                        @if ($invoice->estimate)
                            - {{ __('Estimate') }} #{{ $invoice->estimate->estimate_number }}
                        @endif
                        @if ($invoice->notes)
                            <br><small style="color: #6b7280;">{{ $invoice->notes }}</small>
                        @endif
                    </td>
                    <td class="text-right">${{ number_format((float) $invoice->subtotal, 2) }}</td>
                </tr>
            </tbody>
        </table>

        {{-- Totals --}}
        <div class="totals">
            <div class="totals-row">
                <span>{{ __('Subtotal') }}</span>
                <span>${{ number_format((float) $invoice->subtotal, 2) }}</span>
            </div>
            @if ((float) $invoice->tax_rate > 0)
                <div class="totals-row">
                    <span>{{ __('Tax') }} ({{ $invoice->tax_rate }}%)</span>
                    <span>${{ number_format((float) $invoice->tax_amount, 2) }}</span>
                </div>
            @endif
            <div class="totals-row total">
                <span>{{ __('Total') }}</span>
                <span>${{ number_format((float) $invoice->total, 2) }}</span>
            </div>
            @if ((float) $invoice->amount_paid > 0)
                <div class="totals-row">
                    <span>{{ __('Amount Paid') }}</span>
                    <span style="color: #059669;">-${{ number_format((float) $invoice->amount_paid, 2) }}</span>
                </div>
            @endif
            <div class="totals-row balance-due">
                <span>{{ __('Balance Due') }}</span>
                <span>${{ number_format((float) $invoice->balance_due, 2) }}</span>
            </div>
        </div>

        {{-- Payments History --}}
        @if ($invoice->payments->isNotEmpty())
            <div class="payments-section">
                <h3>{{ __('Payment History') }}</h3>
                <table>
                    <thead>
                        <tr>
                            <th>{{ __('Date') }}</th>
                            <th>{{ __('Method') }}</th>
                            <th>{{ __('Reference') }}</th>
                            <th class="text-right">{{ __('Amount') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($invoice->payments->where('status', 'completed') as $payment)
                            <tr>
                                <td>{{ $payment->received_at->format('M j, Y') }}</td>
                                <td>{{ \App\Models\Payment::METHODS[$payment->method] ?? ucfirst($payment->method) }}</td>
                                <td>{{ $payment->reference_number ?? '-' }}</td>
                                <td class="text-right">${{ number_format((float) $payment->amount, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Terms --}}
        @if ($invoice->terms)
            <div class="notes">
                <h3>{{ __('Terms & Conditions') }}</h3>
                <p>{{ $invoice->terms }}</p>
            </div>
        @endif

        {{-- Footer --}}
        <div class="footer">
            <p>{{ $invoice->companySetting->company_name ?? config('app.name') }} &bull; {{ $invoice->invoice_number }}</p>
        </div>
    </div>
</body>
</html>
