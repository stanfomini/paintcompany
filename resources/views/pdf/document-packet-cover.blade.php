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

        <div class="footer">
            <p>Generated on {{ now()->format('M j, Y g:i A') }}</p>
        </div>
    </div>
</body>
</html>
