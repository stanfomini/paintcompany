<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Corporate Document Packet</title>
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

        .cover-title {
            font-size: 28px;
            font-weight: bold;
            color: #1e40af;
            margin-top: 80px;
            margin-bottom: 16px;
        }

        .cover-date {
            font-size: 14px;
            color: #6b7280;
        }

        .toc-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        .toc-table th {
            background-color: #f3f4f6;
            padding: 10px 8px;
            text-align: left;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            color: #374151;
            border-bottom: 2px solid #e5e7eb;
        }

        .toc-table td {
            padding: 10px 8px;
            border-bottom: 1px solid #e5e7eb;
        }

        .toc-table tr:nth-child(even) td {
            background-color: #f9fafb;
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

        .content-block {
            white-space: pre-wrap;
            line-height: 1.6;
        }

        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            color: #9ca3af;
            font-size: 10px;
        }

        .page-break {
            page-break-before: always;
        }
    </style>
</head>
<body>
    <div class="container">
        {{-- Cover Page --}}
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

        <div class="cover-title">Corporate Document Packet</div>
        <div class="cover-date">{{ now()->format('F j, Y') }}</div>

        {{-- Table of Contents --}}
        <div class="section" style="margin-top: 40px;">
            <div class="section-title">Table of Contents</div>
            <table class="toc-table">
                <thead>
                    <tr>
                        <th style="width: 5%;">#</th>
                        <th style="width: 55%;">Title</th>
                        <th style="width: 25%;">Type</th>
                        <th style="width: 15%;">Date</th>
                    </tr>
                </thead>
                <tbody>
                    @php $tocIndex = 1; @endphp
                    @foreach ($documents as $document)
                        <tr>
                            <td>{{ $tocIndex++ }}</td>
                            <td>{{ $document->title }}</td>
                            <td>
                                @if ($document->type && isset(App\Models\CorporateDocument::TYPES[$document->type]))
                                    {{ App\Models\CorporateDocument::TYPES[$document->type] }}
                                @else
                                    Document
                                @endif
                            </td>
                            <td>{{ $document->created_at->format('M j, Y') }}</td>
                        </tr>
                    @endforeach
                    @foreach ($minutes as $minute)
                        <tr>
                            <td>{{ $tocIndex++ }}</td>
                            <td>{{ $minute->title }}</td>
                            <td>Meeting Minutes</td>
                            <td>{{ $minute->meeting_date?->format('M j, Y') ?? $minute->created_at->format('M j, Y') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Documents --}}
        @foreach ($documents as $document)
            <div class="page-break">
                {{-- Document header --}}
                @if ($document->corporation)
                    <div style="margin-bottom: 10px; color: #6b7280; font-size: 13px;">
                        {{ $document->corporation->name }}
                        @if ($document->corporation->entity_type)
                            &mdash; {{ $document->corporation->entity_type }}
                        @endif
                    </div>
                @endif

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

                {{-- Document content --}}
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
            </div>
        @endforeach

        {{-- Meeting Minutes --}}
        @foreach ($minutes as $minute)
            <div class="page-break">
                @if ($minute->corporation)
                    <div style="margin-bottom: 10px; color: #6b7280; font-size: 13px;">
                        {{ $minute->corporation->name }}
                    </div>
                @endif

                <div style="font-size: 20px; font-weight: bold; color: #111827; margin-bottom: 24px;">
                    Meeting Minutes
                </div>

                {{-- Info block --}}
                <div class="section">
                    <div class="info-grid">
                        <div class="info-row">
                            <div class="info-label">Title:</div>
                            <div class="info-value">{{ $minute->title }}</div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Date:</div>
                            <div class="info-value">{{ $minute->meeting_date?->format('F j, Y') ?? 'Not set' }}</div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Type:</div>
                            <div class="info-value">
                                @if ($minute->meeting_type && isset(App\Models\MeetingMinutes::MEETING_TYPES[$minute->meeting_type]))
                                    <span class="badge badge-blue">{{ App\Models\MeetingMinutes::MEETING_TYPES[$minute->meeting_type] }}</span>
                                @else
                                    {{ $minute->meeting_type ?? 'Not specified' }}
                                @endif
                            </div>
                        </div>
                        @if ($minute->attendees && is_array($minute->attendees) && count($minute->attendees) > 0)
                            <div class="info-row">
                                <div class="info-label">Attendees:</div>
                                <div class="info-value">{{ implode(', ', $minute->attendees) }}</div>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Executive Summary --}}
                <div class="section">
                    <div class="section-title">Executive Summary</div>
                    @if ($minute->executive_summary)
                        <div class="content-block">{{ $minute->executive_summary }}</div>
                    @else
                        <p style="color: #9ca3af; font-style: italic;">Summary pending...</p>
                    @endif
                </div>

                {{-- Transcript --}}
                <div class="section">
                    <div class="section-title">Transcript</div>
                    @if ($minute->transcript)
                        <div class="content-block">{{ $minute->transcript }}</div>
                    @else
                        <p style="color: #9ca3af; font-style: italic;">No transcript available</p>
                    @endif
                </div>

                {{-- Notes --}}
                @if ($minute->notes)
                    <div class="section">
                        <div class="section-title">Notes</div>
                        <div class="content-block">{{ $minute->notes }}</div>
                    </div>
                @endif
            </div>
        @endforeach

        {{-- Footer --}}
        <div class="footer">
            <p>Generated on {{ now()->format('M j, Y g:i A') }}</p>
        </div>
    </div>
</body>
</html>
