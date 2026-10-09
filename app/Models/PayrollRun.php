<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'period_year', 'period_month', 'status', 'created_by',
        'approved_by', 'approved_at', 'paid_at',
        'gross_total_minor', 'net_total_minor', 'payslip_count',
    ];

    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'period_month' => 'integer',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'gross_total_minor' => 'integer',
            'net_total_minor' => 'integer',
            'payslip_count' => 'integer',
        ];
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class, 'run_id');
    }
}
