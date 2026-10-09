<?php

namespace Database\Factories;

use App\Models\PayrollRun;
use Illuminate\Database\Eloquent\Factories\Factory;

class PayrollRunFactory extends Factory
{
    protected $model = PayrollRun::class;

    public function definition(): array
    {
        return [
            'period_year' => fake()->unique()->numberBetween(2024, 2030),
            'period_month' => fake()->numberBetween(1, 12),
            'status' => 'pending_approval',
        ];
    }
}
