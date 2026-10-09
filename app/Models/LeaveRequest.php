<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference', 'employee_id', 'leave_type_id', 'start_date', 'end_date',
        'days', 'reason', 'coverage_employee_id', 'approver_id', 'status',
        'reviewer_note', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'days' => 'decimal:2',
            'decided_at' => 'datetime',
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

    /** Colleague covering the absence; may be the same person as the approver. */
    public function coverageEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'coverage_employee_id');
    }

    /**
     * The HR user or department lead who decided or owns the request.
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
