<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id', 'leave_type_id', 'year',
        'entitled_days', 'used_days', 'booked_days',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'entitled_days' => 'decimal:2',
            'used_days' => 'decimal:2',
            'booked_days' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function getRemainingDaysAttribute(): float
    {
        return (float) $this->entitled_days - (float) $this->used_days - (float) $this->booked_days;
    }
}
