<?php

namespace App\Services;

use App\Models\WebhookEndpoint;

class WebhookMappingPresetService
{
    /**
     * Get the mapping preset for a given lead source slug.
     *
     * @return array<int, array{source_path: string, target_model: string, target_field: string, transform?: string}>|null
     */
    public function getPresetFor(string $slug): ?array
    {
        return match ($slug) {
            'angi' => $this->angiPreset(),
            'craftjack' => $this->craftjackPreset(),
            'thumbtack' => $this->thumbtackPreset(),
            default => null,
        };
    }

    public function applyPreset(WebhookEndpoint $endpoint, string $slug): void
    {
        $mappings = $this->getPresetFor($slug);

        if (! $mappings) {
            return;
        }

        foreach ($mappings as $i => $mapping) {
            $endpoint->fieldMappings()->create([
                ...$mapping,
                'sort_order' => $i + 1,
            ]);
        }
    }

    /**
     * @return array<int, array{source_path: string, target_model: string, target_field: string, transform?: string}>
     */
    protected function angiPreset(): array
    {
        return [
            ['source_path' => 'firstName', 'target_model' => 'customer', 'target_field' => 'first_name'],
            ['source_path' => 'lastName', 'target_model' => 'customer', 'target_field' => 'last_name'],
            ['source_path' => 'email', 'target_model' => 'customer', 'target_field' => 'email'],
            ['source_path' => 'primaryPhone', 'target_model' => 'customer', 'target_field' => 'phone', 'transform' => 'format_phone'],
            ['source_path' => 'address', 'target_model' => 'customer', 'target_field' => 'address'],
            ['source_path' => 'city', 'target_model' => 'customer', 'target_field' => 'city'],
            ['source_path' => 'stateProvince', 'target_model' => 'customer', 'target_field' => 'state'],
            ['source_path' => 'postalCode', 'target_model' => 'customer', 'target_field' => 'zip'],
            ['source_path' => 'taskName', 'target_model' => 'deal', 'target_field' => 'title'],
            ['source_path' => 'comments', 'target_model' => 'deal', 'target_field' => 'description'],
            ['source_path' => 'leadOid', 'target_model' => 'deal', 'target_field' => 'external_id'],
        ];
    }

    /**
     * @return array<int, array{source_path: string, target_model: string, target_field: string, transform?: string}>
     */
    protected function craftjackPreset(): array
    {
        return [
            ['source_path' => 'FirstName', 'target_model' => 'customer', 'target_field' => 'first_name'],
            ['source_path' => 'LastName', 'target_model' => 'customer', 'target_field' => 'last_name'],
            ['source_path' => 'EmailAddress', 'target_model' => 'customer', 'target_field' => 'email'],
            ['source_path' => 'DirectLeadPhoneNumber', 'target_model' => 'customer', 'target_field' => 'phone', 'transform' => 'format_phone'],
            ['source_path' => 'Address', 'target_model' => 'customer', 'target_field' => 'address'],
            ['source_path' => 'City', 'target_model' => 'customer', 'target_field' => 'city'],
            ['source_path' => 'State', 'target_model' => 'customer', 'target_field' => 'state'],
            ['source_path' => 'ZipCode', 'target_model' => 'customer', 'target_field' => 'zip'],
            ['source_path' => 'ServiceName', 'target_model' => 'deal', 'target_field' => 'title'],
            ['source_path' => 'Notes', 'target_model' => 'deal', 'target_field' => 'description'],
            ['source_path' => 'LeadId', 'target_model' => 'deal', 'target_field' => 'external_id'],
        ];
    }

    /**
     * @return array<int, array{source_path: string, target_model: string, target_field: string, transform?: string}>
     */
    protected function thumbtackPreset(): array
    {
        return [
            ['source_path' => 'customer.name', 'target_model' => 'customer', 'target_field' => 'first_name', 'transform' => 'split_first_name'],
            ['source_path' => 'customer.name', 'target_model' => 'customer', 'target_field' => 'last_name', 'transform' => 'split_last_name'],
            ['source_path' => 'request.location.city', 'target_model' => 'customer', 'target_field' => 'city'],
            ['source_path' => 'request.location.state', 'target_model' => 'customer', 'target_field' => 'state'],
            ['source_path' => 'request.location.zipCode', 'target_model' => 'customer', 'target_field' => 'zip'],
            ['source_path' => 'request.category', 'target_model' => 'deal', 'target_field' => 'title'],
            ['source_path' => 'request.description', 'target_model' => 'deal', 'target_field' => 'description'],
            ['source_path' => 'leadID', 'target_model' => 'deal', 'target_field' => 'external_id'],
        ];
    }
}
