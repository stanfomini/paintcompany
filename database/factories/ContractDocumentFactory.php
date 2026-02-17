<?php

namespace Database\Factories;

use App\Models\ContractDocument;
use App\Models\EmployeeContract;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContractDocument>
 */
class ContractDocumentFactory extends Factory
{
    protected $model = ContractDocument::class;

    public function definition(): array
    {
        return [
            'employee_contract_id' => EmployeeContract::factory(),
            'name' => fake()->word().'.pdf',
            'file_path' => 'contract-documents/'.fake()->uuid().'.pdf',
            'file_size' => fake()->numberBetween(1024, 5242880),
            'file_type' => 'application/pdf',
            'uploaded_by' => User::factory()->internal(),
        ];
    }
}
