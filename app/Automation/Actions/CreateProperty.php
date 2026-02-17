<?php

namespace App\Automation\Actions;

use App\Automation\Contracts\ActionInterface;
use App\Automation\Registry;
use App\Models\Customer;
use App\Models\Property;
use App\Models\WorkflowRun;

class CreateProperty implements ActionInterface
{
    public static function getName(): string
    {
        return 'Create Property';
    }

    public static function getCategory(): string
    {
        return 'Record Management';
    }

    public static function getDescription(): string
    {
        return 'Creates a new property record linked to the current customer. Deduplicates by address and city.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'address' => [
                'type' => 'string',
                'label' => 'Address',
                'description' => 'Street address. Supports {{webhook.field}} placeholders.',
                'required' => true,
            ],
            'city' => [
                'type' => 'string',
                'label' => 'City',
                'description' => 'City. Supports {{webhook.field}} placeholders.',
                'required' => true,
            ],
            'state' => [
                'type' => 'string',
                'label' => 'State',
                'description' => 'State. Supports {{webhook.field}} placeholders.',
                'required' => false,
            ],
            'zip' => [
                'type' => 'string',
                'label' => 'Zip Code',
                'description' => 'Zip code. Supports {{webhook.field}} placeholders.',
                'required' => false,
            ],
            'type' => [
                'type' => 'string',
                'label' => 'Property Type',
                'description' => 'The property type.',
                'required' => false,
                'default' => 'residential',
                'options' => ['residential' => 'Residential', 'commercial' => 'Commercial'],
            ],
            'square_footage' => [
                'type' => 'string',
                'label' => 'Square Footage',
                'description' => 'The property square footage. Supports {{webhook.field}} placeholders.',
                'required' => false,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function execute(WorkflowRun $run, array $config): array
    {
        $registry = app(Registry::class);

        $address = $registry->resolveDynamicValues($config['address'] ?? '', $run);
        $city = $registry->resolveDynamicValues($config['city'] ?? '', $run);
        $state = $registry->resolveDynamicValues($config['state'] ?? '', $run);
        $zip = $registry->resolveDynamicValues($config['zip'] ?? '', $run);
        $type = $config['type'] ?? 'residential';
        $sqft = $registry->resolveDynamicValues($config['square_footage'] ?? '', $run);

        $customer = $this->resolveCustomer($run);

        // Dedup by address + city for same customer
        $created = false;
        $property = null;

        if ($customer && $address && $city) {
            $property = Property::where('customer_id', $customer->id)
                ->where('address', $address)
                ->where('city', $city)
                ->first();
        }

        if (! $property) {
            $property = Property::create(array_filter([
                'customer_id' => $customer?->id,
                'address' => $address,
                'city' => $city,
                'state' => $state ?: null,
                'zip' => $zip ?: null,
                'type' => $type,
                'square_footage' => $sqft ? (int) $sqft : null,
            ], fn ($v) => $v !== null && $v !== ''));
            $created = true;
        }

        // Set property_id in context
        $context = $run->context ?? [];
        $context['property_id'] = $property->id;
        $run->update(['context' => $context]);

        return ['property_id' => $property->id, 'created' => $created];
    }

    protected function resolveCustomer(WorkflowRun $run): ?Customer
    {
        $subject = $run->subject;

        if ($subject instanceof Customer) {
            return $subject;
        }

        $customerId = $run->context['customer_id'] ?? null;

        if ($customerId) {
            return Customer::find($customerId);
        }

        return null;
    }
}
