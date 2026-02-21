<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Painting Made Easy - Marketing Flyer</title>
    <style>
        @page {
            size: letter;
            margin: 1in 0.75in;
        }

        * {
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'DejaVu Sans', Helvetica, Arial, sans-serif;
            color: #000;
            text-align: center;
            padding: 0.25in 0;
        }

        .logo-box {
            border: 3px solid #000;
            display: inline-block;
            padding: 12px 30px;
            margin-bottom: 16px;
        }

        .logo-box span {
            font-size: 42px;
            font-weight: bold;
            letter-spacing: 10px;
        }

        .company-name {
            font-size: 22px;
            font-weight: bold;
            letter-spacing: 5px;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .company-location {
            font-size: 14px;
            font-weight: bold;
            letter-spacing: 4px;
            text-transform: uppercase;
            margin-bottom: 44px;
        }

        .tagline {
            font-size: 32px;
            font-weight: bold;
            line-height: 1.3;
            margin-bottom: 10px;
        }

        .tagline-sub {
            font-size: 18px;
            font-style: italic;
            margin-bottom: 34px;
        }

        .divider {
            border: none;
            border-top: 2px solid #000;
            margin: 26px 0;
        }

        .selling-points {
            width: 100%;
            margin: 14px 0;
        }

        .selling-points td {
            padding: 10px 0;
            font-size: 18px;
            vertical-align: middle;
        }

        .selling-points .check-col {
            width: 40px;
            font-size: 20px;
            font-weight: bold;
            text-align: center;
        }

        .selling-points .text-col {
            text-align: left;
            padding-left: 8px;
        }

        .package-line {
            font-size: 22px;
            font-weight: bold;
            letter-spacing: 1px;
            margin-top: 14px;
        }

        .cta-table {
            margin: 16px auto;
        }

        .cta-table td {
            vertical-align: middle;
        }

        .qr-cell {
            width: 160px;
            text-align: center;
        }

        .qr-cell img {
            width: 150px;
            height: 150px;
        }

        .cta-info {
            text-align: left;
            padding-left: 28px;
        }

        .cta-info .label {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 4px;
        }

        .cta-info .or-text {
            font-size: 15px;
            margin-bottom: 4px;
        }

        .cta-info .phone {
            font-size: 28px;
            font-weight: bold;
            letter-spacing: 1px;
            margin-bottom: 4px;
        }

        .cta-info .url {
            font-size: 14px;
        }

        .trust-bar {
            border-top: 2px solid #000;
            padding-top: 16px;
            margin-top: 28px;
            font-size: 13px;
            font-weight: bold;
            letter-spacing: 2px;
            text-transform: uppercase;
        }
    </style>
</head>
<body>

    <div class="logo-box">
        <span>PME</span>
    </div>

    <div class="company-name">Painting Made Easy</div>
    <div class="company-location">Denver</div>

    <div class="tagline">Your Home. Beautifully<br>Transformed. Guaranteed.</div>
    <div class="tagline-sub">Professional painting without the hassle.</div>

    <hr class="divider">

    <table class="selling-points">
        <tr>
            <td class="check-col">&#x2713;</td>
            <td class="text-col">Virtual or In-Person Estimates</td>
        </tr>
        <tr>
            <td class="check-col">&#x2713;</td>
            <td class="text-col">Manage Your Paint Job From Your Phone</td>
        </tr>
        <tr>
            <td class="check-col">&#x2713;</td>
            <td class="text-col">Stay Informed Every Step</td>
        </tr>
        <tr>
            <td class="check-col">&#x2713;</td>
            <td class="text-col">Quality Work From Professionals Who Care</td>
        </tr>
        <tr>
            <td class="check-col">&#x2713;</td>
            <td class="text-col">&ldquo;Cleaner Than We Found It&rdquo; Guarantee</td>
        </tr>
    </table>

    <div class="package-line">All In One Package.</div>

    <hr class="divider">

    <table class="cta-table">
        <tr>
            <td class="qr-cell">
                <img src="{{ $qrCodeDataUri }}" alt="QR Code">
            </td>
            <td class="cta-info">
                <div class="label">Scan to visit us</div>
                <div class="or-text">or call:</div>
                <div class="phone">(757) 343-3032</div>
                <div class="url">paintingmadeeasydenver.com</div>
            </td>
        </tr>
    </table>

    <div class="trust-bar">
        Licensed &amp; Insured &nbsp; &#x2022; &nbsp; 5-Star Rated &nbsp; &#x2022; &nbsp; Same-Day Estimates
    </div>

</body>
</html>
