<?php

namespace App\Services;

use App\Models\ContractTemplate;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorContract;
use App\Models\VendorContractSigner;
use App\Models\VendorContractVendorSignature;
use App\Models\VendorDocument;
use App\Notifications\VendorContractFullyExecuted;
use App\Notifications\VendorContractInternalSigningRequest;
use App\Notifications\VendorContractSentToVendor;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class VendorContractService
{
    // ─── Creation & Editing ───

    /**
     * Create a blank vendor contract.
     */
    public function createBlank(Vendor $vendor, User $createdBy): VendorContract
    {
        $contract = VendorContract::create([
            'vendor_id' => $vendor->id,
            'title' => 'New Contract — '.$vendor->name,
            'content' => '',
            'status' => VendorContract::STATUS_DRAFT,
            'is_valid' => true,
            'created_by' => $createdBy->id,
        ]);

        VendorContractSigner::create([
            'vendor_contract_id' => $contract->id,
            'user_id' => $createdBy->id,
            'order' => 0,
        ]);

        return $contract->load('signers');
    }

    /**
     * Create a new vendor contract from a template.
     *
     * @param  array<int>  $signerUserIds
     */
    public function createFromTemplate(
        Vendor $vendor,
        ContractTemplate $template,
        array $signerUserIds,
        User $createdBy,
    ): VendorContract {
        $content = $this->resolveVendorMergeTags($template->content ?? '', $vendor);

        $contract = VendorContract::create([
            'vendor_id' => $vendor->id,
            'template_id' => $template->id,
            'title' => $template->name,
            'content' => $content,
            'status' => VendorContract::STATUS_DRAFT,
            'is_valid' => true,
            'created_by' => $createdBy->id,
        ]);

        $this->syncSigners($contract, $signerUserIds);

        return $contract;
    }

    /**
     * Replace vendor-specific merge tags in content.
     */
    public function resolveVendorMergeTags(string $content, Vendor $vendor): string
    {
        $replacements = [
            '{vendor_name}' => $vendor->name ?? '',
            '{vendor_company}' => $vendor->name ?? '',
            '{vendor_contact}' => $vendor->contact_name ?? '',
            '{vendor_email}' => $vendor->email ?? '',
            '{vendor_phone}' => $vendor->phone ?? '',
            '{vendor_address}' => $vendor->full_address ?? '',
            '{contract_date}' => now()->format('m/d/Y'),
            '{expiration_date}' => '',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }

    /**
     * Update the rich text content of a draft contract.
     *
     * @throws InvalidArgumentException
     */
    public function updateContent(VendorContract $contract, string $htmlContent): void
    {
        if (! $contract->isDraft()) {
            throw new InvalidArgumentException('Content can only be updated while the contract is in draft status.');
        }

        $contract->update(['content' => $htmlContent]);
    }

    /**
     * Update the internal signers for a draft contract.
     *
     * @param  array<int>  $signerUserIds
     *
     * @throws InvalidArgumentException
     */
    public function updateSigners(VendorContract $contract, array $signerUserIds): void
    {
        if (! $contract->isDraft()) {
            throw new InvalidArgumentException('Signers can only be updated while the contract is in draft status.');
        }

        $this->syncSigners($contract, $signerUserIds);
    }

    // ─── Internal Signing ───

    /**
     * Send a draft contract for internal signing.
     *
     * @throws InvalidArgumentException
     */
    public function sendForInternalSigning(VendorContract $contract): void
    {
        if (! $contract->isDraft()) {
            throw new InvalidArgumentException('Only draft contracts can be sent for internal signing.');
        }

        if ($contract->signers()->count() === 0) {
            throw new InvalidArgumentException('Contract must have at least one signer before sending for internal signing.');
        }

        $contract->update(['status' => VendorContract::STATUS_PENDING_INTERNAL]);

        $contract->load('signers.user');

        foreach ($contract->signers as $signer) {
            if ($signer->user) {
                $signer->user->notify(new VendorContractInternalSigningRequest($contract));
            }
        }
    }

    /**
     * Recall a pending_internal contract back to draft, clearing all signatures.
     *
     * @throws InvalidArgumentException
     */
    public function recallToDraft(VendorContract $contract): void
    {
        if (! $contract->isPendingInternal()) {
            throw new InvalidArgumentException('Only contracts pending internal signatures can be recalled to draft.');
        }

        $contract->signers()->update([
            'signed_at' => null,
            'signature_data' => null,
            'signature_type' => null,
            'signer_ip' => null,
            'signer_user_agent' => null,
        ]);

        $contract->update(['status' => VendorContract::STATUS_DRAFT]);
    }

    /**
     * Record an internal user's signature and auto-transition when all have signed.
     *
     * @throws InvalidArgumentException
     */
    public function signAsInternal(
        VendorContract $contract,
        User $user,
        string $signatureData,
        string $signatureType,
    ): void {
        if (! $contract->isPendingInternal()) {
            throw new InvalidArgumentException('Contract is not pending internal signatures.');
        }

        $signer = $contract->signers()->where('user_id', $user->id)->first();

        if (! $signer) {
            throw new InvalidArgumentException('User is not an assigned signer for this contract.');
        }

        if ($signer->hasSigned()) {
            throw new InvalidArgumentException('User has already signed this contract.');
        }

        $signer->update([
            'signed_at' => now(),
            'signature_data' => $signatureData,
            'signature_type' => $signatureType,
        ]);

        $contract->refresh();

        if ($contract->allInternalSignersSigned()) {
            $contract->update(['status' => VendorContract::STATUS_PENDING_VENDOR]);
        }
    }

    // ─── Vendor Signing ───

    /**
     * Generate a public token and send the contract to the vendor for signing.
     */
    public function sendToVendor(VendorContract $contract): void
    {
        if (! $contract->isPendingVendor()) {
            throw new InvalidArgumentException('Contract must be pending vendor signature before sending to vendor.');
        }

        $token = $contract->generatePublicToken();

        $url = url('/vendor/contract/'.$token);

        $contract->load('vendor');

        Notification::route('mail', $contract->vendor->email)
            ->notify(new VendorContractSentToVendor($contract, $url));
    }

    /**
     * Record the vendor's signature and transition to fully executed.
     */
    public function signAsVendor(
        VendorContract $contract,
        string $name,
        string $email,
        string $signatureData,
        string $signatureType,
        ?Request $request = null,
    ): void {
        if (! $contract->isPendingVendor()) {
            throw new InvalidArgumentException('Contract is not pending vendor signature.');
        }

        VendorContractVendorSignature::create([
            'vendor_contract_id' => $contract->id,
            'signer_name' => $name,
            'signer_email' => $email,
            'signature_data' => $signatureData,
            'signature_type' => $signatureType,
            'signer_ip' => $request?->ip(),
            'signer_user_agent' => $request?->userAgent(),
            'signed_at' => now(),
        ]);

        $contract->update(['status' => VendorContract::STATUS_FULLY_EXECUTED]);

        $this->onFullyExecuted($contract);
    }

    // ─── Post-Execution ───

    /**
     * Handle post-execution tasks: generate PDF, create vendor document, notify parties.
     */
    public function onFullyExecuted(VendorContract $contract): void
    {
        $this->generateExecutedPdf($contract);
        $this->createVendorDocument($contract);
        $this->notifyFullyExecuted($contract);
    }

    /**
     * Generate the executed PDF via DomPDF and store it.
     */
    protected function generateExecutedPdf(VendorContract $contract): void
    {
        $pdf = Pdf::loadView('pdf.vendor-contract', [
            'contract' => $contract,
        ]);

        $path = "vendor-contracts/{$contract->vendor_id}/{$contract->id}/executed-contract.pdf";

        Storage::put($path, $pdf->output());

        $contract->update(['executed_pdf_path' => $path]);
    }

    /**
     * Create a VendorDocument record linked to the contract.
     */
    protected function createVendorDocument(VendorContract $contract): void
    {
        VendorDocument::create([
            'vendor_id' => $contract->vendor_id,
            'vendor_contract_id' => $contract->id,
            'name' => $contract->title,
            'type' => VendorDocument::TYPE_CONTRACT,
            'file_path' => $contract->executed_pdf_path,
            'original_filename' => 'executed-contract.pdf',
            'status' => VendorDocument::STATUS_APPROVED,
        ]);
    }

    /**
     * Notify internal signers and vendor that the contract is fully executed.
     */
    protected function notifyFullyExecuted(VendorContract $contract): void
    {
        $contract->load('signers.user', 'vendor');

        foreach ($contract->signers as $signer) {
            if ($signer->user) {
                $signer->user->notify(new VendorContractFullyExecuted($contract));
            }
        }

        Notification::route('mail', $contract->vendor->email)
            ->notify(new VendorContractFullyExecuted($contract));
    }

    // ─── Validity ───

    /**
     * Invalidate a contract. Allowed from pending_internal, pending_vendor, fully_executed, or expired.
     *
     * @throws InvalidArgumentException
     */
    public function invalidate(VendorContract $contract): void
    {
        $allowedStatuses = [
            VendorContract::STATUS_PENDING_INTERNAL,
            VendorContract::STATUS_PENDING_VENDOR,
            VendorContract::STATUS_FULLY_EXECUTED,
            VendorContract::STATUS_EXPIRED,
        ];

        if (! in_array($contract->status, $allowedStatuses)) {
            throw new InvalidArgumentException(
                'Contract can only be invalidated from pending_internal, pending_vendor, fully_executed, or expired status.'
            );
        }

        if ($contract->public_token) {
            $contract->invalidatePublicToken();
        }

        $contract->update([
            'status' => VendorContract::STATUS_INVALIDATED,
            'is_valid' => false,
        ]);
    }

    /**
     * Renew a contract by cloning it into a new draft.
     *
     * @throws InvalidArgumentException
     */
    public function renew(VendorContract $contract): VendorContract
    {
        if ($contract->hasActiveRenewal()) {
            throw new InvalidArgumentException('An active renewal already exists for this contract.');
        }

        $newContract = VendorContract::create([
            'vendor_id' => $contract->vendor_id,
            'template_id' => $contract->template_id,
            'title' => $contract->title,
            'content' => $contract->content,
            'status' => VendorContract::STATUS_DRAFT,
            'is_valid' => true,
            'renewed_from_id' => $contract->id,
            'created_by' => $contract->created_by,
        ]);

        $contract->load('signers');

        foreach ($contract->signers as $signer) {
            VendorContractSigner::create([
                'vendor_contract_id' => $newContract->id,
                'user_id' => $signer->user_id,
                'order' => $signer->order,
            ]);
        }

        return $newContract;
    }

    // ─── Private Helpers ───

    /**
     * Sync internal signers for a contract.
     *
     * @param  array<int>  $signerUserIds
     */
    private function syncSigners(VendorContract $contract, array $signerUserIds): void
    {
        $contract->signers()->delete();

        foreach (array_values($signerUserIds) as $order => $userId) {
            VendorContractSigner::create([
                'vendor_contract_id' => $contract->id,
                'user_id' => $userId,
                'order' => $order,
            ]);
        }
    }
}
