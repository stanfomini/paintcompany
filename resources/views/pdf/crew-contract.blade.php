<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $contract->type_label }} - {{ $contract->user->name }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 12px; line-height: 1.6; color: #333; }
        .container { padding: 40px; }

        .header { border-bottom: 2px solid #1e40af; padding-bottom: 15px; margin-bottom: 30px; }
        .company-name { font-size: 20px; font-weight: bold; color: #1e40af; }
        .company-info { font-size: 10px; color: #666; margin-top: 4px; }

        .contract-title { font-size: 18px; font-weight: bold; text-align: center; margin-bottom: 5px; color: #1e40af; }
        .contract-meta { font-size: 10px; text-align: center; color: #666; margin-bottom: 25px; }

        .contract-body { font-size: 11px; line-height: 1.7; white-space: pre-wrap; margin-bottom: 30px; padding: 20px; background: #fafafa; border: 1px solid #e5e7eb; border-radius: 4px; }

        .sig-block { border-top: 2px solid #000; padding-top: 20px; margin-top: 30px; }
        .sig-title { font-size: 14px; font-weight: bold; margin-bottom: 15px; }
        .sig-table { width: 100%; border-collapse: collapse; }
        .sig-table td { padding: 8px 0; vertical-align: bottom; }
        .sig-label { font-size: 9px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; }
        .sig-value { font-size: 12px; border-bottom: 1px solid #ccc; min-height: 20px; padding-bottom: 3px; }
        .sig-typed { font-family: 'Brush Script MT', 'Segoe Script', cursive; font-size: 22px; color: #000; }

        .meta-grid { display: table; width: 100%; margin-top: 15px; }
        .meta-row { display: table-row; }
        .meta-label { display: table-cell; width: 120px; font-size: 9px; color: #666; padding: 3px 0; text-transform: uppercase; }
        .meta-value { display: table-cell; font-size: 10px; padding: 3px 0; }

        .page-footer { margin-top: 30px; font-size: 8px; color: #999; text-align: center; border-top: 1px solid #e5e7eb; padding-top: 10px; }
    </style>
</head>
<body>
<div class="container">
    {{-- Header --}}
    <div class="header">
        <div class="company-name">{{ $settings->company_name }}</div>
        @if ($settings->company_address || $settings->company_phone)
            <div class="company-info">
                {{ $settings->company_address }}
                @if ($settings->company_phone) &nbsp;|&nbsp; {{ $settings->company_phone }} @endif
                @if ($settings->company_email) &nbsp;|&nbsp; {{ $settings->company_email }} @endif
            </div>
        @endif
    </div>

    {{-- Contract Title --}}
    <div class="contract-title">{{ $contract->type_label }}</div>
    <div class="contract-meta">
        Signed by {{ $contract->signer_name }} on {{ $contract->signed_at->format('F j, Y \a\t g:ia') }}
    </div>

    {{-- Contract Content --}}
    <div class="contract-body">{{ $contract->contract_content }}</div>

    {{-- Signature Block --}}
    <div class="sig-block">
        <div class="sig-title">Signature</div>
        <table class="sig-table">
            <tr>
                <td style="width: 60%;">
                    <div class="sig-label">Contractor Signature</div>
                    <div class="sig-value">
                        @if ($contract->signature_type === 'drawn' && str_starts_with($contract->signature_data, 'data:image'))
                            <img src="{{ $contract->signature_data }}" style="height: 35px; max-width: 250px;" />
                        @else
                            <span class="sig-typed">{{ $contract->signature_data }}</span>
                        @endif
                    </div>
                </td>
                <td style="width: 40%; padding-left: 20px;">
                    <div class="sig-label">Date</div>
                    <div class="sig-value">{{ $contract->signed_at->format('m/d/Y') }}</div>
                </td>
            </tr>
        </table>

        <div class="meta-grid">
            <div class="meta-row">
                <span class="meta-label">Full Name:</span>
                <span class="meta-value">{{ $contract->signer_name }}</span>
            </div>
            <div class="meta-row">
                <span class="meta-label">Email:</span>
                <span class="meta-value">{{ $contract->signer_email }}</span>
            </div>
            <div class="meta-row">
                <span class="meta-label">IP Address:</span>
                <span class="meta-value">{{ $contract->ip_address }}</span>
            </div>
            <div class="meta-row">
                <span class="meta-label">User Agent:</span>
                <span class="meta-value" style="font-size: 8px; word-break: break-all;">{{ $contract->user_agent }}</span>
            </div>
        </div>
    </div>

    <div class="page-footer">
        Generated {{ now()->format('M j, Y \a\t g:ia') }} &mdash; {{ $settings->company_name }} &mdash; This document was signed electronically.
    </div>
</div>
</body>
</html>
