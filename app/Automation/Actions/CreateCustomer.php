<?php

namespace App\Automation\Actions;

use App\Automation\Contracts\ActionInterface;
use App\Automation\Registry;
use App\Models\Customer;
use App\Models\WorkflowRun;

class CreateCustomer implements ActionInterface
{
    public static function getName(): string
    {
        return 'Create Customer';
    }

    public static function getCategory(): string
    {
        return 'Record Management';
    }

    public static function getDescription(): string
    {
        return 'Creates a new customer record from webhook or form data. Supports deduplication by email and swaps the run subject to the customer for downstream actions.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'first_name' => [
                'type' => 'string',
                'label' => 'First Name',
                'description' => 'Customer first name. Supports {{webhook.field}} placeholders.',
                'required' => true,
            ],
            'last_name' => [
                'type' => 'string',
                'label' => 'Last Name',
                'description' => 'Customer last name. Supports {{webhook.field}} placeholders.',
                'required' => false,
            ],
            'email' => [
                'type' => 'string',
                'label' => 'Email',
                'description' => 'Customer email address. Supports {{webhook.field}} placeholders.',
                'required' => false,
            ],
            'phone' => [
                'type' => 'string',
                'label' => 'Phone',
                'description' => 'Customer phone number. Supports {{webhook.field}} placeholders.',
                'required' => false,
            ],
            'address' => [
                'type' => 'string',
                'label' => 'Address',
                'description' => 'Street address. Supports {{webhook.field}} placeholders.',
                'required' => false,
            ],
            'city' => [
                'type' => 'string',
                'label' => 'City',
                'description' => 'City. Supports {{webhook.field}} placeholders.',
                'required' => false,
            ],
            'state' => [
                'type' => 'string',
                'label' => 'State',
                'description' => 'State. Supports {{webhook.field}} placeholders.',
                'required' => false,
            ],
            'zip' => [
                'type' => 'string',
                'label' => 'Zip',
                'description' => 'Zip code. Supports {{webhook.field}} placeholders.',
                'required' => false,
            ],
            'source' => [
                'type' => 'string',
                'label' => 'Source',
                'description' => 'Lead source (e.g., facebook, google_ads).',
                'required' => false,
            ],
            'deduplicate_by_email' => [
                'type' => 'boolean',
                'label' => 'Deduplicate by Email',
                'description' => 'If enabled, finds existing customer by email instead of creating a duplicate.',
                'required' => false,
                'default' => true,
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

        $firstName = $registry->resolveDynamicValues($config['first_name'] ?? '', $run);
        $lastName = $registry->resolveDynamicValues($config['last_name'] ?? '', $run);
        $email = $registry->resolveDynamicValues($config['email'] ?? '', $run);
        $phone = $registry->resolveDynamicValues($config['phone'] ?? '', $run);
        $address = $registry->resolveDynamicValues($config['address'] ?? '', $run);
        $city = $registry->resolveDynamicValues($config['city'] ?? '', $run);
        $state = $registry->resolveDynamicValues($config['state'] ?? '', $run);
        $zip = $registry->resolveDynamicValues($config['zip'] ?? '', $run);
        $source = $registry->resolveDynamicValues($config['source'] ?? '', $run);
        $dedup = $config['deduplicate_by_email'] ?? true;

        $created = false;
        $customer = null;

        // Deduplication by email
        if ($dedup && ! empty($email)) {
            $customer = Customer::where('email', $email)->first();
        }

        if (! $customer) {
            $attributes = [
                'first_name' => $firstName,
                'last_name' => $lastName ?: '',
                'created_by' => $run->triggered_by,
            ];

            if ($email) {
                $attributes['email'] = $email;
            }
            if ($phone) {
                $attributes['phone'] = $phone;
            }
            if ($address) {
                $attributes['address'] = $address;
            }
            if ($city) {
                $attributes['city'] = $city;
            }
            if ($state) {
                $attributes['state'] = $state;
            }
            if ($zip) {
                $attributes['zip'] = $zip;
            }
            if ($source) {
                $attributes['source'] = $source;
            }

            $customer = Customer::create($attributes);
            $created = true;
        }

        // Swap run subject to the customer for downstream actions
        $run->update([
            'subject_type' => Customer::class,
            'subject_id' => $customer->id,
        ]);

        // Set customer_id in context for downstream actions
        $context = $run->context ?? [];
        $context['customer_id'] = $customer->id;
        $run->update(['context' => $context]);

        return ['customer_id' => $customer->id, 'created' => $created];
    }
}
