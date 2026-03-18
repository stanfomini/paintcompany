<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Certificate of Incumbency - {{ $data['corporation_name'] ?? 'Corporation' }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Times New Roman', 'Georgia', serif; font-size: 11px; line-height: 1.6; color: #111; }
        .container { padding: 60px 70px; }

        .corp-header { text-align: center; margin-bottom: 28px; padding-bottom: 14px; border-bottom: 2px solid #333; }
        .corp-name { font-size: 18px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px; }
        .corp-details { font-size: 10px; color: #555; line-height: 1.5; }

        .title { text-align: center; font-size: 16px; font-weight: bold; text-transform: uppercase; margin-bottom: 20px; letter-spacing: 1px; }
        .section { margin-bottom: 10px; }
        .section-heading { font-size: 12px; font-weight: bold; text-transform: uppercase; margin-bottom: 6px; margin-top: 16px; border-bottom: 1px solid #ccc; padding-bottom: 3px; }
        .declaration { margin-bottom: 12px; text-indent: 40px; text-align: justify; }
        .info-line { margin-bottom: 3px; padding-left: 20px; font-size: 10px; }
        .bold { font-weight: bold; }

        .officers-table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        .officers-table th, .officers-table td { border: 1px solid #999; padding: 6px 10px; text-align: left; font-size: 10px; }
        .officers-table th { background-color: #f5f5f5; font-weight: bold; text-transform: uppercase; font-size: 9px; letter-spacing: 0.5px; }
        .officers-table td.sig-cell { width: 160px; height: 26px; }

        .authority { margin-top: 10px; margin-bottom: 10px; text-align: justify; }
        .certification { margin-top: 12px; text-align: justify; text-indent: 40px; }

        .signature-block { margin-top: 36px; page-break-inside: avoid; }
        .signature-line { margin-top: 30px; width: 300px; }
        .signature-line-rule { border-top: 1px solid #333; padding-top: 4px; }
        .footer { margin-top: 30px; text-align: center; color: #999; font-size: 9px; font-family: 'Helvetica', sans-serif; }
    </style>
</head>
<body>
    <div class="container">
        {{-- Corporation Header --}}
        @if (!empty($corporation))
            <div class="corp-header">
                <div class="corp-name">{{ $corporation->name }}</div>
                <div class="corp-details">
                    @if ($corporation->entity_type)
                        {{ App\Models\Corporation::ENTITY_TYPES[$corporation->entity_type] ?? $corporation->entity_type }}
                    @endif
                    @if ($corporation->state_of_formation)
                        &bull; {{ $corporation->state_of_formation }}
                    @endif
                    @if ($corporation->ein)
                        &bull; EIN: {{ $corporation->ein }}
                    @endif
                    @if ($corporation->address_line_1)
                        <br>{{ $corporation->address_line_1 }}@if ($corporation->city || $corporation->state), {{ collect([$corporation->city, $corporation->state])->filter()->implode(', ') }} {{ $corporation->zip }}@endif
                    @endif
                </div>
            </div>
        @else
            <div class="corp-header">
                <div class="corp-name">{{ $data['corporation_name'] }}</div>
            </div>
        @endif

        <div class="title">Certificate of Incumbency</div>

        @php
            $entityType = $data['entity_type'] ?? 'LLC';
            $isLlc = $entityType === 'LLC';
            $entityLabel = App\Models\Corporation::ENTITY_TYPES[$entityType] ?? $entityType;
            $governingDoc = $isLlc ? 'Operating Agreement' : 'Bylaws';
            $personnelLabel = $isLlc ? 'Members and Managers' : 'Officers and Directors';
        @endphp

        {{-- Declaration --}}
        <div class="section">
            <p class="declaration">
                I, <span class="bold">{{ $data['certifying_officer'] }}</span>,
                the <span class="bold">{{ $data['certifying_title'] }}</span> of
                <span class="bold">{{ $data['corporation_name'] }}</span>,
                a {{ $entityLabel }} duly organized and existing under the laws of the State of
                <span class="bold">{{ $data['state'] ?? '' }}</span>,
                do hereby certify as of
                <span class="bold">{{ $data['certificate_date'] ?? '' }}</span>
                that the following is a true and correct statement of the {{ strtolower($personnelLabel) }}
                of the entity, their respective titles, and that each is duly elected or appointed
                and currently serving in such capacity.
            </p>
        </div>

        {{-- Entity Information --}}
        @if (!empty($data['formation_date']) || !empty($data['address']) || !empty($data['ein']))
            <div class="section-heading">Entity Information</div>
            <div class="section">
                @if (!empty($data['state']))
                    <p class="info-line"><span class="bold">State of Formation:</span> {{ $data['state'] }}</p>
                @endif
                @if (!empty($data['formation_date']))
                    <p class="info-line"><span class="bold">Date of Formation:</span> {{ $data['formation_date'] }}</p>
                @endif
                @if (!empty($data['address']))
                    <p class="info-line"><span class="bold">Principal Address:</span>
                        {{ $data['address'] }}@if (!empty($data['city'])), {{ $data['city'] }}@endif@if (!empty($data['state_abbr'])), {{ $data['state_abbr'] }}@endif @if (!empty($data['zip'])){{ $data['zip'] }}@endif
                    </p>
                @endif
                @if (!empty($data['registered_agent']))
                    <p class="info-line"><span class="bold">Registered Agent:</span> {{ $data['registered_agent'] }}</p>
                @endif
                @if (!empty($data['ein']))
                    <p class="info-line"><span class="bold">EIN:</span> {{ $data['ein'] }}</p>
                @endif
            </div>
        @endif

        {{-- Officers / Members Table --}}
        <div class="section-heading">{{ $personnelLabel }}</div>
        <div class="section">
            <table class="officers-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Title</th>
                        <th>Specimen Signature</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($data['officers'] ?? [] as $officer)
                        <tr>
                            <td>{{ $officer['name'] ?? '' }}</td>
                            <td>{{ $officer['title'] ?? '' }}</td>
                            <td class="sig-cell"></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" style="text-align: center; color: #999;">
                                No {{ strtolower($personnelLabel) }} on record
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Ownership Section (optional) --}}
        @if (!empty($data['include_ownership']) && !empty($data['owners']))
            <div class="section-heading">Ownership</div>
            <div class="section">
                <table class="officers-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Role</th>
                            <th>Ownership %</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($data['owners'] as $owner)
                            <tr>
                                <td>{{ $owner['name'] ?? '' }}</td>
                                <td>{{ $owner['role'] ?? '' }}</td>
                                <td>{{ isset($owner['percentage']) ? number_format((float) $owner['percentage'], 2) . '%' : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Authority Statement --}}
        @if (!empty($data['authority_statement']))
            <div class="section-heading">Authority</div>
            <div class="section">
                <p class="authority">{{ $data['authority_statement'] }}</p>
            </div>
        @endif

        {{-- Good Standing Certification --}}
        <div class="section">
            <p class="certification">
                I further certify that <span class="bold">{{ $data['corporation_name'] }}</span> is in good standing
                under the laws of {{ $data['state'] ?? 'its state of formation' }} and that this Certificate of
                Incumbency is being issued in accordance with the {{ $governingDoc }} of the entity.
            </p>
        </div>

        {{-- Signature Block — kept together to avoid splitting across pages --}}
        <div class="signature-block">
            <p style="margin-bottom: 4px;">IN WITNESS WHEREOF, I have executed this Certificate of Incumbency as of the date set forth above.</p>

            <div class="signature-line">
                <div class="signature-line-rule">
                    {{ $data['certifying_officer'] }}, {{ $data['certifying_title'] }}
                </div>
            </div>

            <div class="signature-line" style="margin-top: 24px;">
                <div class="signature-line-rule">
                    Date
                </div>
            </div>
        </div>

        <div class="footer">
            <p>Generated on {{ now()->format('F j, Y') }}</p>
        </div>
    </div>
</body>
</html>
