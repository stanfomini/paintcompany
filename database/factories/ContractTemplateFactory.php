<?php

namespace Database\Factories;

use App\Models\ContractTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContractTemplate>
 */
class ContractTemplateFactory extends Factory
{
    protected $model = ContractTemplate::class;

    public function definition(): array
    {
        return [
            'name' => fake()->sentence(3),
            'description' => fake()->sentence(),
            'type' => 'standard',
            'content_type' => 'rich_text',
            'content' => 'This agreement is between {company_name} and {employee_name}, Employee #{employee_number}. '
                .'Position: {job_title}, Department: {department}. '
                .'Compensation: {pay_rate} ({pay_type}). Start Date: {hire_date}. '
                .'Date: {date}.',
            'merge_tags' => ContractTemplate::getAvailableMergeTags(),
            'is_active' => true,
            'version' => 1,
        ];
    }

    public function pdf(): static
    {
        return $this->state(fn (array $attributes) => [
            'content_type' => 'pdf',
            'content' => null,
            'pdf_path' => 'contracts/templates/'.fake()->uuid().'.pdf',
            'fields' => [
                'pages' => ['1' => ['width' => 612, 'height' => 792]],
                'fields' => [
                    [
                        'id' => 'field_name',
                        'type' => 'text',
                        'label' => 'Employee Full Name',
                        'merge_tag' => 'employee_name',
                        'page' => 1,
                        'x_pct' => 20.0,
                        'y_pct' => 25.0,
                        'width_pct' => 40.0,
                        'height_pct' => 3.0,
                        'required' => true,
                        'font_size' => 12,
                    ],
                    [
                        'id' => 'field_date',
                        'type' => 'date',
                        'label' => 'Date',
                        'merge_tag' => 'date',
                        'page' => 1,
                        'x_pct' => 20.0,
                        'y_pct' => 35.0,
                        'width_pct' => 30.0,
                        'height_pct' => 3.0,
                        'required' => true,
                        'font_size' => 12,
                    ],
                    [
                        'id' => 'field_signature',
                        'type' => 'signature',
                        'label' => 'Signature',
                        'merge_tag' => null,
                        'page' => 1,
                        'x_pct' => 20.0,
                        'y_pct' => 55.0,
                        'width_pct' => 40.0,
                        'height_pct' => 8.0,
                        'required' => true,
                        'font_size' => 12,
                    ],
                ],
            ],
        ]);
    }

    public function roleSpecific(string $role = 'Painter'): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'role_specific',
            'role_name' => $role,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
