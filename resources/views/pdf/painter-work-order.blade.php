<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('Work Order') }} - {{ $estimate->estimate_number }}</title>
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
            border-bottom: 2px solid #3b82f6;
            padding-bottom: 12px;
        }

        .header-title {
            font-size: 18px;
            font-weight: bold;
            color: #1e40af;
            margin-bottom: 3px;
        }

        .header-subtitle {
            font-size: 11px;
            color: #666;
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

        .info-detail {
            font-size: 9px;
            color: #666;
            margin-top: 2px;
        }

        .section {
            margin-bottom: 15px;
            page-break-inside: avoid;
        }

        .section-title {
            font-size: 11px;
            font-weight: bold;
            color: #1f2937;
            margin-bottom: 8px;
            padding: 5px 8px;
            background: #f3f4f6;
            border-left: 3px solid #3b82f6;
        }

        .area-card {
            border: 1px solid #e5e7eb;
            margin-bottom: 10px;
            page-break-inside: avoid;
        }

        .area-header {
            background: #f9fafb;
            padding: 8px;
            border-bottom: 1px solid #e5e7eb;
        }

        .area-name {
            font-size: 12px;
            font-weight: bold;
            color: #111827;
        }

        .area-meta {
            font-size: 9px;
            color: #6b7280;
            margin-top: 2px;
        }

        .area-hours {
            font-size: 11px;
            font-weight: bold;
            color: #059669;
            float: right;
        }

        .area-content {
            padding: 8px;
        }

        .warning-box {
            background: #fef3c7;
            border: 1px solid #f59e0b;
            padding: 6px 8px;
            margin-bottom: 8px;
            font-size: 9px;
        }

        .warning-box strong {
            color: #92400e;
        }

        .note-box {
            background: #eff6ff;
            border: 1px solid #3b82f6;
            padding: 6px 8px;
            margin-bottom: 8px;
            font-size: 9px;
            color: #1e40af;
        }

        .surface-item {
            display: table;
            width: 100%;
            padding: 5px 0;
            border-bottom: 1px dotted #e5e7eb;
        }

        .surface-checkbox {
            display: table-cell;
            width: 18px;
            vertical-align: top;
            padding-top: 2px;
        }

        .checkbox {
            width: 12px;
            height: 12px;
            border: 1px solid #000;
        }

        .surface-details {
            display: table-cell;
            padding-left: 6px;
            vertical-align: top;
        }

        .surface-name {
            font-weight: bold;
            font-size: 10px;
        }

        .surface-specs {
            font-size: 9px;
            color: #6b7280;
            margin-top: 2px;
        }

        .surface-paint {
            display: inline-block;
            background: #e0e7ff;
            color: #3730a3;
            padding: 1px 5px;
            border-radius: 2px;
            font-size: 8px;
            margin-top: 3px;
        }

        .surface-prep {
            color: #b45309;
            font-size: 8px;
            margin-top: 2px;
        }

        .surface-hours {
            display: table-cell;
            width: 50px;
            text-align: right;
            font-weight: bold;
            font-size: 10px;
            vertical-align: top;
        }

        .addons-section {
            border-top: 1px dashed #e5e7eb;
            padding-top: 6px;
            margin-top: 6px;
        }

        .addons-title {
            font-size: 9px;
            font-weight: bold;
            color: #6b7280;
            margin-bottom: 4px;
        }

        .totals-box {
            background: #1f2937;
            color: white;
            padding: 12px;
            margin-top: 15px;
        }

        .totals-grid {
            display: table;
            width: 100%;
        }

        .totals-cell {
            display: table-cell;
            text-align: center;
            padding: 5px;
        }

        .totals-label {
            font-size: 9px;
            color: #9ca3af;
            text-transform: uppercase;
        }

        .totals-value {
            font-size: 16px;
            font-weight: bold;
            margin-top: 3px;
        }

        .totals-highlight {
            color: #34d399;
        }

        .materials-list {
            margin-top: 15px;
            border: 1px solid #e5e7eb;
            padding: 10px;
        }

        .materials-title {
            font-size: 11px;
            font-weight: bold;
            margin-bottom: 8px;
        }

        .material-item {
            display: table;
            width: 100%;
            padding: 4px 0;
            border-bottom: 1px dotted #e5e7eb;
        }

        .material-checkbox {
            display: table-cell;
            width: 18px;
        }

        .material-name {
            display: table-cell;
            font-size: 10px;
        }

        .material-qty {
            display: table-cell;
            width: 80px;
            text-align: right;
            font-size: 10px;
            color: #6b7280;
        }

        .special-instructions {
            background: #fefce8;
            border: 1px solid #facc15;
            padding: 10px;
            margin-top: 15px;
        }

        .special-title {
            font-size: 11px;
            font-weight: bold;
            color: #854d0e;
            margin-bottom: 5px;
        }

        .special-text {
            font-size: 10px;
            color: #713f12;
            white-space: pre-wrap;
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
            <div class="header-title">{{ __("PAINTER'S WORK ORDER") }}</div>
            <div class="header-subtitle">{{ $projectInfo['estimate_number'] }} | {{ $projectInfo['project_type'] }} {{ __('Painting') }} | {{ __('Generated:') }} {{ now()->format('M j, Y g:i A') }}</div>
        </div>

        <div class="info-grid">
            <div class="info-cell">
                <div class="info-label">{{ __('Customer') }}</div>
                <div class="info-value">{{ $customerInfo['name'] }}</div>
                <div class="info-detail">{{ $customerInfo['address'] }}</div>
                @if ($customerInfo['property_type'])
                    <div class="info-detail">{{ $customerInfo['property_type'] }}</div>
                @endif
            </div>
            <div class="info-cell">
                <div class="info-label">{{ __('Project') }}</div>
                <div class="info-value">{{ $projectInfo['project_type'] }} {{ __('Painting') }}</div>
                @if ($projectInfo['title'])
                    <div class="info-detail">{{ $projectInfo['title'] }}</div>
                @endif
            </div>
        </div>

        <div class="section">
            <div class="section-title">{{ __('AREAS TO PAINT') }}</div>

            @foreach ($areaBreakdowns as $area)
                <div class="area-card">
                    <div class="area-header">
                        <span class="area-hours">{{ number_format($area['total_hours'], 1) }} hrs</span>
                        <div class="area-name">{{ $area['name'] }}</div>
                        <div class="area-meta">
                            {{ $area['scope_type'] }}
                            @if ($area['dimensions'])
                                &bull; {{ $area['dimensions'] }}
                            @endif
                            @if ($area['total_sqft'] > 0)
                                &bull; {{ number_format($area['total_sqft']) }} sqft
                            @endif
                        </div>
                    </div>
                    <div class="area-content">
                        @if ($area['substrate'])
                            <div class="warning-box">
                                <strong>{{ __('Surface Condition:') }}</strong> {{ $area['substrate'] }}
                                @if ($area['substrate_prep'] && $area['substrate_prep'] !== 'None')
                                    | {{ __('Prep:') }} {{ $area['substrate_prep'] }}
                                @endif
                            </div>
                        @endif

                        @if ($area['notes'])
                            <div class="note-box">
                                <strong>{{ __('Note:') }}</strong> {{ $area['notes'] }}
                            </div>
                        @endif

                        @foreach ($area['surfaces'] as $surface)
                            <div class="surface-item">
                                <div class="surface-checkbox"><div class="checkbox"></div></div>
                                <div class="surface-details">
                                    <div class="surface-name">{{ $surface['name'] }} - {{ number_format($surface['quantity']) }} {{ $surface['unit'] }}</div>
                                    <div class="surface-specs">{{ $surface['coats'] }} {{ $surface['coats'] === 1 ? __('coat') : __('coats') }} &bull; {{ $surface['application_method'] }}</div>
                                    @if ($surface['paint_product'])
                                        <div class="surface-paint">{{ $surface['paint_product']['full_name'] }}@if ($surface['paint_product']['sheen']) - {{ $surface['paint_product']['sheen'] }}@endif</div>
                                    @elseif ($surface['paint_brand'] && $surface['paint_type'])
                                        <div class="surface-paint">{{ $surface['paint_brand'] }} {{ $surface['paint_type'] }}</div>
                                    @endif
                                    @if (count($surface['prep_notes']) > 0)
                                        <div class="surface-prep">{{ __('Prep:') }} {{ implode(', ', $surface['prep_notes']) }}</div>
                                    @endif
                                </div>
                                <div class="surface-hours">{{ number_format($surface['labor_hours'], 1) }} hrs</div>
                            </div>
                        @endforeach

                        @if (count($area['addons']) > 0)
                            <div class="addons-section">
                                <div class="addons-title">{{ __('Included Addons') }}</div>
                                @foreach ($area['addons'] as $addon)
                                    <div class="surface-item">
                                        <div class="surface-checkbox"><div class="checkbox"></div></div>
                                        <div class="surface-details">
                                            <div class="surface-name">{{ $addon['name'] }}</div>
                                        </div>
                                        @if ($addon['labor_hours'] > 0)
                                            <div class="surface-hours">{{ number_format($addon['labor_hours'], 1) }} hrs</div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <div class="totals-box">
            <div class="totals-grid">
                <div class="totals-cell">
                    <div class="totals-label">{{ __('Labor Hours') }}</div>
                    <div class="totals-value">{{ number_format($jobTotals['labor_hours'], 1) }}</div>
                </div>
                <div class="totals-cell">
                    <div class="totals-label">{{ __('Cleanup Hours') }}</div>
                    <div class="totals-value">{{ number_format($jobTotals['cleanup_hours'], 1) }}</div>
                </div>
                <div class="totals-cell">
                    <div class="totals-label">{{ __('Total Hours') }}</div>
                    <div class="totals-value totals-highlight">{{ number_format($jobTotals['total_hours'], 1) }}</div>
                </div>
                <div class="totals-cell">
                    <div class="totals-label">{{ __('Areas') }}</div>
                    <div class="totals-value">{{ $jobTotals['area_count'] }}</div>
                </div>
            </div>
        </div>

        @if (count($materialsNeeded) > 0)
            <div class="materials-list">
                <div class="materials-title">{{ __('MATERIALS NEEDED') }}</div>
                @foreach ($materialsNeeded as $material)
                    <div class="material-item">
                        <div class="material-checkbox"><div class="checkbox"></div></div>
                        <div class="material-name">{{ $material['brand'] }} {{ $material['name'] }}@if ($material['sheen']) - {{ $material['sheen'] }}@endif</div>
                        <div class="material-qty">{{ ceil($material['gallons']) }} {{ ceil($material['gallons']) === 1 ? __('gallon') : __('gallons') }}</div>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($projectInfo['notes'])
            <div class="special-instructions">
                <div class="special-title">{{ __('SPECIAL INSTRUCTIONS') }}</div>
                <div class="special-text">{{ $projectInfo['notes'] }}</div>
            </div>
        @endif

        <div class="footer">
            {{ __('Work Order:') }} {{ $projectInfo['estimate_number'] }} | {{ __('Generated by') }} {{ config('app.name') }} | {{ now()->format('M j, Y g:i A') }}
        </div>
    </div>
</body>
</html>
