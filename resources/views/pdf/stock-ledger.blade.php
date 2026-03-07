<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Stock Ledger - {{ $data['corporation_name'] ?? 'Corporation' }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 11px; line-height: 1.5; color: #111; }
        .container { padding: 50px 50px; }
        .title { text-align: center; font-size: 18px; font-weight: bold; text-transform: uppercase; margin-bottom: 4px; letter-spacing: 1px; }
        .corporation-name { text-align: center; font-size: 14px; font-weight: bold; margin-bottom: 6px; color: #1e40af; }
        .subtitle { text-align: center; font-size: 11px; color: #666; margin-bottom: 30px; }
        .section-title { font-size: 13px; font-weight: bold; color: #374151; margin-bottom: 10px; padding-bottom: 5px; border-bottom: 2px solid #3b82f6; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        th { background-color: #f3f4f6; color: #374151; font-weight: 600; text-align: left; padding: 8px 10px; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #d1d5db; }
        td { padding: 8px 10px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        tr:nth-child(even) td { background-color: #f9fafb; }
        .summary-table td { font-weight: 500; }
        .summary-label { color: #6b7280; }
        .number { text-align: right; font-variant-numeric: tabular-nums; }
        .footer { margin-top: 40px; text-align: center; color: #9ca3af; font-size: 9px; }
        .empty-notice { text-align: center; padding: 20px; color: #9ca3af; font-style: italic; }
    </style>
</head>
<body>
    <div class="container">
        <div class="corporation-name">{{ $data['corporation_name'] }}</div>
        <div class="title">Stock Ledger</div>
        <div class="subtitle">As of {{ now()->format('F j, Y') }}</div>

        {{-- Share Summary --}}
        @if (!empty($data['authorized_shares']))
            <div class="section-title">Share Summary</div>
            <table class="summary-table">
                <thead>
                    <tr>
                        <th>Class</th>
                        <th class="number">Authorized</th>
                        <th class="number">Issued</th>
                        <th class="number">Outstanding</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($data['authorized_shares'] as $auth)
                        @php
                            $issued = collect($data['entries'] ?? [])->where('class', $auth['class'] ?? 'Common')->sum('shares');
                        @endphp
                        <tr>
                            <td>{{ $auth['class'] ?? 'Common' }} Stock</td>
                            <td class="number">{{ number_format((int) ($auth['authorized'] ?? 0)) }}</td>
                            <td class="number">{{ number_format((int) $issued) }}</td>
                            <td class="number">{{ number_format((int) $issued) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        {{-- Issuance Register --}}
        <div class="section-title">Issuance Register</div>
        @if (!empty($data['entries']))
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Shareholder</th>
                        <th>Class</th>
                        <th class="number">Shares</th>
                        <th>Cert #</th>
                        <th>Consideration</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($data['entries'] as $entry)
                        <tr>
                            <td>{{ $entry['date'] ?? '' }}</td>
                            <td>
                                {{ $entry['shareholder'] ?? '' }}
                                @if (!empty($entry['address']))
                                    <br><span style="font-size: 9px; color: #6b7280;">{{ $entry['address'] }}</span>
                                @endif
                            </td>
                            <td>{{ $entry['class'] ?? 'Common' }}</td>
                            <td class="number">{{ number_format((int) ($entry['shares'] ?? 0)) }}</td>
                            <td>{{ $entry['certificate_number'] ?? 'Uncertificated' }}</td>
                            <td>{{ $entry['consideration'] ?? '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="empty-notice">No shares have been issued.</div>
        @endif

        <div class="footer">
            <p>Generated on {{ now()->format('M j, Y g:i A') }}</p>
        </div>
    </div>
</body>
</html>
