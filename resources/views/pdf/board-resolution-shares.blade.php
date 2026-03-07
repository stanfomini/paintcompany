<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Board Resolution - {{ $data['corporation_name'] ?? 'Corporation' }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Times New Roman', 'Georgia', serif; font-size: 12px; line-height: 1.8; color: #111; }
        .container { padding: 60px 70px; }
        .title { text-align: center; font-size: 16px; font-weight: bold; text-transform: uppercase; margin-bottom: 8px; letter-spacing: 1px; }
        .subtitle { text-align: center; font-size: 13px; font-weight: bold; margin-bottom: 30px; }
        .corporation-name { text-align: center; font-size: 14px; font-weight: bold; margin-bottom: 4px; }
        .section { margin-bottom: 16px; }
        .recital { margin-bottom: 12px; text-indent: 40px; }
        .resolved { margin-bottom: 12px; text-indent: 40px; }
        .bold { font-weight: bold; }
        .underline { text-decoration: underline; }
        .signature-block { margin-top: 60px; }
        .signature-line { margin-top: 40px; width: 300px; }
        .signature-line-rule { border-top: 1px solid #333; padding-top: 4px; }
        .footer { margin-top: 50px; text-align: center; color: #999; font-size: 9px; font-family: 'Helvetica', sans-serif; }
    </style>
</head>
<body>
    <div class="container">
        <div class="corporation-name">{{ $data['corporation_name'] }}</div>
        <div class="title">Resolution of the Board of Directors</div>
        <div class="subtitle">Authorization to Issue Shares of Stock</div>

        <div class="section">
            <p class="recital">
                The undersigned, being the sole Director of
                <span class="bold">{{ $data['corporation_name'] }}</span>,
                a {{ $data['state'] ?? 'Colorado' }} corporation (the "Corporation"),
                hereby adopts the following resolution effective as of
                <span class="bold">{{ $data['resolution_date'] }}</span>:
            </p>
        </div>

        <div class="section">
            <p class="recital">
                <span class="bold">WHEREAS</span>, the Corporation's Articles of Incorporation authorize the issuance
                of shares of capital stock; and
            </p>
            <p class="recital">
                <span class="bold">WHEREAS</span>, the Board of Directors deems it in the best interest of the Corporation
                to issue shares to its shareholder in exchange for consideration; and
            </p>
            <p class="recital">
                <span class="bold">WHEREAS</span>, the Board of Directors has determined that the consideration to be
                received for such shares is adequate;
            </p>
        </div>

        <div class="section">
            <p class="recital">
                <span class="bold">NOW, THEREFORE, BE IT RESOLVED</span>, that the Corporation hereby authorizes the
                issuance of <span class="bold">{{ number_format((int) ($data['share_count'] ?? 0)) }}</span> shares of
                <span class="bold">{{ $data['share_class'] ?? 'Common' }}</span> Stock to:
            </p>
            <p style="text-align: center; margin: 16px 0; font-weight: bold; font-size: 13px;">
                {{ $data['shareholder_name'] }}
                @if (!empty($data['shareholder_entity_type']))
                    <br><span style="font-weight: normal; font-size: 11px;">(a {{ $data['shareholder_entity_type'] }})</span>
                @endif
                @if (!empty($data['shareholder_ein']))
                    <br><span style="font-weight: normal; font-size: 11px;">EIN: {{ $data['shareholder_ein'] }}</span>
                @endif
            </p>
            <p class="recital">
                in exchange for the following consideration:
                <span class="bold">{{ $data['consideration'] ?? 'services rendered and to be rendered' }}</span>.
            </p>
        </div>

        <div class="section">
            <p class="resolved">
                <span class="bold">RESOLVED FURTHER</span>, that the shares shall be
                @if (!empty($data['certificated']) && $data['certificated'])
                    evidenced by stock certificates in the form approved by the Board of Directors.
                @else
                    issued in uncertificated (book-entry) form and recorded in the Corporation's stock ledger.
                @endif
            </p>
        </div>

        <div class="section">
            <p class="resolved">
                <span class="bold">RESOLVED FURTHER</span>, that the Director is hereby authorized and directed to take
                all actions necessary to effectuate the foregoing resolutions, including but not limited to updating
                the Corporation's stock ledger and issuing any required notices.
            </p>
        </div>

        <div class="signature-block">
            <p style="margin-bottom: 4px;">IN WITNESS WHEREOF, the undersigned has executed this resolution as of the date first written above.</p>

            <div class="signature-line">
                <div class="signature-line-rule">
                    {{ $data['director_name'] }}, Director
                </div>
            </div>

            <div class="signature-line" style="margin-top: 24px;">
                <div class="signature-line-rule">
                    Date
                </div>
            </div>
        </div>

        <div class="footer">
            <p>Generated on {{ now()->format('M j, Y g:i A') }}</p>
        </div>
    </div>
</body>
</html>
