<?php

namespace App\Services\Prospector;

use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\ExternalIdMap;
use Illuminate\Database\Eloquent\Model;

class ModelBridgeService
{
    /**
     * Flag to prevent sync loops. When true, observers skip syncing.
     */
    public static bool $syncing = false;

    /**
     * Import a host model record into Prospector.
     *
     * @return Model The created/updated Prospector model
     */
    public function importFromHost(Model $hostModel, string $prospectorType): ?Model
    {
        $config = config("prospector-bridge.{$prospectorType}");

        if (! $config || ! $config['model']) {
            return null;
        }

        $fieldMap = $config['fields'] ?? [];
        $mappedData = $this->mapFields($hostModel, $fieldMap);

        if (empty($mappedData)) {
            return null;
        }

        // Look up existing map
        $map = ExternalIdMap::forExternal(get_class($hostModel), $hostModel->getKey())->first();

        try {
            self::$syncing = true;

            if ($prospectorType === 'companies') {
                $prospectorModel = $this->syncCompany($map, $mappedData, $hostModel);
            } elseif ($prospectorType === 'contacts') {
                $prospectorModel = $this->syncContact($map, $mappedData, $hostModel, $config);
            } else {
                return null;
            }

            // Create or update the map
            ExternalIdMap::updateOrCreate(
                [
                    'prospector_type' => rtrim($prospectorType, 's'), // companies -> company
                    'external_model' => get_class($hostModel),
                    'external_id' => $hostModel->getKey(),
                ],
                [
                    'prospector_id' => $prospectorModel->id,
                    'last_synced_at' => now(),
                ],
            );

            return $prospectorModel;
        } finally {
            self::$syncing = false;
        }
    }

    /**
     * Export a Prospector model back to the host model.
     */
    public function exportToHost(Model $prospectorModel, string $prospectorType): ?Model
    {
        $config = config("prospector-bridge.{$prospectorType}");

        if (! $config || ! $config['model']) {
            return null;
        }

        $map = ExternalIdMap::forProspector(
            rtrim($prospectorType, 's'),
            $prospectorModel->getKey(),
        )->first();

        if (! $map) {
            return null; // No host record to update
        }

        $hostModelClass = $map->external_model;
        $hostModel = $hostModelClass::find($map->external_id);

        if (! $hostModel) {
            return null;
        }

        $fieldMap = $config['fields'] ?? [];
        $reverseMappedData = $this->reverseMapFields($prospectorModel, $fieldMap);

        try {
            self::$syncing = true;
            $hostModel->update($reverseMappedData);
            $map->update(['last_synced_at' => now()]);

            return $hostModel;
        } finally {
            self::$syncing = false;
        }
    }

    /**
     * Map host model fields to Prospector fields using the config mapping.
     */
    public function mapFields(Model $hostModel, array $fieldMap): array
    {
        $mapped = [];

        foreach ($fieldMap as $prospectorField => $hostField) {
            if (is_callable($hostField)) {
                $mapped[$prospectorField] = $hostField($hostModel);
            } elseif (is_string($hostField)) {
                $value = data_get($hostModel, $hostField);
                if ($value !== null) {
                    $mapped[$prospectorField] = $value;
                }
            }
        }

        return $mapped;
    }

    /**
     * Reverse map: Prospector fields back to host model fields.
     */
    public function reverseMapFields(Model $prospectorModel, array $fieldMap): array
    {
        $mapped = [];

        foreach ($fieldMap as $prospectorField => $hostField) {
            if (is_string($hostField)) {
                $value = $prospectorModel->getAttribute($prospectorField);
                if ($value !== null) {
                    $mapped[$hostField] = $value;
                }
            }
            // Skip callables for reverse mapping — they're one-way
        }

        return $mapped;
    }

    /**
     * Sync a company record.
     */
    protected function syncCompany(?ExternalIdMap $map, array $data, Model $hostModel): Company
    {
        if ($map) {
            $company = Company::find($map->prospector_id);
            if ($company) {
                $company->update($data);

                return $company;
            }
        }

        // Create new company — requires a Twilio account
        $defaultAccount = \App\Models\Prospector\TwilioAccount::where('is_active', true)->first();

        return Company::create(array_merge($data, [
            'twilio_account_id' => $defaultAccount?->id,
            'is_active' => true,
        ]));
    }

    /**
     * Sync a contact record.
     */
    protected function syncContact(?ExternalIdMap $map, array $data, Model $hostModel, array $config): Contact
    {
        if ($map) {
            $contact = Contact::find($map->prospector_id);
            if ($contact) {
                $contact->update($data);

                return $contact;
            }
        }

        // Resolve company association
        $companyKey = $config['company_key'] ?? null;
        if ($companyKey && $hostModel->getAttribute($companyKey)) {
            $companyMap = ExternalIdMap::where('prospector_type', 'company')
                ->where('external_model', config('prospector-bridge.companies.model'))
                ->where('external_id', $hostModel->getAttribute($companyKey))
                ->first();

            if ($companyMap) {
                $data['company_id'] = $companyMap->prospector_id;
            }
        }

        // If no company resolved, use the first active company
        if (empty($data['company_id'])) {
            $data['company_id'] = Company::where('is_active', true)->first()?->id;
        }

        // Format phone to E.164 if present
        if (! empty($data['phone'])) {
            $data['phone'] = app(ContactIntakeService::class)->formatPhone($data['phone']);
        }

        // Check for existing contact by phone (dedup)
        if (! empty($data['phone']) && ! empty($data['company_id'])) {
            $existing = Contact::where('company_id', $data['company_id'])
                ->where(function ($q) use ($data) {
                    $q->where('phone', $data['phone'])
                        ->orWhere('phone_2', $data['phone']);
                })
                ->first();

            if ($existing) {
                $existing->update($data);

                return $existing;
            }
        }

        return Contact::create($data);
    }

    /**
     * Check if the bridge is enabled and configured for a given type.
     */
    public static function isConfigured(string $type = 'companies'): bool
    {
        return app(\App\Services\Prospector\SettingsService::class)->get('bridge_enabled', false)
            && config("prospector-bridge.{$type}.model") !== null;
    }
}
