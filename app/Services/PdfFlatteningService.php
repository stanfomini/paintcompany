<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use setasign\Fpdi\Tcpdf\Fpdi;

class PdfFlatteningService
{
    /**
     * Flatten a PDF template with field values written at specified positions.
     *
     * Downloads the template from the default storage disk (which may be S3),
     * processes locally with FPDI, uploads the result back to the default disk,
     * and cleans up temporary files.
     *
     * @param  string  $templatePdfPath  Path on the default storage disk
     * @param  list<array{id: string, type: string, label: string, page: int, x_pct: float, y_pct: float, width_pct: float, height_pct: float, font_size?: int}>  $fields
     * @param  array<string, string>  $fieldValues  Keyed by field ID
     * @param  array<string, array{width: int|float, height: int|float}>  $pages  Page dimensions keyed by page number
     * @return string Path to the flattened PDF on the default disk
     */
    public function flatten(string $templatePdfPath, array $fields, array $fieldValues, array $pages): string
    {
        $disk = Storage::disk();

        // Download template PDF to a temp file for FPDI processing
        $tempTemplate = tempnam(sys_get_temp_dir(), 'tpl_');
        file_put_contents($tempTemplate, $disk->get($templatePdfPath));

        try {
            $pdf = new Fpdi('P', 'pt');
            $pdf->SetAutoPageBreak(false, 0);
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);

            $pageCount = $pdf->setSourceFile($tempTemplate);

            // Import all pages from the source PDF
            $importedPages = [];
            for ($i = 1; $i <= $pageCount; $i++) {
                $importedPages[$i] = $pdf->importPage($i);
            }

            // Add each page and overlay field values
            for ($pageNum = 1; $pageNum <= $pageCount; $pageNum++) {
                $templateId = $importedPages[$pageNum];
                $size = $pdf->getTemplateSize($templateId);

                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($templateId);

                $pageWidth = $size['width'];
                $pageHeight = $size['height'];

                // Get fields for this page
                $pageFields = array_filter($fields, fn (array $field) => ($field['page'] ?? 1) === $pageNum);

                foreach ($pageFields as $field) {
                    $fieldId = $field['id'] ?? '';
                    $value = $fieldValues[$fieldId] ?? '';

                    if ($value === '' && $field['type'] !== 'checkbox') {
                        continue;
                    }

                    $x = ($field['x_pct'] / 100) * $pageWidth;
                    $y = ($field['y_pct'] / 100) * $pageHeight;
                    $w = ($field['width_pct'] / 100) * $pageWidth;
                    $h = ($field['height_pct'] / 100) * $pageHeight;
                    $fontSize = $field['font_size'] ?? 12;

                    match ($field['type']) {
                        'signature', 'initials' => $this->placeSignature($pdf, $value, $x, $y, $w, $h, $fontSize),
                        'checkbox' => $this->placeCheckbox($pdf, $value, $x, $y, $w, $h, $fontSize),
                        default => $this->placeText($pdf, $value, $x, $y, $w, $h, $fontSize),
                    };
                }
            }

            // Generate the flattened PDF content and upload to default disk
            $outputFilename = 'signed-contracts/'.Str::uuid().'.pdf';
            $pdfContent = $pdf->Output('', 'S');
            $disk->put($outputFilename, $pdfContent);

            return $outputFilename;
        } finally {
            @unlink($tempTemplate);
        }
    }

    /**
     * Place text at the specified position.
     */
    private function placeText(Fpdi $pdf, string $text, float $x, float $y, float $w, float $h, int $fontSize): void
    {
        $pdf->SetFont('helvetica', '', $fontSize);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetXY($x, $y);
        $pdf->Cell($w, $h, $text, 0, 0, 'L');
    }

    /**
     * Place a signature — either as an image (base64/data URI) or as italic text (typed).
     */
    private function placeSignature(Fpdi $pdf, string $value, float $x, float $y, float $w, float $h, int $fontSize): void
    {
        if (str_starts_with($value, 'data:') || preg_match('/^[A-Za-z0-9+\/=]{20,}$/', $value)) {
            $this->placeSignatureImage($pdf, $value, $x, $y, $w, $h);
        } else {
            $pdf->SetFont('helvetica', 'I', $fontSize);
            $pdf->SetTextColor(0, 0, 100);
            $pdf->SetXY($x, $y);
            $pdf->Cell($w, $h, $value, 0, 0, 'L');
        }
    }

    /**
     * Place a signature image (base64-encoded PNG/data URI) at the specified position.
     */
    private function placeSignatureImage(Fpdi $pdf, string $signatureData, float $x, float $y, float $w, float $h): void
    {
        // Handle data URI format: data:image/png;base64,xxxx
        $imageData = $signatureData;
        if (str_starts_with($signatureData, 'data:')) {
            $parts = explode(',', $signatureData, 2);
            $imageData = $parts[1] ?? '';
        }

        $decoded = base64_decode($imageData, true);
        if ($decoded === false || strlen($decoded) === 0) {
            return;
        }

        // Write to a temporary file for TCPDF
        $tmpFile = tempnam(sys_get_temp_dir(), 'sig_');
        file_put_contents($tmpFile, $decoded);

        try {
            $pdf->Image($tmpFile, $x, $y, $w, $h, '', '', '', false, 300, '', false, false, 0, 'CM');
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * Place a checkbox mark at the specified position.
     */
    private function placeCheckbox(Fpdi $pdf, string $value, float $x, float $y, float $w, float $h, int $fontSize): void
    {
        $checked = in_array(strtolower($value), ['true', '1', 'yes', 'on', 'checked'], true);

        if ($checked) {
            $pdf->SetFont('zapfdingbats', '', $fontSize);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetXY($x, $y);
            // ZapfDingbats checkmark character
            $pdf->Cell($w, $h, '4', 0, 0, 'C');
        }
    }
}
