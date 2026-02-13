<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Form W-9 - {{ $w9->name }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 8pt; line-height: 1.2; color: #000; }
        .page { width: 100%; padding: 20px 30px; }

        /* Header row: 3 columns */
        .header { width: 100%; border-bottom: 2px solid #000; border-collapse: collapse; }
        .header td { vertical-align: top; padding: 6px 8px; }
        .header-left { width: 35%; border-right: 1px solid #000; }
        .header-center { width: 40%; text-align: center; }
        .header-right { width: 25%; border-left: 1px solid #000; text-align: right; }
        .header-left .form-title { font-size: 20pt; font-weight: bold; line-height: 1.0; }
        .header-left .dept { font-size: 6.5pt; color: #000; margin-top: 1px; }
        .header-left .rev { font-size: 7pt; color: #000; margin-top: 2px; }
        .header-left .url { font-size: 6.5pt; margin-top: 1px; }
        .header-center .main-title { font-size: 11pt; font-weight: bold; margin-bottom: 4px; }
        .header-center .sub-instructions { font-size: 7pt; }
        .header-right .give-form { font-size: 8pt; line-height: 1.4; }
        .header-right .omb { font-size: 7pt; margin-top: 8px; }

        /* Form body: 2-column table, left = lines, right = line 3/4 */
        .form-body { width: 100%; border-collapse: collapse; }
        .form-body td { vertical-align: top; }
        .col-left { width: 53%; }
        .col-right { width: 47%; border-left: 1px solid #000; }

        /* Line rows */
        .line { border-bottom: 1px solid #000; padding: 3px 8px; min-height: 28px; }
        .line-label { font-size: 6.5pt; color: #000; }
        .line-label .line-num { font-weight: bold; }
        .line-value { font-size: 10pt; min-height: 14px; padding-top: 1px; }

        /* Right column sections */
        .right-section { border-bottom: 1px solid #000; padding: 3px 8px; }
        .right-inner-label { font-size: 6.5pt; color: #000; }

        /* Tax classification (line 3a) */
        .checkbox { display: inline-block; width: 10px; height: 10px; border: 1px solid #000; text-align: center; font-size: 7pt; line-height: 10px; margin-right: 2px; vertical-align: middle; }
        .checkbox.checked { background-color: #000; color: #fff; }
        .tax-row { font-size: 7.5pt; margin-bottom: 2px; white-space: nowrap; }
        .tax-row-wrap { font-size: 7.5pt; margin-bottom: 2px; }

        /* Part I: TIN */
        .part-header { font-size: 9pt; font-weight: bold; padding: 4px 8px; background: #000; color: #fff; }
        .part-header span { font-weight: normal; font-size: 8pt; }
        .tin-body { padding: 6px 8px; border: 1px solid #000; border-top: none; }
        .tin-label { font-size: 7pt; margin-bottom: 4px; line-height: 1.3; }
        .tin-boxes { margin-top: 6px; }
        .tin-digit { display: inline-block; width: 16px; height: 18px; border: 1px solid #000; text-align: center; font-size: 11pt; font-weight: bold; line-height: 18px; margin-right: 0px; }
        .tin-dash { display: inline-block; width: 8px; text-align: center; font-size: 11pt; font-weight: bold; line-height: 18px; }
        .tin-type-row { margin-bottom: 5px; font-size: 7.5pt; }

        /* Part II: Certification */
        .cert-body { padding: 6px 8px; border: 1px solid #000; border-top: none; }
        .cert-text { font-size: 7pt; line-height: 1.4; }
        .cert-text p { margin-bottom: 3px; }
        .cert-instruction { font-size: 6.5pt; font-style: italic; margin-top: 4px; }

        /* Sign Here */
        .sign-header { font-size: 9pt; font-weight: bold; padding: 4px 8px; background: #000; color: #fff; }
        .sig-body { border: 1px solid #000; border-top: none; }
        .sig-table { width: 100%; border-collapse: collapse; }
        .sig-table td { padding: 4px 8px; vertical-align: bottom; }
        .sig-table .sig-cell { border-right: 1px solid #000; }
        .sig-label { font-size: 6.5pt; color: #000; }
        .sig-value { font-size: 10pt; border-bottom: 1px solid #000; min-height: 22px; padding-bottom: 2px; }
        .sig-typed { font-family: 'Brush Script MT', 'Segoe Script', cursive; font-size: 16pt; }

        /* General Instructions */
        .general-instructions { margin-top: 8px; border-top: 2px solid #000; padding-top: 6px; }
        .gi-title { font-size: 9pt; font-weight: bold; margin-bottom: 3px; }
        .gi-text { font-size: 6.5pt; line-height: 1.3; }
        .gi-text p { margin-bottom: 3px; }

        /* Footer */
        .page-footer { margin-top: 6px; font-size: 6pt; text-align: center; border-top: 1px solid #ccc; padding-top: 4px; }
        .cat-no { font-size: 6.5pt; }

        /* Electronic signature notice */
        .e-sig-notice { margin-top: 4px; font-size: 6.5pt; color: #666; text-align: center; border: 1px dashed #ccc; padding: 3px; }
    </style>
</head>
<body>
<div class="page">
    {{-- ====== HEADER: 3-column layout ====== --}}
    <table class="header">
        <tr>
            <td class="header-left">
                <div class="form-title">Form <span style="font-size: 24pt;">W-9</span></div>
                <div class="rev">(Rev. March 2024)</div>
                <div class="dept">Department of the Treasury<br>Internal Revenue Service</div>
            </td>
            <td class="header-center">
                <div class="main-title">Request for Taxpayer<br>Identification Number and Certification</div>
                <div class="sub-instructions">
                    &#9654; Go to <em>www.irs.gov/FormW9</em> for instructions and the latest information.
                </div>
            </td>
            <td class="header-right">
                <div class="give-form">Give Form to the<br>requester. Do not<br>send to the IRS.</div>
            </td>
        </tr>
    </table>

    {{-- ====== FORM BODY: 2-column layout ====== --}}
    <table class="form-body">
        <tr>
            {{-- LEFT COLUMN: Lines 1, 2, 5, 6, 7, Requester --}}
            <td class="col-left">
                {{-- Line 1 --}}
                <div class="line">
                    <div class="line-label">
                        <span class="line-num">1</span> Name (as shown on your income tax return). Name is required on this line; do not leave this line blank.
                    </div>
                    <div class="line-value">{{ $w9->name }}</div>
                </div>

                {{-- Line 2 --}}
                <div class="line">
                    <div class="line-label">
                        <span class="line-num">2</span> Business name/disregarded entity name, if different from above
                    </div>
                    <div class="line-value">{{ $w9->business_name ?? '' }}</div>
                </div>

                {{-- Line 5 --}}
                <div class="line">
                    <div class="line-label">
                        <span class="line-num">5</span> Address (number, street, and apt. or suite no.) See instructions.
                    </div>
                    <div class="line-value">{{ $w9->address }}</div>
                </div>

                {{-- Line 6 --}}
                <div class="line">
                    <div class="line-label">
                        <span class="line-num">6</span> City, state, and ZIP code
                    </div>
                    <div class="line-value">{{ $w9->city_state_zip }}</div>
                </div>

                {{-- Line 7 --}}
                <div class="line" style="border-bottom: 2px solid #000;">
                    <div class="line-label">
                        <span class="line-num">7</span> List account number(s) here (optional)
                    </div>
                    <div class="line-value">{{ $w9->account_numbers ?? '' }}</div>
                </div>
            </td>

            {{-- RIGHT COLUMN: Lines 3a, 3b, 4, Requester --}}
            <td class="col-right">
                {{-- Line 3a --}}
                <div class="right-section">
                    <div class="right-inner-label">
                        <span style="font-weight: bold;">3a</span> Check appropriate box for federal tax classification of the person whose name is entered on line 1. Check only <strong>one</strong> of the following seven boxes.
                    </div>
                    <div style="margin-top: 4px;">
                        @php
                            $tc = $w9->tax_classification;
                        @endphp
                        <div class="tax-row">
                            <span class="checkbox {{ $tc === 'individual' ? 'checked' : '' }}">{{ $tc === 'individual' ? '&#10003;' : '&nbsp;' }}</span>
                            Individual/sole proprietor or single-member LLC
                            &nbsp;&nbsp;
                            <span class="checkbox {{ $tc === 'c_corp' ? 'checked' : '' }}">{{ $tc === 'c_corp' ? '&#10003;' : '&nbsp;' }}</span>
                            C Corporation
                            &nbsp;&nbsp;
                            <span class="checkbox {{ $tc === 's_corp' ? 'checked' : '' }}">{{ $tc === 's_corp' ? '&#10003;' : '&nbsp;' }}</span>
                            S Corporation
                        </div>
                        <div class="tax-row">
                            <span class="checkbox {{ $tc === 'partnership' ? 'checked' : '' }}">{{ $tc === 'partnership' ? '&#10003;' : '&nbsp;' }}</span>
                            Partnership
                            &nbsp;&nbsp;
                            <span class="checkbox {{ $tc === 'trust' ? 'checked' : '' }}">{{ $tc === 'trust' ? '&#10003;' : '&nbsp;' }}</span>
                            Trust/estate
                        </div>
                        <div class="tax-row-wrap">
                            <span class="checkbox {{ $tc === 'llc' ? 'checked' : '' }}">{{ $tc === 'llc' ? '&#10003;' : '&nbsp;' }}</span>
                            Limited liability company. Enter the tax classification (C=C corporation, S=S corporation, P=Partnership) &#9654;
                            <span style="font-weight: bold; text-decoration: underline; padding: 0 8px;">{{ $w9->llc_tax_classification ? strtoupper($w9->llc_tax_classification) : '&nbsp;&nbsp;' }}</span>
                        </div>
                        <div style="font-size: 6.5pt; margin-left: 15px; margin-bottom: 2px;">
                            <strong>Note:</strong> Check the appropriate box in the line above for the tax classification of the single-member owner.
                            Do not check LLC if the LLC is classified as a single-member LLC that is disregarded from the owner unless the owner of the LLC is
                            another LLC that is not disregarded from the owner for U.S. federal tax purposes. Otherwise, a single-member LLC that
                            is disregarded from the owner should check the appropriate box for the tax classification of its owner.
                        </div>
                        <div class="tax-row">
                            <span class="checkbox {{ $tc === 'other' ? 'checked' : '' }}">{{ $tc === 'other' ? '&#10003;' : '&nbsp;' }}</span>
                            Other (see instructions) &#9654;
                            <span style="text-decoration: underline; padding: 0 8px;">{{ $w9->other_classification ?? '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;' }}</span>
                        </div>
                    </div>
                </div>

                {{-- Line 3b --}}
                <div class="right-section">
                    <div class="right-inner-label">
                        <span style="font-weight: bold;">3b</span> If on line 3a you checked &ldquo;Partnership&rdquo; or &ldquo;Trust/estate,&rdquo; or checked &ldquo;LLC&rdquo;
                        and entered &ldquo;P&rdquo; as its tax classification, and you are providing this form to a partnership, trust, or estate in which
                        you have an ownership interest, check this box if you have any foreign partners, owners, or beneficiaries. See instructions
                        &nbsp;
                        <span class="checkbox">{{ '' }}</span>
                    </div>
                </div>

                {{-- Line 4: Exemptions --}}
                <div class="right-section" style="border-bottom: none;">
                    <div class="right-inner-label" style="margin-bottom: 2px;">
                        <span style="font-weight: bold;">4</span> Exemptions (codes apply only to certain entities, not individuals; see instructions on page 3):
                    </div>
                    <div style="font-size: 7pt; padding-left: 8px;">
                        <div style="margin-bottom: 3px;">
                            Exempt payee code (if any) <span style="text-decoration: underline; font-weight: bold; padding: 0 5px;">{{ $w9->exempt_payee_code ?? '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;' }}</span>
                        </div>
                        <div>
                            Exemption from FATCA reporting<br>code (if any) <span style="text-decoration: underline; font-weight: bold; padding: 0 5px;">{{ $w9->fatca_exemption_code ?? '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;' }}</span>
                        </div>
                        <div style="font-size: 6pt; font-style: italic; margin-top: 3px;">
                            (Applies to accounts maintained outside the U.S.)
                        </div>
                    </div>
                </div>

                {{-- Requester's name and address --}}
                <div class="right-section" style="border-bottom: 2px solid #000; min-height: 36px;">
                    <div class="right-inner-label">Requester's name and address (optional)</div>
                    <div style="font-size: 8pt; padding-top: 2px;">{{ $settings->company_name }}</div>
                </div>
            </td>
        </tr>
    </table>

    {{-- ====== PART I: Taxpayer Identification Number (TIN) ====== --}}
    <div class="part-header">Part I <span>Taxpayer Identification Number (TIN)</span></div>
    <div class="tin-body">
        <div class="tin-label">
            Enter your TIN in the appropriate box. The TIN provided must match the name given on line 1 to avoid
            backup withholding. For individuals, this is generally your social security number (SSN). However, for a
            resident alien, sole proprietor, or disregarded entity, see the instructions for Part I, later. For other
            entities, it is your employer identification number (EIN). If you do not have a number, see <em>How to get a
            TIN</em>, later.
        </div>
        <div style="margin-top: 4px; font-size: 6.5pt; line-height: 1.3;">
            <strong>Note:</strong> If the account is in more than one name, see the instructions for line 1. Also see <em>What Name and
            Number To Give the Requester</em> for guidelines on whose number to enter.
        </div>

        <table style="width: 100%; margin-top: 8px; border-collapse: collapse;">
            <tr>
                <td style="width: 50%; vertical-align: top;">
                    <div class="tin-type-row">
                        <span class="checkbox {{ $w9->tin_type === 'ssn' ? 'checked' : '' }}">{{ $w9->tin_type === 'ssn' ? '&#10003;' : '&nbsp;' }}</span>
                        <strong>Social security number</strong>
                    </div>
                    @if ($w9->tin_type === 'ssn')
                        @php
                            $ssnDigits = str_pad(preg_replace('/\D/', '', $w9->tin ?? ''), 9, '0', STR_PAD_LEFT);
                        @endphp
                        <div class="tin-boxes">
                            {{-- SSN format: XXX-XX-XXXX (full, unmasked) --}}
                            <span class="tin-digit">{{ $ssnDigits[0] }}</span><span class="tin-digit">{{ $ssnDigits[1] }}</span><span class="tin-digit">{{ $ssnDigits[2] }}</span>
                            <span class="tin-dash">&ndash;</span>
                            <span class="tin-digit">{{ $ssnDigits[3] }}</span><span class="tin-digit">{{ $ssnDigits[4] }}</span>
                            <span class="tin-dash">&ndash;</span>
                            <span class="tin-digit">{{ $ssnDigits[5] }}</span><span class="tin-digit">{{ $ssnDigits[6] }}</span><span class="tin-digit">{{ $ssnDigits[7] }}</span><span class="tin-digit">{{ $ssnDigits[8] }}</span>
                        </div>
                    @endif
                </td>
                <td style="width: 50%; vertical-align: top; padding-left: 10px;">
                    <div class="tin-type-row">
                        <span class="checkbox {{ $w9->tin_type === 'ein' ? 'checked' : '' }}">{{ $w9->tin_type === 'ein' ? '&#10003;' : '&nbsp;' }}</span>
                        <strong>Employer identification number</strong>
                    </div>
                    @if ($w9->tin_type === 'ein')
                        @php
                            $einDigits = str_pad(preg_replace('/\D/', '', $w9->tin ?? ''), 9, '0', STR_PAD_LEFT);
                        @endphp
                        <div class="tin-boxes">
                            {{-- EIN format: XX-XXXXXXX (full, unmasked) --}}
                            <span class="tin-digit">{{ $einDigits[0] }}</span><span class="tin-digit">{{ $einDigits[1] }}</span>
                            <span class="tin-dash">&ndash;</span>
                            <span class="tin-digit">{{ $einDigits[2] }}</span><span class="tin-digit">{{ $einDigits[3] }}</span><span class="tin-digit">{{ $einDigits[4] }}</span><span class="tin-digit">{{ $einDigits[5] }}</span><span class="tin-digit">{{ $einDigits[6] }}</span><span class="tin-digit">{{ $einDigits[7] }}</span><span class="tin-digit">{{ $einDigits[8] }}</span>
                        </div>
                    @endif
                </td>
            </tr>
        </table>
    </div>

    {{-- ====== PART II: Certification ====== --}}
    <div class="part-header">Part II <span>Certification</span></div>
    <div class="cert-body">
        <div class="cert-text">
            <p>Under penalties of perjury, I certify that:</p>
            <p>1. The number shown on this form is my correct taxpayer identification number (or I am waiting for a number to be issued to me); and</p>
            <p>2. I am not subject to backup withholding because: (a) I am exempt from backup withholding, or (b) I have not been notified by the Internal
            Revenue Service (IRS) that I am subject to backup withholding as a result of a failure to report all interest or dividends, or (c) the IRS has notified me that I am
            no longer subject to backup withholding; and</p>
            <p>3. I am a U.S. citizen or other U.S. person (defined below); and</p>
            <p>4. The FATCA code(s) entered on this form (if any) indicating that I am exempt from FATCA reporting is correct.</p>
        </div>
        <div class="cert-instruction">
            <strong>Certification instructions.</strong> You must cross out item 2 above if you have been notified by the IRS that you are currently subject to backup withholding because
            you have failed to report all interest and dividends on your tax return. For real estate transactions, item 2 does not apply. For mortgage interest paid,
            acquisition or abandonment of secured property, cancellation of debt, contributions to an individual retirement arrangement (IRA), and generally, payments
            other than interest and dividends, you are not required to sign the certification, but you must provide your correct TIN. See the instructions for Part II, later.
        </div>
    </div>

    {{-- ====== SIGN HERE ====== --}}
    <div class="sign-header">Sign Here</div>
    <div class="sig-body">
        <table class="sig-table">
            <tr>
                <td style="width: 60%;" class="sig-cell">
                    <div class="sig-label">Signature of U.S. person &#9654;</div>
                    <div class="sig-value">
                        @if ($w9->signature_type === 'drawn' && str_starts_with($w9->signature_data, 'data:image'))
                            <img src="{{ $w9->signature_data }}" style="height: 28px; max-width: 180px;" />
                        @else
                            <span class="sig-typed">{{ $w9->signature_data }}</span>
                        @endif
                    </div>
                </td>
                <td style="width: 40%;">
                    <div class="sig-label">Date &#9654;</div>
                    <div class="sig-value">{{ $w9->signed_at?->format('m/d/Y') }}</div>
                </td>
            </tr>
        </table>
    </div>

    {{-- ====== GENERAL INSTRUCTIONS ====== --}}
    <div class="general-instructions">
        <div class="gi-title">General Instructions</div>
        <div class="gi-text">
            <p>Section references are to the Internal Revenue Code unless otherwise noted.</p>
            <p><strong>Future developments.</strong> For the latest information about developments related to Form W-9 and its instructions, such as legislation enacted
            after they were published, go to <em>www.irs.gov/FormW9</em>.</p>
            <p><strong>Purpose of Form.</strong> An individual or entity (Form W-9 requester) who is required to file an information return with the IRS must obtain your correct taxpayer
            identification number (TIN) which may be your social security number (SSN), individual taxpayer identification number (ITIN), adoption taxpayer identification
            number (ATIN), or employer identification number (EIN), to report on an information return the amount paid to you, or other amount reportable on an
            information return.</p>
        </div>
    </div>

    {{-- ====== FOOTER ====== --}}
    <div style="margin-top: 6px; font-size: 6.5pt; display: flex; justify-content: space-between;">
        <span>Cat. No. 10231X</span>
        <span style="text-align: center; flex: 1;">Form <strong>W-9</strong> (Rev. 3-2024)</span>
    </div>

    {{-- Electronic signature notice --}}
    <div class="e-sig-notice">
        Signed electronically on {{ $w9->signed_at?->format('F j, Y \a\t g:i A') }}
        @if ($w9->ip_address) &nbsp;|&nbsp; IP: {{ $w9->ip_address }} @endif
        &nbsp;|&nbsp; Generated {{ now()->format('M j, Y') }} by {{ $settings->company_name }}
    </div>
</div>
</body>
</html>
