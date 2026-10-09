<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * gross_minor, total_deductions_minor and net_minor are MySQL generated
 * columns - they are computed by the database and must not be assigned.
 */
class Payslip extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference', 'run_id', 'employee_id', 'period_year', 'period_month',
        'basic_minor', 'overtime_minor', 'bonus_minor', 'allowance_minor',
        'tax_minor', 'pension_minor', 'other_deduction_minor',
        'currency', 'payment_method', 'status', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'period_month' => 'integer',
            'basic_minor' => 'integer',
            'overtime_minor' => 'integer',
            'bonus_minor' => 'integer',
            'allowance_minor' => 'integer',
            'tax_minor' => 'integer',
            'pension_minor' => 'integer',
            'other_deduction_minor' => 'integer',
            'gross_minor' => 'integer',
            'total_deductions_minor' => 'integer',
            'net_minor' => 'integer',
            'paid_at' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'run_id');
    }
}
