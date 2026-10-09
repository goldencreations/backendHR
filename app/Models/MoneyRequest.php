<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MoneyRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference', 'employee_id', 'money_request_type_id', 'amount_minor',
        'currency', 'reason', 'needed_by', 'payment_method', 'status',
        'decided_by', 'decided_at', 'decision_note', 'paid_at', 'receipt_number',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'needed_by' => 'date',
            'paid_at' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(MoneyRequestType::class, 'money_request_type_id');
    }
}
