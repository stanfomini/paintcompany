<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $document->title }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Times New Roman', 'Georgia', serif;
            font-size: 12px;
            line-height: 1.8;
            color: #111;
        }
        .container { padding: 60px 70px; }

        /* Corporation header */
        .corp-header {
            text-align: center;
            margin-bottom: 40px;
            padding-bottom: 20px;
            border-bottom: 2px solid #333;
        }
        .corp-name {
            font-size: 18px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 4px;
        }
        .corp-details {
            font-size: 10px;
            color: #555;
            line-height: 1.5;
        }

        /* Document title area */
        .doc-title {
            text-align: center;
            font-size: 16px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }
        .doc-subtitle {
            text-align: center;
            font-size: 11px;
            color: #666;
            margin-bottom: 30px;
        }
        .version-badge {
            text-align: center;
            margin-bottom: 30px;
        }
        .version-badge span {
            display: inline-block;
            padding: 2px 10px;
            border: 1px solid #999;
            border-radius: 3px;
            font-size: 9px;
            color: #666;
            font-family: 'Helvetica', sans-serif;
        }

        /* Content sections */
        .content-heading-1 {
            font-size: 16px;
            font-weight: bold;
            text-align: center;
            text-transform: uppercase;
            margin-top: 24px;
            margin-bottom: 12px;
        }
        .content-heading-2 {
            font-size: 14px;
            font-weight: bold;
            margin-top: 20px;
            margin-bottom: 8px;
        }
        .content-heading-3 {
            font-size: 12px;
            font-weight: bold;
            margin-top: 16px;
            margin-bottom: 6px;
        }
        .content-paragraph {
            margin-bottom: 12px;
            text-align: justify;
            text-indent: 40px;
        }
        .content-paragraph-no-indent {
            margin-bottom: 12px;
            text-align: justify;
        }
        .content-list-item {
            margin-left: 60px;
            margin-bottom: 6px;
            text-indent: -20px;
            padding-left: 20px;
        }
        .content-list-item::before {
            content: "\2022\00a0\00a0";
        }

        /* Signature block */
        .signature-block {
            margin-top: 60px;
        }
        .signature-line {
            margin-top: 40px;
            width: 300px;
        }
        .signature-line-rule {
            border-top: 1px solid #333;
            padding-top: 4px;
            font-size: 11px;
        }

        .footer {
            margin-top: 50px;
            text-align: center;
            color: #999;
            font-size: 9px;
            font-family: 'Helvetica', sans-serif;
        }
    </style>
</head>
<body>
    <div class="container">
        {{-- Corporation Header --}}
        @php
            $corp = $document->corporation;
        @endphp
        @if ($corp)
            <div class="corp-header">
                <div class="corp-name">{{ $corp->name }}</div>
                <div class="corp-details">
                    @if ($corp->entity_type)
                        {{ App\Models\Corporation::ENTITY_TYPES[$corp->entity_type] ?? $corp->entity_type }}
                    @endif
                    @if ($corp->state_of_formation)
                        &bull; {{ $corp->state_of_formation }}
                    @endif
                    @if ($corp->ein)
                        &bull; EIN: {{ $corp->ein }}
                    @endif
                    @if ($corp->address_line_1)
                        <br>{{ $corp->address_line_1 }}@if ($corp->city || $corp->state), {{ collect([$corp->city, $corp->state])->filter()->implode(', ') }} {{ $corp->zip }}@endif
                    @endif
                </div>
            </div>
        @endif

        {{-- Document Title --}}
        <div class="doc-title">{{ $document->title }}</div>
        @if ($document->type && isset(App\Models\CorporateDocument::TYPES[$document->type]))
            <div class="doc-subtitle">{{ App\Models\CorporateDocument::TYPES[$document->type] }}</div>
        @endif

        @if ($document->version > 1)
            <div class="version-badge"><span>Version {{ $document->version }}</span></div>
        @endif

        {{-- Document Content --}}
        @php
            $sections = $document->getContentForExport();
        @endphp

        @if ($sections)
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
        @else
            <p style="color: #999; font-style: italic; text-align: center; margin-top: 40px;">
                No content available for this document.
            </p>
        @endif

        {{-- Footer --}}
        <div class="footer">
            <p>Generated on {{ now()->format('F j, Y') }}</p>
        </div>
    </div>
</body>
</html>
