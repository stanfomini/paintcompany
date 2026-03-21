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

        .header-meta {
            font-size: 10px;
            color: #999;
            margin-top: 5px;
        }

        .contract-content {
            margin: 30px 0;
            font-size: 11px;
            line-height: 1.8;
        }

        .contract-content p {
            margin-bottom: 10px;
        }

        .signature-page {
            page-break-inside: avoid;
            margin-top: 50px;
            border-top: 1px solid #e5e7eb;
            padding-top: 30px;
        }

        .signature-section-title {
            font-size: 14px;
            font-weight: bold;
            color: #1e40af;
            margin-bottom: 20px;
        }

        .signature-block {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #f3f4f6;
        }

        .signature-block:last-child {
            border-bottom: none;
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

        .signature-line {
            border-bottom: 1px solid #333;
            min-height: 30px;
            margin-bottom: 5px;
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
                {{ __('Vendor') }}: {{ $contract->vendor->name ?? '' }}
            </div>
            @if ($contract->expires_at)
                <div class="header-meta">
                    {{ __('Expires') }}: {{ $contract->expires_at->format('m/d/Y') }}
                </div>
            @endif
        </div>

        {{-- Contract Content --}}
        <div class="contract-content">
            {!! $contract->content !!}
        </div>

        {{-- Signature Page --}}
        @if ($contract->isFullyExecuted())
            <div class="signature-page">
                {{-- Internal Signers --}}
                <div class="signature-section-title">{{ __('Internal Signatures') }}</div>

                @foreach ($contract->signers as $signer)
                    <div class="signature-block">
                        <div class="signature-label">{{ $signer->user->name ?? __('Signer') }}</div>
                        <div class="signature-line">
                            @if ($signer->signature_type === 'drawn' && $signer->signature_data)
                                <div class="signature-drawn">
                                    <img src="{{ $signer->signature_data }}" alt="Signature" />
                                </div>
                            @elseif ($signer->signature_type === 'typed' && $signer->signature_data)
                                <div class="signature-typed">{{ $signer->signature_data }}</div>
                            @endif
                        </div>
                        @if ($signer->signed_at)
                            <div class="signature-value">{{ __('Date') }}: {{ $signer->signed_at->format('m/d/Y g:ia') }}</div>
                        @endif
                    </div>
                @endforeach

                {{-- Vendor Signature --}}
                @if ($contract->vendorSignature)
                    <div class="signature-section-title" style="margin-top: 30px;">{{ __('Vendor Signature') }}</div>

                    <div class="signature-block">
                        <div class="signature-label">{{ $contract->vendorSignature->signer_name }}</div>
                        <div class="signature-value" style="margin-bottom: 8px;">{{ $contract->vendorSignature->signer_email }}</div>
                        <div class="signature-line">
                            @if ($contract->vendorSignature->signature_type === 'drawn' && $contract->vendorSignature->signature_data)
                                <div class="signature-drawn">
                                    <img src="{{ $contract->vendorSignature->signature_data }}" alt="Signature" />
                                </div>
                            @elseif ($contract->vendorSignature->signature_type === 'typed' && $contract->vendorSignature->signature_data)
                                <div class="signature-typed">{{ $contract->vendorSignature->signature_data }}</div>
                            @endif
                        </div>
                        @if ($contract->vendorSignature->signed_at)
                            <div class="signature-value">{{ __('Date') }}: {{ $contract->vendorSignature->signed_at->format('m/d/Y g:ia') }}</div>
                        @endif
                    </div>
                @endif
            </div>
        @endif
    </div>

    <div class="footer">
        {{ __('This document was electronically signed and is legally binding.') }}
        @if ($contract->isFullyExecuted())
            &middot; {{ __('Executed') }} {{ now()->format('m/d/Y g:ia') }}
        @endif
    </div>
</body>
</html>
