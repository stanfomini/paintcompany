<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meeting Minutes - {{ $minutes->title }}</title>
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

        .transcript-table {
            width: 100%;
            border-collapse: collapse;
        }

        .transcript-table td {
            vertical-align: top;
            padding: 6px 8px;
            border-bottom: 1px solid #f3f4f6;
        }

        .transcript-timestamp {
            width: 60px;
            font-family: 'Courier New', monospace;
            font-size: 10px;
            font-weight: 600;
            color: #1e40af;
            white-space: nowrap;
        }

        .transcript-speaker {
            font-size: 10px;
            font-weight: 600;
            color: #6b7280;
            margin-bottom: 2px;
        }

        .transcript-text {
            font-size: 11px;
            line-height: 1.5;
            color: #374151;
        }

        .transcript-columns {
            column-count: 2;
            column-gap: 24px;
            column-rule: 1px solid #e5e7eb;
        }

        .transcript-entry {
            break-inside: avoid;
            margin-bottom: 8px;
            padding-bottom: 6px;
            border-bottom: 1px solid #f3f4f6;
        }

        .transcript-entry-time {
            font-family: 'Courier New', monospace;
            font-size: 10px;
            font-weight: 600;
            color: #1e40af;
            display: inline-block;
            margin-right: 6px;
        }

        .transcript-entry-speaker {
            font-size: 10px;
            font-weight: 600;
            color: #6b7280;
        }

        .transcript-entry-text {
            font-size: 11px;
            line-height: 1.5;
            color: #374151;
            margin-top: 2px;
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

        {{-- Corporation Name --}}
        @if ($minutes->corporation)
            <div style="margin-bottom: 10px; color: #6b7280; font-size: 13px;">
                {{ $minutes->corporation->name }}
            </div>
        @endif

        {{-- Title --}}
        <div style="font-size: 20px; font-weight: bold; color: #111827; margin-bottom: 24px;">
            Meeting Minutes
        </div>

        {{-- Info Block --}}
        <div class="section">
            <div class="info-grid">
                <div class="info-row">
                    <div class="info-label">Title:</div>
                    <div class="info-value">{{ $minutes->title }}</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Date:</div>
                    <div class="info-value">{{ $minutes->meeting_date?->format('F j, Y') ?? 'Not set' }}</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Type:</div>
                    <div class="info-value">
                        @if ($minutes->meeting_type && isset(App\Models\MeetingMinutes::MEETING_TYPES[$minutes->meeting_type]))
                            <span class="badge badge-blue">{{ App\Models\MeetingMinutes::MEETING_TYPES[$minutes->meeting_type] }}</span>
                        @else
                            {{ $minutes->meeting_type ?? 'Not specified' }}
                        @endif
                    </div>
                </div>
                @if ($minutes->attendees && is_array($minutes->attendees) && count($minutes->attendees) > 0)
                    <div class="info-row">
                        <div class="info-label">Attendees:</div>
                        <div class="info-value">{{ implode(', ', $minutes->attendees) }}</div>
                    </div>
                @endif
            </div>
        </div>

        {{-- Executive Summary --}}
        <div class="section">
            <div class="section-title">Executive Summary</div>
            @if ($minutes->executive_summary)
                <div class="content-block">{{ $minutes->executive_summary }}</div>
            @else
                <p style="color: #9ca3af; font-style: italic;">Summary pending...</p>
            @endif
        </div>

        {{-- Timestamped Transcript --}}
        @if ($minutes->transcript_segments && is_array($minutes->transcript_segments) && count($minutes->transcript_segments) > 0)
            <div class="section">
                <div class="section-title">Timestamped Transcript</div>
                <div class="transcript-columns">
                    @foreach ($minutes->transcript_segments as $segment)
                        <div class="transcript-entry">
                            <span class="transcript-entry-time">{{ gmdate('i:s', (int) ($segment['start'] ?? 0)) }}</span>
                            @if (!empty($segment['speaker']))
                                <span class="transcript-entry-speaker">{{ $segment['speaker'] }}</span>
                            @endif
                            <div class="transcript-entry-text">{{ $segment['text'] ?? '' }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Full Transcript --}}
        <div class="section">
            <div class="section-title">Full Transcript</div>
            @if ($minutes->transcript)
                <div class="content-block">{{ $minutes->transcript }}</div>
            @else
                <p style="color: #9ca3af; font-style: italic;">No transcript available</p>
            @endif
        </div>

        {{-- Notes --}}
        @if ($minutes->notes)
            <div class="section">
                <div class="section-title">Notes</div>
                <div class="content-block">{{ $minutes->notes }}</div>
            </div>
        @endif

        {{-- Footer --}}
        <div class="footer">
            <p>Generated on {{ now()->format('M j, Y g:i A') }}</p>
        </div>
    </div>
</body>
</html>
