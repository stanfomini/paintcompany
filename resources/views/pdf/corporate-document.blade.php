<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $document->title }}</title>
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

        .company-name {
            font-size: 24px;
            font-weight: bold;
            color: #1e40af;
            margin-bottom: 5px;
        }

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

        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-blue {
            background-color: #dbeafe;
            color: #1e40af;
        }

        .badge-gray {
            background-color: #f3f4f6;
            color: #374151;
        }

        .document-title {
            font-size: 20px;
            font-weight: bold;
            color: #111827;
            margin-bottom: 8px;
        }

        .content-heading-1 {
            font-size: 18px;
            font-weight: bold;
            color: #111827;
            margin-top: 20px;
            margin-bottom: 8px;
        }

        .content-heading-2 {
            font-size: 15px;
            font-weight: bold;
            color: #1f2937;
            margin-top: 16px;
            margin-bottom: 6px;
        }

        .content-heading-3 {
            font-size: 13px;
            font-weight: bold;
            color: #374151;
            margin-top: 12px;
            margin-bottom: 4px;
        }

        .content-paragraph {
            margin-bottom: 10px;
            line-height: 1.6;
        }

        .content-list-item {
            margin-left: 24px;
            margin-bottom: 4px;
            line-height: 1.6;
        }

        .content-list-item::before {
            content: "\2022  ";
        }

        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            color: #9ca3af;
            font-size: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        {{-- Company Header --}}
        <table style="width: 100%; margin-bottom: 40px; border-bottom: 2px solid #3b82f6; padding-bottom: 20px;">
            <tr>
                <td style="vertical-align: top;">
                    <div class="company-name">{{ $settings->company_name ?? config('app.name') }}</div>
                    @if ($settings->address_line_1)
                        <div style="color: #6b7280;">{{ $settings->address_line_1 }}</div>
                    @endif
                    @if ($settings->city || $settings->state || $settings->zip_code)
                        <div style="color: #6b7280;">{{ collect([$settings->city, $settings->state])->filter()->implode(', ') }} {{ $settings->zip_code }}</div>
                    @endif
                </td>
                <td style="text-align: right; vertical-align: top;">
                    @if ($settings->company_phone)
                        <div style="color: #6b7280;">{{ $settings->company_phone }}</div>
                    @endif
                    @if ($settings->company_email)
                        <div style="color: #6b7280;">{{ $settings->company_email }}</div>
                    @endif
                </td>
            </tr>
        </table>

        {{-- Corporation Info --}}
        @if ($document->corporation)
            <div style="margin-bottom: 10px; color: #6b7280; font-size: 13px;">
                {{ $document->corporation->name }}
                @if ($document->corporation->entity_type)
                    &mdash; {{ $document->corporation->entity_type }}
                @endif
            </div>
        @endif

        {{-- Document Title + Version + Type --}}
        <div style="margin-bottom: 24px;">
            <div class="document-title">
                {{ $document->title }}
                @if ($document->version)
                    <span class="badge badge-blue">Version {{ $document->version }}</span>
                @endif
            </div>
            @if ($document->type && isset(App\Models\CorporateDocument::TYPES[$document->type]))
                <span class="badge badge-gray">{{ App\Models\CorporateDocument::TYPES[$document->type] }}</span>
            @endif
        </div>

        {{-- Document Content --}}
        @php
            $sections = $document->getContentForExport();
        @endphp

        @if ($sections)
            <div class="section">
                @foreach ($sections as $section)
                    @if (($section['type'] ?? '') === 'heading')
                        @php $level = $section['level'] ?? 1; @endphp
                        @if ($level === 1)
                            <div class="content-heading-1">{{ $section['text'] ?? '' }}</div>
                        @elseif ($level === 2)
                            <div class="content-heading-2">{{ $section['text'] ?? '' }}</div>
                        @else
                            <div class="content-heading-3">{{ $section['text'] ?? '' }}</div>
                        @endif
                    @elseif (($section['type'] ?? '') === 'paragraph')
                        <div class="content-paragraph">{{ $section['text'] ?? '' }}</div>
                    @elseif (($section['type'] ?? '') === 'list_item')
                        <div class="content-list-item">{{ $section['text'] ?? '' }}</div>
                    @endif
                @endforeach
            </div>
        @else
            <div class="section">
                <p style="color: #9ca3af; font-style: italic;">No content available for this document.</p>
            </div>
        @endif

        {{-- Footer --}}
        <div class="footer">
            <p>Generated on {{ now()->format('M j, Y g:i A') }}</p>
        </div>
    </div>
</body>
</html>
