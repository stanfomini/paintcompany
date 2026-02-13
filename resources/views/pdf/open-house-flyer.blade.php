<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('Open House Flyer') }} - {{ $listing->address }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        @page {
            size: letter;
            margin: 0;
        }

        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 14px;
            line-height: 1.5;
            color: #333;
            width: 8.5in;
            height: 11in;
        }

        .container {
            padding: 40px 50px;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 3px solid #1e40af;
            padding-bottom: 20px;
        }

        .company-name {
            font-size: 36px;
            font-weight: bold;
            color: #1e40af;
            margin-bottom: 5px;
            letter-spacing: 1px;
        }

        .company-tagline {
            font-size: 14px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 2px;
        }

        .open-house-badge {
            background-color: #1e40af;
            color: white;
            font-size: 28px;
            font-weight: bold;
            padding: 15px 40px;
            text-align: center;
            margin: 20px auto;
            text-transform: uppercase;
            letter-spacing: 3px;
        }

        .property-section {
            text-align: center;
            margin: 25px 0;
        }

        .property-address {
            font-size: 32px;
            font-weight: bold;
            color: #111827;
            margin-bottom: 10px;
            line-height: 1.2;
        }

        .property-location {
            font-size: 22px;
            color: #374151;
        }

        .open-house-date {
            background-color: #f3f4f6;
            border: 2px solid #e5e7eb;
            padding: 20px;
            margin: 25px 0;
            text-align: center;
        }

        .date-label {
            font-size: 16px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }

        .date-value {
            font-size: 24px;
            font-weight: bold;
            color: #1e40af;
        }

        .services-section {
            background-color: #eff6ff;
            border-left: 4px solid #1e40af;
            padding: 20px 25px;
            margin: 25px 0;
        }

        .services-title {
            font-size: 18px;
            font-weight: bold;
            color: #1e40af;
            margin-bottom: 10px;
        }

        .services-text {
            font-size: 16px;
            color: #374151;
            line-height: 1.6;
        }

        .qr-section {
            text-align: center;
            margin: 30px 0;
            padding: 25px;
            border: 2px dashed #d1d5db;
            background-color: #fafafa;
        }

        .qr-code {
            margin: 0 auto 15px auto;
            width: 200px;
            height: 200px;
        }

        .qr-code svg {
            width: 200px;
            height: 200px;
        }

        .scan-text {
            font-size: 20px;
            font-weight: bold;
            color: #1e40af;
            margin-bottom: 8px;
        }

        .scan-subtext {
            font-size: 14px;
            color: #6b7280;
        }

        .url-text {
            font-size: 14px;
            color: #374151;
            margin-top: 15px;
            padding: 10px 20px;
            background-color: #e5e7eb;
            display: inline-block;
            font-family: monospace;
        }

        .agent-section {
            margin-top: auto;
            padding-top: 25px;
            border-top: 2px solid #e5e7eb;
        }

        .agent-title {
            font-size: 14px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 10px;
        }

        .agent-info {
            display: table;
            width: 100%;
        }

        .agent-details {
            display: table-cell;
            vertical-align: middle;
        }

        .agent-name {
            font-size: 20px;
            font-weight: bold;
            color: #111827;
            margin-bottom: 5px;
        }

        .agent-company {
            font-size: 14px;
            color: #6b7280;
            margin-bottom: 5px;
        }

        .agent-contact {
            font-size: 14px;
            color: #374151;
        }

        .agent-contact span {
            margin-right: 20px;
        }

        .footer {
            margin-top: 20px;
            text-align: center;
            color: #9ca3af;
            font-size: 11px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="company-name">{{ __('Apex Negotiations') }}</div>
            <div class="company-tagline">{{ __('Property Tax Savings Specialists') }}</div>
        </div>

        <div class="open-house-badge">{{ __('Open House') }}</div>

        <div class="property-section">
            <div class="property-address">{{ $listing->address }}</div>
            <div class="property-location">{{ $listing->city }}, {{ $listing->state }} {{ $listing->zip }}</div>
        </div>

        @if ($listing->open_house_date)
            <div class="open-house-date">
                <div class="date-label">{{ __('Date & Time') }}</div>
                <div class="date-value">{{ $listing->open_house_date->format('l, F j, Y \a\t g:i A') }}</div>
            </div>
        @endif

        <div class="services-section">
            <div class="services-title">{{ __('Free Property Tax Savings Consultation') }}</div>
            <div class="services-text">
                {{ __('Discover how you can potentially reduce your property taxes. Our experts specialize in property tax appeals and negotiations. Sign up today for a free consultation and find out if you\'re overpaying on your property taxes!') }}
            </div>
        </div>

        <div class="qr-section">
            <div class="qr-code">
                {!! $qrCodeSvg !!}
            </div>
            <div class="scan-text">{{ __('Scan to Sign Up') }}</div>
            <div class="scan-subtext">{{ __('for your free property tax savings consultation') }}</div>
            <div class="url-text">{{ str_replace(['http://', 'https://'], '', $signupUrl) }}</div>
        </div>

        <div class="agent-section">
            <div class="agent-title">{{ __('Your Agent') }}</div>
            <div class="agent-info">
                <div class="agent-details">
                    <div class="agent-name">{{ $agent->name }}</div>
                    @if ($agent->agentProfile?->company_name)
                        <div class="agent-company">{{ $agent->agentProfile->company_name }}</div>
                    @endif
                    <div class="agent-contact">
                        @if ($agent->agentProfile?->phone)
                            <span>{{ $agent->agentProfile->phone }}</span>
                        @endif
                        <span>{{ $agent->email }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="footer">
            <p>{{ __('Apex Negotiations - Helping homeowners save on property taxes') }}</p>
        </div>
    </div>
</body>
</html>
