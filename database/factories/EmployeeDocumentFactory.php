<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmployeeDocumentFactory extends Factory
{
    protected $model = EmployeeDocument::class;

    public function definition(): array
    {
        return [
            'reference' => 'DOC-'.fake()->unique()->numerify('####'),
            'employee_id' => Employee::factory(),
            'name' => 'document.pdf',
            'disk_path' => 'documents/2024/01/'.fake()->uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'status' => 'verified',
        ];
    }
}
