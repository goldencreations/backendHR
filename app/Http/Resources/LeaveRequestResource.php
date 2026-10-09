<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'employee' => $this->employee ? [
                'id' => $this->employee->id,
                'name' => $this->employee->full_name,
                'initials' => mb_substr((string) $this->employee->first_name, 0, 1).mb_substr((string) $this->employee->last_name, 0, 1),
            ] : null,
            'leave_type' => $this->leaveType?->name,
            'leave_type_id' => $this->leave_type_id,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'days' => (float) $this->days,
            'reason' => $this->reason,
            // Null when nobody is covering; the UI compares this with the
            // approver to decide which note to show.
            'coverage' => $this->coverageEmployee ? [
                'id' => $this->coverageEmployee->id,
                'name' => $this->coverageEmployee->full_name,
            ] : null,
            'approver' => $this->approver?->name,
            'approver_id' => $this->approver_id,
            'status' => $this->status,
            'reviewer_note' => $this->reviewer_note,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
