<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use Illuminate\Database\Eloquent\Factories\Factory;

class PayslipFactory extends Factory
{
    protected $model = Payslip::class;

    public function definition(): array
    {
        return [
            'reference' => 'PR-'.fake()->unique()->numerify('####'),
            'run_id' => PayrollRun::factory(),
            'employee_id' => Employee::factory(),
            'period_year' => now()->year,
            'period_month' => 6,
            'basic_minor' => fake()->numberBetween(1_000_000, 5_000_000),
            'overtime_minor' => 0,
            'bonus_minor' => 0,
            'allowance_minor' => 0,
            'tax_minor' => fake()->numberBetween(50_000, 500_000),
            'pension_minor' => fake()->numberBetween(50_000, 400_000),
            'other_deduction_minor' => 0,
            'currency' => 'TZS',
            'payment_method' => 'bank_transfer',
            'status' => 'pending_approval',
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'status' => 'paid',
            'paid_at' => now()->toDateString(),
        ]);
    }
}
