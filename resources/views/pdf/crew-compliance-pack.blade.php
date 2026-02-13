<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Compliance Pack - {{ $user->name }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 11px; line-height: 1.5; color: #333; }
        .container { padding: 40px; }
        .page-break { page-break-after: always; }

        .header { border-bottom: 2px solid #1e40af; padding-bottom: 15px; margin-bottom: 25px; }
        .company-name { font-size: 20px; font-weight: bold; color: #1e40af; }
        .company-info { font-size: 10px; color: #666; margin-top: 4px; }

        .cover-title { font-size: 28px; font-weight: bold; text-align: center; margin: 60px 0 10px; color: #1e40af; }
        .cover-subtitle { font-size: 16px; text-align: center; color: #666; margin-bottom: 40px; }
        .cover-date { font-size: 12px; text-align: center; color: #888; }

        .section-title { font-size: 16px; font-weight: bold; color: #1e40af; border-bottom: 1px solid #1e40af; padding-bottom: 5px; margin-bottom: 15px; }
        .subsection { margin-bottom: 20px; }

        .info-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        .info-table th { text-align: left; font-size: 10px; color: #666; padding: 4px 8px; background: #f3f4f6; border: 1px solid #e5e7eb; text-transform: uppercase; }
        .info-table td { padding: 6px 8px; border: 1px solid #e5e7eb; font-size: 11px; }

        .status-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 9px; font-weight: 600; }
        .status-complete { background: #dcfce7; color: #166534; }
        .status-missing { background: #fef2f2; color: #991b1b; }

        .contract-content { font-size: 9px; line-height: 1.5; white-space: pre-wrap; padding: 15px; background: #fafafa; border: 1px solid #e5e7eb; border-radius: 4px; margin-bottom: 15px; max-height: none; }

        .sig-line { margin-top: 10px; font-size: 10px; }
        .sig-typed { font-family: 'Brush Script MT', 'Segoe Script', cursive; font-size: 18px; }

        .page-footer { margin-top: 20px; font-size: 8px; color: #999; text-align: center; }

        .checklist { list-style: none; padding: 0; }
        .checklist li { padding: 6px 0; border-bottom: 1px solid #f3f4f6; font-size: 11px; }
        .checklist li:last-child { border-bottom: none; }
        .check-icon { display: inline-block; width: 14px; height: 14px; border-radius: 50%; text-align: center; line-height: 14px; font-size: 9px; font-weight: bold; margin-right: 6px; }
        .check-yes { background: #dcfce7; color: #166534; }
        .check-no { background: #fef2f2; color: #991b1b; }
    </style>
</head>
<body>
@php
    $profile = $user->crewProfile;
    $contracts = $user->crewSignedContracts;
    $w9 = $user->crewW9Form;
@endphp

{{-- Cover Page --}}
<div class="container">
    <div class="header">
        <div class="company-name">{{ $settings->company_name }}</div>
        @if ($settings->company_address || $settings->company_phone)
            <div class="company-info">{{ $settings->company_address }} @if ($settings->company_phone) | {{ $settings->company_phone }} @endif</div>
        @endif
    </div>

    <div class="cover-title">Crew Compliance Pack</div>
    <div class="cover-subtitle">{{ $user->name }}</div>
    <div class="cover-date">Generated {{ now()->format('F j, Y') }}</div>

    <div style="margin-top: 40px;">
        <div class="section-title">Compliance Summary</div>
        <ul class="checklist">
            <li>
                <span class="check-icon {{ $contracts->count() >= 3 ? 'check-yes' : 'check-no' }}">{{ $contracts->count() >= 3 ? '&#10003;' : '&#10007;' }}</span>
                Contracts: {{ $contracts->count() }}/3 signed
            </li>
            <li>
                <span class="check-icon {{ $w9 ? 'check-yes' : 'check-no' }}">{{ $w9 ? '&#10003;' : '&#10007;' }}</span>
                W-9 Form: {{ $w9 ? 'On file (signed ' . $w9->signed_at?->format('M j, Y') . ')' : 'Not submitted' }}
            </li>
            <li>
                <span class="check-icon {{ $profile?->insurance_photo ? 'check-yes' : 'check-no' }}">{{ $profile?->insurance_photo ? '&#10003;' : '&#10007;' }}</span>
                Insurance: {{ $profile?->insurance_company ? $profile->insurance_company . ' (Policy: ' . $profile->policy_number . ')' : 'Not on file' }}
                @if ($profile?->insurance_expiration_date)
                    &mdash; Expires {{ $profile->insurance_expiration_date->format('M j, Y') }}
                @endif
            </li>
            <li>
                <span class="check-icon {{ $profile?->workers_comp_document ? 'check-yes' : 'check-no' }}">{{ $profile?->workers_comp_document ? '&#10003;' : '&#10007;' }}</span>
                Workers' Comp: {{ $profile?->workers_comp_carrier ? $profile->workers_comp_carrier . ' (Policy: ' . $profile->workers_comp_policy_number . ')' : 'Not on file' }}
                @if ($profile?->workers_comp_expiration_date)
                    &mdash; Expires {{ $profile->workers_comp_expiration_date->format('M j, Y') }}
                @endif
            </li>
            <li>
                <span class="check-icon {{ $profile?->drivers_license_front ? 'check-yes' : 'check-no' }}">{{ $profile?->drivers_license_front ? '&#10003;' : '&#10007;' }}</span>
                Driver's License: {{ $profile?->drivers_license_front ? 'On file' : 'Not uploaded' }}
            </li>
        </ul>
    </div>
</div>

{{-- Contracts --}}
@foreach ($contracts as $contract)
<div class="page-break"></div>
<div class="container">
    <div class="header">
        <div class="company-name">{{ $settings->company_name }}</div>
    </div>

    <div class="section-title">{{ $contract->type_label }}</div>

    <div class="contract-content">{{ $contract->contract_content }}</div>

    <div class="sig-line">
        <strong>Signed by:</strong> {{ $contract->signer_name }} ({{ $contract->signer_email }})<br>
        <strong>Date:</strong> {{ $contract->signed_at->format('F j, Y \a\t g:ia') }}<br>
        <strong>IP Address:</strong> {{ $contract->ip_address }}<br>
        <strong>Signature:</strong>
        @if ($contract->signature_type === 'drawn' && str_starts_with($contract->signature_data, 'data:image'))
            <img src="{{ $contract->signature_data }}" style="height: 30px; max-width: 200px;" />
        @else
            <span class="sig-typed">{{ $contract->signature_data }}</span>
        @endif
    </div>
</div>
@endforeach

{{-- W-9 --}}
@if ($w9)
<div class="page-break"></div>
<div class="container">
    <div class="header">
        <div class="company-name">{{ $settings->company_name }}</div>
    </div>

    <div class="section-title">W-9 Form</div>

    <table class="info-table">
        <tr><th>Name</th><td>{{ $w9->name }}</td></tr>
        @if ($w9->business_name)<tr><th>Business Name</th><td>{{ $w9->business_name }}</td></tr>@endif
        <tr><th>Tax Classification</th><td>{{ $w9->tax_classification_label }}</td></tr>
        @php
            $tinDigits = preg_replace('/\D/', '', $w9->tin ?? '');
            $formattedTin = $w9->tin_type === 'ssn'
                ? substr($tinDigits, 0, 3) . '-' . substr($tinDigits, 3, 2) . '-' . substr($tinDigits, 5, 4)
                : substr($tinDigits, 0, 2) . '-' . substr($tinDigits, 2, 7);
        @endphp
        <tr><th>TIN ({{ strtoupper($w9->tin_type) }})</th><td>{{ $formattedTin }}</td></tr>
        <tr><th>Address</th><td>{{ $w9->address }}</td></tr>
        <tr><th>City/State/ZIP</th><td>{{ $w9->city_state_zip }}</td></tr>
        <tr><th>Signed</th><td>{{ $w9->signed_at?->format('F j, Y \a\t g:ia') }}</td></tr>
        <tr><th>IP Address</th><td>{{ $w9->ip_address }}</td></tr>
    </table>

    <div class="sig-line">
        <strong>Signature:</strong>
        @if ($w9->signature_type === 'drawn' && str_starts_with($w9->signature_data, 'data:image'))
            <img src="{{ $w9->signature_data }}" style="height: 30px; max-width: 200px;" />
        @else
            <span class="sig-typed">{{ $w9->signature_data }}</span>
        @endif
    </div>
</div>
@endif

{{-- Insurance & Workers' Comp --}}
<div class="page-break"></div>
<div class="container">
    <div class="header">
        <div class="company-name">{{ $settings->company_name }}</div>
    </div>

    <div class="section-title">Insurance & Workers' Compensation</div>

    <div class="subsection">
        <h3 style="font-size: 13px; margin-bottom: 8px;">Insurance Certificate</h3>
        <table class="info-table">
            <tr><th>Company</th><td>{{ $profile?->insurance_company ?? 'Not provided' }}</td></tr>
            <tr><th>Policy Number</th><td>{{ $profile?->policy_number ?? 'Not provided' }}</td></tr>
            <tr>
                <th>Coverage Period</th>
                <td>
                    @if ($profile?->insurance_start_date && $profile?->insurance_expiration_date)
                        {{ $profile->insurance_start_date->format('M j, Y') }} &ndash; {{ $profile->insurance_expiration_date->format('M j, Y') }}
                    @else
                        Not provided
                    @endif
                </td>
            </tr>
            <tr><th>Document</th><td>{{ $profile?->insurance_photo ? 'On file' : 'Not uploaded' }}</td></tr>
        </table>
    </div>

    <div class="subsection">
        <h3 style="font-size: 13px; margin-bottom: 8px;">Workers' Compensation</h3>
        <table class="info-table">
            <tr><th>Carrier</th><td>{{ $profile?->workers_comp_carrier ?? 'Not provided' }}</td></tr>
            <tr><th>Policy Number</th><td>{{ $profile?->workers_comp_policy_number ?? 'Not provided' }}</td></tr>
            <tr>
                <th>Coverage Period</th>
                <td>
                    @if ($profile?->workers_comp_start_date && $profile?->workers_comp_expiration_date)
                        {{ $profile->workers_comp_start_date->format('M j, Y') }} &ndash; {{ $profile->workers_comp_expiration_date->format('M j, Y') }}
                    @else
                        Not provided
                    @endif
                </td>
            </tr>
            <tr><th>Document</th><td>{{ $profile?->workers_comp_document ? 'On file' : 'Not uploaded' }}</td></tr>
        </table>
    </div>

    <div class="subsection">
        <h3 style="font-size: 13px; margin-bottom: 8px;">Driver's License</h3>
        <table class="info-table">
            <tr><th>Front</th><td>{{ $profile?->drivers_license_front ? 'On file' : 'Not uploaded' }}</td></tr>
            <tr><th>Back</th><td>{{ $profile?->drivers_license_back ? 'On file' : 'Not uploaded' }}</td></tr>
        </table>
    </div>

    <div class="page-footer">
        Compliance Pack &mdash; {{ $user->name }} &mdash; Generated {{ now()->format('M j, Y \a\t g:ia') }} &mdash; {{ $settings->company_name }}
    </div>
</div>
</body>
</html>
