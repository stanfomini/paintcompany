<?php

namespace App\Automation\Actions;

use App\Automation\Contracts\ActionInterface;
use App\Automation\Registry;
use App\Models\EmployeeProfile;
use App\Models\User;
use App\Models\WorkflowRun;
use Illuminate\Support\Str;

class CreateEmployee implements ActionInterface
{
    public static function getName(): string
    {
        return 'Create Employee';
    }

    public static function getCategory(): string
    {
        return 'Record Management';
    }

    public static function getDescription(): string
    {
        return 'Creates a new internal user with an employee profile from webhook or form data. Supports deduplication by email and swaps the run subject to the user for downstream actions.';
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
                'description' => 'Employee first name. Supports {{webhook.field}} placeholders.',
                'required' => true,
            ],
            'last_name' => [
                'type' => 'string',
                'label' => 'Last Name',
                'description' => 'Employee last name. Supports {{webhook.field}} placeholders.',
                'required' => false,
            ],
            'email' => [
                'type' => 'string',
                'label' => 'Email',
                'description' => 'Employee email address. Supports {{webhook.field}} placeholders.',
                'required' => false,
            ],
            'phone' => [
                'type' => 'string',
                'label' => 'Phone',
                'description' => 'Employee phone number. Supports {{webhook.field}} placeholders.',
                'required' => false,
            ],
            'job_title' => [
                'type' => 'string',
                'label' => 'Job Title',
                'description' => 'Job title (e.g., Painter, Lead Painter). Supports {{webhook.field}} placeholders.',
                'required' => false,
            ],
            'department' => [
                'type' => 'string',
                'label' => 'Department',
                'description' => 'Department (e.g., Production, Sales). Supports {{webhook.field}} placeholders.',
                'required' => false,
            ],
            'pay_rate' => [
                'type' => 'string',
                'label' => 'Pay Rate',
                'description' => 'Hourly rate or annual salary. Supports {{webhook.field}} placeholders.',
                'required' => false,
            ],
            'pay_type' => [
                'type' => 'string',
                'label' => 'Pay Type',
                'description' => 'Either "hourly" or "salary". Defaults to hourly.',
                'required' => false,
                'default' => 'hourly',
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
            'deduplicate_by_email' => [
                'type' => 'boolean',
                'label' => 'Deduplicate by Email',
                'description' => 'If enabled, finds existing user by email instead of creating a duplicate.',
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
        $jobTitle = $registry->resolveDynamicValues($config['job_title'] ?? '', $run);
        $department = $registry->resolveDynamicValues($config['department'] ?? '', $run);
        $payRate = $registry->resolveDynamicValues($config['pay_rate'] ?? '', $run);
        $payType = $registry->resolveDynamicValues($config['pay_type'] ?? 'hourly', $run);
        $address = $registry->resolveDynamicValues($config['address'] ?? '', $run);
        $city = $registry->resolveDynamicValues($config['city'] ?? '', $run);
        $state = $registry->resolveDynamicValues($config['state'] ?? '', $run);
        $zip = $registry->resolveDynamicValues($config['zip'] ?? '', $run);
        $dedup = $config['deduplicate_by_email'] ?? true;

        $name = trim($firstName.' '.$lastName);

        $created = false;
        $user = null;

        // Deduplication by email
        if ($dedup && ! empty($email)) {
            $user = User::where('email', $email)->where('type', 'internal')->first();
        }

        if (! $user) {
            $attributes = [
                'name' => $name ?: 'New Employee',
                'password' => bcrypt(Str::random(32)),
                'type' => 'internal',
            ];

            if ($email) {
                $attributes['email'] = $email;
            } else {
                $attributes['email'] = Str::slug($name ?: 'employee').'-'.Str::random(6).'@placeholder.local';
            }

            $user = User::create($attributes);
            $created = true;
        }

        // Create or update employee profile
        $profileData = [
            'hire_date' => now()->toDateString(),
            'employment_status' => 'active',
        ];

        if ($jobTitle) {
            $profileData['job_title'] = $jobTitle;
        }
        if ($department) {
            $profileData['department'] = $department;
        }
        if ($payRate) {
            $profileData['pay_rate'] = (float) $payRate;
        }
        if ($payType && in_array($payType, ['hourly', 'salary'])) {
            $profileData['pay_type'] = $payType;
        }
        if ($address) {
            $profileData['address_line_1'] = $address;
        }
        if ($city) {
            $profileData['city'] = $city;
        }
        if ($state) {
            $profileData['state'] = $state;
        }
        if ($zip) {
            $profileData['zip_code'] = $zip;
        }
        if ($phone) {
            $profileData['phone'] = $phone;
        }

        $profile = $user->employeeProfile;

        if (! $profile) {
            $profileData['user_id'] = $user->id;
            $profileData['employee_number'] = EmployeeProfile::generateEmployeeNumber();
            $profile = EmployeeProfile::create($profileData);
        } else {
            $profile->update($profileData);
        }

        // Swap run subject to the user for downstream actions
        $run->update([
            'subject_type' => User::class,
            'subject_id' => $user->id,
        ]);

        // Set IDs in context for downstream actions
        $context = $run->context ?? [];
        $context['user_id'] = $user->id;
        $context['employee_profile_id'] = $profile->id;
        $run->update(['context' => $context]);

        return [
            'user_id' => $user->id,
            'employee_profile_id' => $profile->id,
            'employee_number' => $profile->employee_number,
            'created' => $created,
        ];
    }
}
