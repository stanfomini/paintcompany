<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('Estimate') }} {{ $estimate->estimate_number }}</title>
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
            margin-bottom: 5px;
        }

        .estimate-info {
            text-align: right;
        }

        .estimate-number {
            font-size: 18px;
            font-weight: bold;
            color: #1e40af;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            margin-top: 5px;
        }

        .status-draft { background-color: #f3f4f6; color: #374151; }
        .status-sent { background-color: #dbeafe; color: #1e40af; }
        .status-accepted { background-color: #d1fae5; color: #065f46; }
        .status-rejected { background-color: #fee2e2; color: #991b1b; }
        .status-expired { background-color: #fef3c7; color: #92400e; }

        .section {
            margin-bottom: 30px;
        }

        .section-title {
            font-size: 14px;
            font-weight: bold;
            color: #374151;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 1px solid #e5e7eb;
        }

        .info-grid {
            display: table;
            width: 100%;
        }

        .info-row {
            display: table-row;
        }

        .info-label {
            display: table-cell;
            width: 120px;
            padding: 5px 0;
            color: #6b7280;
            font-weight: 500;
        }

        .info-value {
            display: table-cell;
            padding: 5px 0;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        .items-table th {
            background-color: #f3f4f6;
            padding: 10px 8px;
            text-align: left;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            color: #374151;
            border-bottom: 2px solid #e5e7eb;
        }

        .items-table th.text-right {
            text-align: right;
        }

        .items-table td {
            padding: 12px 8px;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: top;
        }

        .items-table td.text-right {
            text-align: right;
        }

        .item-description {
            font-weight: 500;
        }

        .item-details {
            font-size: 11px;
            color: #6b7280;
            margin-top: 3px;
        }

        .totals {
            margin-top: 20px;
            margin-left: auto;
            width: 250px;
        }

        .totals-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e5e7eb;
        }

        .totals-row.total {
            border-bottom: none;
            border-top: 2px solid #374151;
            font-weight: bold;
            font-size: 16px;
            padding-top: 12px;
        }

        .totals-label {
            color: #6b7280;
        }

        .totals-row.total .totals-label {
            color: #111827;
        }

        .discount {
            color: #dc2626;
        }

        .areas-section {
            margin-top: 30px;
        }

        .area-name {
            font-weight: 600;
            font-size: 13px;
            color: #374151;
            margin-bottom: 8px;
            padding: 8px;
            background-color: #f9fafb;
            border-radius: 4px;
        }

        .area-dimensions {
            font-size: 11px;
            color: #6b7280;
            font-weight: normal;
        }

        .surfaces-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
            font-size: 11px;
        }

        .surfaces-table th {
            background-color: #f9fafb;
            padding: 6px 8px;
            text-align: left;
            font-weight: 500;
            font-size: 10px;
            text-transform: uppercase;
            color: #6b7280;
            border-bottom: 1px solid #e5e7eb;
        }

        .surfaces-table td {
            padding: 6px 8px;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: top;
        }

        .options-section {
            margin-top: 30px;
        }

        .option-item {
            display: table;
            width: 100%;
            padding: 12px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            margin-bottom: 10px;
        }

        .option-item.selected {
            border-color: #3b82f6;
            background-color: #eff6ff;
        }

        .option-item.not-selected {
            opacity: 0.6;
        }

        .option-checkbox {
            display: table-cell;
            width: 24px;
            vertical-align: top;
        }

        .option-content {
            display: table-cell;
            vertical-align: top;
        }

        .option-name {
            font-weight: 600;
            color: #374151;
        }

        .option-description {
            font-size: 11px;
            color: #6b7280;
            margin-top: 4px;
        }

        .option-price {
            display: table-cell;
            width: 100px;
            text-align: right;
            font-weight: 600;
            vertical-align: top;
        }

        .checkbox-icon {
            width: 16px;
            height: 16px;
            border: 2px solid #d1d5db;
            border-radius: 3px;
            display: inline-block;
        }

        .checkbox-icon.checked {
            background-color: #3b82f6;
            border-color: #3b82f6;
            position: relative;
        }

        .checkbox-icon.checked::after {
            content: "✓";
            color: white;
            font-size: 12px;
            position: absolute;
            top: -2px;
            left: 1px;
        }

        .recommended-badge {
            display: inline-block;
            background-color: #fef3c7;
            color: #92400e;
            font-size: 9px;
            font-weight: 600;
            padding: 2px 6px;
            border-radius: 9999px;
            margin-left: 8px;
            text-transform: uppercase;
        }

        .photos-section {
            margin-top: 30px;
        }

        .photos-grid {
            display: table;
            width: 100%;
        }

        .photo-row {
            display: table-row;
        }

        .photo-cell {
            display: table-cell;
            width: 33.33%;
            padding: 8px;
            vertical-align: top;
        }

        .photo-wrapper {
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            overflow: hidden;
        }

        .photo-image {
            width: 100%;
            height: 150px;
            object-fit: cover;
        }

        .photo-caption {
            font-size: 10px;
            color: #6b7280;
            padding: 8px;
            text-align: center;
        }

        .signature-section {
            margin-top: 40px;
            padding: 20px;
            border: 2px solid #10b981;
            border-radius: 8px;
            background-color: #f0fdf4;
        }

        .signature-header {
            display: table;
            width: 100%;
            margin-bottom: 15px;
        }

        .signature-title {
            display: table-cell;
            font-size: 14px;
            font-weight: bold;
            color: #065f46;
        }

        .signature-badge {
            display: table-cell;
            text-align: right;
        }

        .signed-badge {
            display: inline-block;
            background-color: #10b981;
            color: white;
            font-size: 10px;
            font-weight: 600;
            padding: 4px 12px;
            border-radius: 9999px;
            text-transform: uppercase;
        }

        .signature-content {
            display: table;
            width: 100%;
        }

        .signature-image-cell {
            display: table-cell;
            width: 200px;
            vertical-align: top;
        }

        .signature-image {
            max-width: 180px;
            max-height: 80px;
            border-bottom: 1px solid #374151;
        }

        .typed-signature {
            font-family: 'Georgia', serif;
            font-size: 24px;
            font-style: italic;
            color: #1f2937;
            border-bottom: 1px solid #374151;
            padding-bottom: 5px;
        }

        .signature-details-cell {
            display: table-cell;
            vertical-align: top;
            padding-left: 20px;
        }

        .signature-detail {
            font-size: 11px;
            margin-bottom: 4px;
        }

        .signature-detail-label {
            color: #6b7280;
        }

        .signature-detail-value {
            color: #374151;
            font-weight: 500;
        }

        .notes-section {
            margin-top: 40px;
            padding: 20px;
            background-color: #f9fafb;
            border-radius: 8px;
        }

        .notes-title {
            font-weight: 600;
            margin-bottom: 8px;
            color: #374151;
        }

        .notes-content {
            color: #6b7280;
            white-space: pre-wrap;
        }

        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            color: #9ca3af;
            font-size: 10px;
        }

        .two-column {
            width: 100%;
            display: table;
        }

        .column {
            display: table-cell;
            width: 48%;
            vertical-align: top;
        }

        .column:first-child {
            padding-right: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <table style="width: 100%; margin-bottom: 40px; border-bottom: 2px solid #3b82f6; padding-bottom: 20px;">
            <tr>
                <td style="vertical-align: top;">
                    <div class="company-name">{{ config('app.name') }}</div>
                    <div style="color: #6b7280;">{{ __('Professional Painting Services') }}</div>
                </td>
                <td style="text-align: right; vertical-align: top;">
                    <div class="estimate-number">{{ $estimate->estimate_number }}</div>
                    <div class="status-badge status-{{ $estimate->status }}">{{ $estimate->status_label }}</div>
                    <div style="margin-top: 10px; color: #6b7280;">
                        {{ __('Created:') }} {{ $estimate->created_at->format('M j, Y') }}
                    </div>
                </td>
            </tr>
        </table>

        <div class="two-column">
            <div class="column">
                <div class="section">
                    <div class="section-title">{{ __('Estimate Details') }}</div>
                    <div class="info-grid">
                        <div class="info-row">
                            <div class="info-label">{{ __('Title:') }}</div>
                            <div class="info-value">{{ $estimate->title }}</div>
                        </div>
                        @if ($estimate->description)
                            <div class="info-row">
                                <div class="info-label">{{ __('Description:') }}</div>
                                <div class="info-value">{{ $estimate->description }}</div>
                            </div>
                        @endif
                        @if ($estimate->valid_until)
                            <div class="info-row">
                                <div class="info-label">{{ __('Valid Until:') }}</div>
                                <div class="info-value">{{ $estimate->valid_until->format('M j, Y') }}</div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="column">
                <div class="section">
                    <div class="section-title">{{ __('Customer Information') }}</div>
                    <div class="info-grid">
                        <div class="info-row">
                            <div class="info-label">{{ __('Customer:') }}</div>
                            <div class="info-value">{{ $estimate->deal?->customer?->full_name ?? '-' }}</div>
                        </div>
                        @if ($estimate->deal?->customer?->email)
                            <div class="info-row">
                                <div class="info-label">{{ __('Email:') }}</div>
                                <div class="info-value">{{ $estimate->deal->customer->email }}</div>
                            </div>
                        @endif
                        @if ($estimate->deal?->customer?->phone)
                            <div class="info-row">
                                <div class="info-label">{{ __('Phone:') }}</div>
                                <div class="info-value">{{ $estimate->deal->customer->phone }}</div>
                            </div>
                        @endif
                        <div class="info-row">
                            <div class="info-label">{{ __('Deal:') }}</div>
                            <div class="info-value">{{ $estimate->deal->title }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="section">
            <div class="section-title">{{ __('Line Items') }}</div>
            <table class="items-table">
                <thead>
                    <tr>
                        <th style="width: 45%;">{{ __('Description') }}</th>
                        <th class="text-right" style="width: 12%;">{{ __('Qty') }}</th>
                        <th style="width: 13%;">{{ __('Unit') }}</th>
                        <th class="text-right" style="width: 15%;">{{ __('Unit Price') }}</th>
                        <th class="text-right" style="width: 15%;">{{ __('Total') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($estimate->items as $item)
                        <tr>
                            <td>
                                <div class="item-description">{{ $item->description }}</div>
                                @if ($item->details)
                                    <div class="item-details">{{ $item->details }}</div>
                                @endif
                            </td>
                            <td class="text-right">{{ number_format($item->quantity, 2) }}</td>
                            <td>{{ $item->unit }}</td>
                            <td class="text-right">${{ number_format($item->unit_price, 2) }}</td>
                            <td class="text-right">${{ number_format($item->total, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

        </div>

        @if ($estimate->areas->count() > 0)
            <div class="section areas-section">
                <div class="section-title">{{ __('Areas & Surfaces') }}</div>
                @foreach ($estimate->areas as $area)
                    <div class="area-name">
                        {{ $area->name }}
                        @if ($area->length && $area->width && $area->height)
                            <span class="area-dimensions">
                                ({{ number_format($area->length, 0) }}' x {{ number_format($area->width, 0) }}' x {{ number_format($area->height, 0) }}' H)
                            </span>
                        @endif
                    </div>
                    @if ($area->surfaces->count() > 0)
                        <table class="surfaces-table">
                            <thead>
                                <tr>
                                    <th style="width: 35%;">{{ __('Surface') }}</th>
                                    <th class="text-right" style="width: 15%;">{{ __('Qty') }}</th>
                                    <th style="width: 10%;">{{ __('Unit') }}</th>
                                    <th class="text-right" style="width: 12%;">{{ __('Coats') }}</th>
                                    <th class="text-right" style="width: 14%;">{{ __('Labor') }}</th>
                                    <th class="text-right" style="width: 14%;">{{ __('Material') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($area->surfaces->where('is_included', true) as $surface)
                                    <tr>
                                        <td>{{ $surface->name }}</td>
                                        <td class="text-right">{{ number_format($surface->quantity, 0) }}</td>
                                        <td>{{ $surface->unit }}</td>
                                        <td class="text-right">{{ $surface->coats }}</td>
                                        <td class="text-right">${{ number_format($surface->labor_cost, 2) }}</td>
                                        <td class="text-right">${{ number_format($surface->material_cost, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                @endforeach
            </div>
        @endif

        @if ($estimate->options->count() > 0)
            <div class="section options-section">
                <div class="section-title">{{ __('Options') }}</div>
                @foreach ($estimate->options as $option)
                    <div class="option-item {{ $option->is_selected ? 'selected' : 'not-selected' }}">
                        <div class="option-checkbox">
                            <div class="checkbox-icon {{ $option->is_selected ? 'checked' : '' }}"></div>
                        </div>
                        <div class="option-content">
                            <div class="option-name">
                                {{ $option->name }}
                                @if ($option->is_recommended)
                                    <span class="recommended-badge">{{ __('Recommended') }}</span>
                                @endif
                            </div>
                            @if ($option->description)
                                <div class="option-description">{{ $option->description }}</div>
                            @endif
                        </div>
                        <div class="option-price">
                            ${{ number_format($option->calculated_price ?? $option->fixed_price ?? 0, 2) }}
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($estimate->photos->count() > 0)
            <div class="section photos-section">
                <div class="section-title">{{ __('Project Photos') }}</div>
                <div class="photos-grid">
                    @foreach ($estimate->photos->chunk(3) as $photoRow)
                        <div class="photo-row">
                            @foreach ($photoRow as $photo)
                                <div class="photo-cell">
                                    <div class="photo-wrapper">
                                        @php
                                            $diskName = $photo->disk ?? config('filesystems.default');
                                            $imgDisk = \Illuminate\Support\Facades\Storage::disk($diskName);
                                            $imgSrc = '';
                                            if ($imgDisk->exists($photo->path)) {
                                                $imgData = base64_encode($imgDisk->get($photo->path));
                                                $imgSrc = 'data:' . ($photo->mime_type ?? 'image/jpeg') . ';base64,' . $imgData;
                                            }
                                        @endphp
                                        <img src="{{ $imgSrc }}" class="photo-image" alt="{{ $photo->caption ?? __('Project photo') }}">
                                        @if ($photo->caption)
                                            <div class="photo-caption">{{ $photo->caption }}</div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="section">
            <div class="section-title">{{ __('Pricing Summary') }}</div>
            <div class="totals">
                @if ($estimate->items_subtotal > 0)
                    <div class="totals-row">
                        <span class="totals-label">{{ __('Line Items') }}</span>
                        <span>${{ number_format($estimate->items_subtotal, 2) }}</span>
                    </div>
                @endif
                @if ($estimate->areas_subtotal > 0)
                    <div class="totals-row">
                        <span class="totals-label">{{ __('Areas & Surfaces') }}</span>
                        <span>${{ number_format($estimate->areas_subtotal, 2) }}</span>
                    </div>
                @endif
                @if ($estimate->options_subtotal > 0)
                    <div class="totals-row">
                        <span class="totals-label">{{ __('Selected Options') }}</span>
                        <span>${{ number_format($estimate->options_subtotal, 2) }}</span>
                    </div>
                @endif
                <div class="totals-row">
                    <span class="totals-label">{{ __('Subtotal') }}</span>
                    <span>${{ number_format($estimate->subtotal, 2) }}</span>
                </div>
                @if ($estimate->tax_rate > 0)
                    <div class="totals-row">
                        <span class="totals-label">{{ __('Tax') }} ({{ number_format($estimate->tax_rate, 2) }}%)</span>
                        <span>${{ number_format($estimate->tax_amount, 2) }}</span>
                    </div>
                @endif
                @if ($estimate->discount_amount > 0)
                    <div class="totals-row">
                        <span class="totals-label">{{ __('Discount') }}</span>
                        <span class="discount">-${{ number_format($estimate->discount_amount, 2) }}</span>
                    </div>
                @endif
                <div class="totals-row total">
                    <span class="totals-label">{{ __('Total') }}</span>
                    <span>${{ number_format($estimate->total, 2) }}</span>
                </div>
            </div>
        </div>

        @if ($estimate->createdBy?->internalUser?->hasSignature())
            <div class="section" style="margin-top: 40px;">
                <div class="section-title">{{ __('Prepared By') }}</div>
                <div style="display: table; width: 100%;">
                    <div style="display: table-cell; width: 200px; vertical-align: top;">
                        @if ($estimate->createdBy->internalUser->signature_type === 'drawn')
                            <img src="{{ $estimate->createdBy->internalUser->signature_data }}" style="max-width: 180px; max-height: 60px; border-bottom: 1px solid #374151;" alt="Signature">
                        @else
                            <div class="typed-signature">{{ $estimate->createdBy->internalUser->signature_data }}</div>
                        @endif
                    </div>
                    <div style="display: table-cell; vertical-align: top; padding-left: 20px;">
                        <div class="signature-detail">
                            <span class="signature-detail-value" style="font-weight: 600;">{{ $estimate->createdBy->name }}</span>
                        </div>
                        @if ($estimate->createdBy->internalUser->job_title)
                            <div class="signature-detail">
                                <span class="signature-detail-value">{{ $estimate->createdBy->internalUser->job_title }}</span>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endif

        @if ($estimate->signature)
            <div class="signature-section">
                <div class="signature-header">
                    <div class="signature-title">{{ __('Customer Acceptance') }}</div>
                    <div class="signature-badge">
                        <span class="signed-badge">{{ __('Signed') }}</span>
                    </div>
                </div>
                <div class="signature-content">
                    <div class="signature-image-cell">
                        @if ($estimate->signature->signature_type === 'drawn' && $estimate->signature->signature_data)
                            <img src="{{ $estimate->signature->signature_data }}" class="signature-image" alt="Signature">
                        @else
                            <div class="typed-signature">{{ $estimate->signature->signer_name }}</div>
                        @endif
                    </div>
                    <div class="signature-details-cell">
                        <div class="signature-detail">
                            <span class="signature-detail-label">{{ __('Signed by:') }}</span>
                            <span class="signature-detail-value">{{ $estimate->signature->signer_name }}</span>
                        </div>
                        <div class="signature-detail">
                            <span class="signature-detail-label">{{ __('Email:') }}</span>
                            <span class="signature-detail-value">{{ $estimate->signature->signer_email }}</span>
                        </div>
                        <div class="signature-detail">
                            <span class="signature-detail-label">{{ __('Date:') }}</span>
                            <span class="signature-detail-value">{{ $estimate->signature->signed_at->format('M j, Y g:i A') }}</span>
                        </div>
                        <div class="signature-detail">
                            <span class="signature-detail-label">{{ __('IP Address:') }}</span>
                            <span class="signature-detail-value">{{ $estimate->signature->ip_address }}</span>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        @if ($estimate->notes || $estimate->terms)
            <div class="two-column" style="margin-top: 30px;">
                @if ($estimate->notes)
                    <div class="column">
                        <div class="notes-section">
                            <div class="notes-title">{{ __('Notes') }}</div>
                            <div class="notes-content">{{ $estimate->notes }}</div>
                        </div>
                    </div>
                @endif

                @if ($estimate->terms)
                    <div class="column">
                        <div class="notes-section">
                            <div class="notes-title">{{ __('Terms & Conditions') }}</div>
                            <div class="notes-content">{{ $estimate->terms }}</div>
                        </div>
                    </div>
                @endif
            </div>
        @endif

        <div class="footer">
            <p>{{ __('Generated on') }} {{ now()->format('M j, Y g:i A') }}</p>
            <p>{{ __('This estimate is valid until') }} {{ $estimate->valid_until?->format('M j, Y') ?? __('further notice') }}.</p>
        </div>
    </div>
</body>
</html>
