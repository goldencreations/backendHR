<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Employee;
use App\Models\JobRole;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        $first = fake()->firstName();
        $last = fake()->lastName();

        return [
            'employee_code' => 'GH-'.fake()->unique()->numberBetween(1000, 9999),
            'first_name' => $first,
            'last_name' => $last,
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+2557'.fake()->numerify('#######'),
            'employment_type' => 'full_time',
            'hire_date' => now()->subYears(fake()->numberBetween(1, 5)),
            'start_date' => now()->subYears(fake()->numberBetween(1, 5)),
            'base_salary_minor' => fake()->numberBetween(1_000_000, 8_000_000),
            'currency' => 'TZS',
            'status' => 'active',
            'nida_number' => fake()->unique()->numerify('#########'),
        ];
    }

    public function inDepartment(Department $department): static
    {
        return $this->state(fn () => ['department_id' => $department->id]);
    }

    public function withRole(JobRole $role): static
    {
        return $this->state(fn () => [
            'job_role_id' => $role->id,
            'department_id' => $role->department_id,
        ]);
    }
}
