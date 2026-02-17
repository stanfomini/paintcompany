<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $contract->title }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 11px;
            line-height: 1.6;
            color: #333;
        }

        .container {
            padding: 40px;
        }

        .header {
            margin-bottom: 30px;
            border-bottom: 2px solid #2563eb;
            padding-bottom: 15px;
        }

        .header-title {
            font-size: 22px;
            font-weight: bold;
            color: #1e40af;
            margin-bottom: 5px;
        }

        .header-subtitle {
            font-size: 12px;
            color: #666;
        }

        .company-info {
            font-size: 10px;
            color: #666;
            margin-top: 5px;
        }

        .contract-content {
            margin: 30px 0;
            white-space: pre-line;
            font-size: 11px;
            line-height: 1.8;
        }

        .signature-section {
            margin-top: 50px;
            border-top: 1px solid #e5e7eb;
            padding-top: 30px;
        }

        .signature-block {
            display: inline-block;
            width: 45%;
            vertical-align: top;
            margin-bottom: 20px;
        }

        .signature-block:first-child {
            margin-right: 8%;
        }

        .signature-label {
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            color: #666;
            margin-bottom: 8px;
            letter-spacing: 0.5px;
        }

        .signature-value {
            font-size: 11px;
            color: #333;
            margin-bottom: 4px;
        }

        .signature-line {
            border-bottom: 1px solid #333;
            min-height: 30px;
            margin-bottom: 5px;
        }

        .signature-typed {
            font-size: 20px;
            font-style: italic;
            font-family: 'Georgia', serif;
            padding: 5px 0;
        }

        .signature-drawn img {
            max-height: 60px;
            max-width: 200px;
        }

        .signature-meta {
            font-size: 9px;
            color: #999;
            margin-top: 10px;
        }

        .footer {
            position: fixed;
            bottom: 20px;
            left: 40px;
            right: 40px;
            font-size: 9px;
            color: #999;
            border-top: 1px solid #e5e7eb;
            padding-top: 10px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        {{-- Header --}}
        <div class="header">
            <div class="header-title">{{ $contract->title }}</div>
            <div class="header-subtitle">
                {{ __('Employee') }}: {{ $contract->employeeProfile->user->name ?? '' }}
                &middot; {{ $contract->employeeProfile->employee_number ?? '' }}
            </div>
            @if ($settings ?? null)
                <div class="company-info">
                    {{ $settings->company_name ?? '' }}
                    @if ($settings->address_line_1)
                        &middot; {{ $settings->address_line_1 }}{{ $settings->city ? ', '.$settings->city : '' }}{{ $settings->state ? ', '.$settings->state : '' }} {{ $settings->zip_code ?? '' }}
                    @endif
                </div>
            @endif
        </div>

        {{-- Contract Content --}}
        <div class="contract-content">
            {!! nl2br(e($contract->content)) !!}
        </div>

        {{-- Signature Section --}}
        @if ($contract->isSigned())
            <div class="signature-section">
                {{-- Employee Signature --}}
                <div class="signature-block">
                    <div class="signature-label">{{ __('Employee Signature') }}</div>
                    <div class="signature-line">
                        @if ($contract->signature_type === 'typed')
                            <div class="signature-typed">{{ $contract->signature_data }}</div>
                        @elseif ($contract->signature_type === 'drawn' && $contract->signature_data)
                            <div class="signature-drawn">
                                <img src="{{ $contract->signature_data }}" alt="Signature" />
                            </div>
                        @endif
                    </div>
                    <div class="signature-value">{{ $contract->signer_name }}</div>
                    <div class="signature-value">{{ __('Date') }}: {{ $contract->signed_at->format('m/d/Y g:ia') }}</div>
                    <div class="signature-meta">
                        {{ __('IP') }}: {{ $contract->signer_ip }}
                    </div>
                </div>

                {{-- Company Countersignature --}}
                <div class="signature-block">
                    <div class="signature-label">{{ __('Company Representative') }}</div>
                    @if ($contract->countersigned_at)
                        <div class="signature-line">
                            <div class="signature-typed">{{ $contract->countersignedBy->name ?? '' }}</div>
                        </div>
                        <div class="signature-value">{{ $contract->countersignedBy->name ?? '' }}</div>
                        <div class="signature-value">{{ __('Date') }}: {{ $contract->countersigned_at->format('m/d/Y g:ia') }}</div>
                    @else
                        <div class="signature-line"></div>
                        <div class="signature-value" style="color: #999;">{{ __('Pending countersignature') }}</div>
                    @endif
                </div>
            </div>
        @endif
    </div>

    <div class="footer">
        {{ __('This document was electronically signed and is legally binding.') }}
        &middot; {{ __('Generated') }} {{ now()->format('m/d/Y g:ia') }}
    </div>
</body>
</html>
