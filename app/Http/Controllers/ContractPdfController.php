<?php

namespace App\Http\Controllers;

use App\Models\CompanySetting;
use App\Models\ContractTemplate;
use App\Models\EmployeeContract;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContractPdfController extends Controller
{
    public function download(EmployeeContract $employeeContract): Response|StreamedResponse
    {
        abort_unless(auth()->user()?->can('employees.view'), 403);

        $filename = 'contract-'.$employeeContract->id.'-'.Str::slug($employeeContract->title).'.pdf';

        if ($employeeContract->pdf_path && Storage::exists($employeeContract->pdf_path)) {
            return Storage::download($employeeContract->pdf_path, $filename);
        }

        $employeeContract->load(['employeeProfile.user', 'template', 'countersignedBy']);

        $settings = CompanySetting::getInstance();

        $pdf = Pdf::loadView('pdf.employee-contract', [
            'contract' => $employeeContract,
            'settings' => $settings,
        ]);

        return $pdf->download($filename);
    }

    public function publicDownload(string $token): Response|StreamedResponse
    {
        $contract = EmployeeContract::where('token', $token)->firstOrFail();
        $contract->load('employeeProfile');

        $this->authorizeContractAccess($contract);
        abort_unless($contract->isSigned(), 403);

        $filename = 'contract-'.$contract->id.'-'.Str::slug($contract->title).'.pdf';

        if ($contract->pdf_path && Storage::exists($contract->pdf_path)) {
            return Storage::download($contract->pdf_path, $filename);
        }

        $contract->load(['employeeProfile.user', 'template', 'countersignedBy']);

        $settings = CompanySetting::getInstance();

        $pdf = Pdf::loadView('pdf.employee-contract', [
            'contract' => $contract,
            'settings' => $settings,
        ]);

        return $pdf->download($filename);
    }

    public function viewDocument(EmployeeContract $employeeContract): StreamedResponse
    {
        abort_unless(auth()->user()?->can('employees.view'), 403);
        abort_unless($employeeContract->pdf_path && Storage::exists($employeeContract->pdf_path), 404);

        return Storage::response($employeeContract->pdf_path, null, [
            'Content-Disposition' => 'inline',
        ]);
    }

    public function publicViewDocument(string $token): StreamedResponse
    {
        $contract = EmployeeContract::where('token', $token)->firstOrFail();
        $contract->load('employeeProfile');

        $this->authorizeContractAccess($contract);
        abort_unless($contract->pdf_path && Storage::exists($contract->pdf_path), 404);

        return Storage::response($contract->pdf_path, null, [
            'Content-Disposition' => 'inline',
        ]);
    }

    /**
     * Serve the template PDF for the authenticated field placer UI.
     */
    public function templatePdfPreview(ContractTemplate $contractTemplate): StreamedResponse
    {
        abort_unless(auth()->user()?->can('settings.contracts'), 403);
        abort_unless(
            $contractTemplate->content_type === 'pdf'
            && $contractTemplate->pdf_path
            && Storage::exists($contractTemplate->pdf_path),
            404,
        );

        return Storage::response($contractTemplate->pdf_path, null, [
            'Content-Disposition' => 'inline',
        ]);
    }

    /**
     * Serve the template PDF for the signing page (authenticated, owner-only).
     */
    public function publicTemplatePdf(string $token): StreamedResponse
    {
        $contract = EmployeeContract::where('token', $token)->firstOrFail();
        $contract->load('employeeProfile');

        $this->authorizeContractAccess($contract);

        $template = $contract->template;

        abort_unless(
            $template
            && $template->content_type === 'pdf'
            && $template->pdf_path
            && Storage::exists($template->pdf_path),
            404,
        );

        return Storage::response($template->pdf_path, null, [
            'Content-Disposition' => 'inline',
        ]);
    }

    /**
     * Serve the template PDF for the assign modal employer signing.
     */
    public function templatePdfForAssign(ContractTemplate $contractTemplate): StreamedResponse
    {
        abort_unless(auth()->user()?->can('employees.edit'), 403);
        abort_unless(
            $contractTemplate->content_type === 'pdf'
            && $contractTemplate->pdf_path
            && Storage::exists($contractTemplate->pdf_path),
            404,
        );

        return Storage::response($contractTemplate->pdf_path, null, [
            'Content-Disposition' => 'inline',
        ]);
    }

    /**
     * Verify the authenticated user owns the contract or has admin permission.
     */
    private function authorizeContractAccess(EmployeeContract $contract): void
    {
        $isOwner = auth()->id() === $contract->employeeProfile?->user_id;
        $isAdmin = auth()->user()?->can('employees.view');
        abort_unless($isOwner || $isAdmin, 403);
    }
}
