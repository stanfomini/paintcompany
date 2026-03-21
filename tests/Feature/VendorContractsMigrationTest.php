<?php

use Illuminate\Support\Facades\Schema;

it('creates the vendor_contracts table with correct columns', function () {
    expect(Schema::hasTable('vendor_contracts'))->toBeTrue();

    expect(Schema::hasColumns('vendor_contracts', [
        'id', 'vendor_id', 'template_id', 'title', 'content',
        'status', 'is_valid', 'notes', 'expires_at', 'expiry_warning_sent_at',
        'renewed_from_id', 'public_token', 'public_token_expires_at',
        'executed_pdf_path', 'created_by', 'created_at', 'updated_at', 'deleted_at',
    ]))->toBeTrue();
});

it('creates the vendor_contract_signers table with correct columns', function () {
    expect(Schema::hasTable('vendor_contract_signers'))->toBeTrue();

    expect(Schema::hasColumns('vendor_contract_signers', [
        'id', 'vendor_contract_id', 'user_id', 'order',
        'signed_at', 'signature_data', 'signature_type',
        'signer_ip', 'signer_user_agent', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('creates the vendor_contract_vendor_signatures table with correct columns', function () {
    expect(Schema::hasTable('vendor_contract_vendor_signatures'))->toBeTrue();

    expect(Schema::hasColumns('vendor_contract_vendor_signatures', [
        'id', 'vendor_contract_id', 'signer_name', 'signer_email',
        'signature_data', 'signature_type', 'signer_ip',
        'signer_user_agent', 'signed_at', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('adds vendor_contract_id column to vendor_documents table', function () {
    expect(Schema::hasColumn('vendor_documents', 'vendor_contract_id'))->toBeTrue();
});
