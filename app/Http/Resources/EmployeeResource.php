<?php

namespace App\Http\Resources;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serialises an employee for both portals.
 *
 * $full is requested by the HR directory and Registration Info; the
 * self-service portal gets the same shape but the salary field is omitted
 * by the controller, since the UI marks it "Managed by People Operations".
 */
class EmployeeResource extends JsonResource
{
    public function __construct($resource, private readonly bool $full = true)
    {
        parent::__construct($resource);
    }

    public static function summary($resource): self
    {
        return new self($resource, false);
    }

    public function toArray(Request $request): array
    {
        $payload = [
            'id' => $this->id,
            'employee_code' => $this->employee_code,
            'name' => $this->full_name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'employment_type' => $this->employment_type,
            'employment_type_label' => $this->employmentTypeLabel(),
            'status' => $this->status,
            'department' => $this->department?->name,
            'department_id' => $this->department_id,
            'role' => $this->jobRole?->title,
            'role_level' => $this->jobRole?->level,
            'job_role_id' => $this->job_role_id,
            'hire_date' => $this->hire_date?->toDateString(),
            'start_date' => $this->start_date?->toDateString(),
            'has_login' => $this->user_id !== null,
            'avatar_url' => $this->profile_image_path
                ? route('api.employees.avatar', ['employee' => $this->id])
                : null,
            'initials' => $this->initials(),
        ];

        if (! $this->full) {
            return $payload;
        }

        return $payload + [
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'tin' => $this->tin,
            'nida_number' => $this->nida_number,
            'address' => $this->address,
            'emergency_contact_name' => $this->emergency_contact_name,
            'emergency_contact_phone' => $this->emergency_contact_phone,
            'base_salary_minor' => $this->base_salary_minor,
            'currency' => $this->currency,
            'termination_date' => $this->termination_date?->toDateString(),
            'current_assignment' => $this->currentAssignment() ? [
                'job_role_id' => $this->currentAssignment()->job_role_id,
                'role' => $this->currentAssignment()->jobRole?->title,
                'level' => $this->currentAssignment()->jobRole?->level,
                'effective_from' => $this->currentAssignment()->effective_from?->toDateString(),
            ] : null,
            'documents_count' => $this->whenCounted('documents'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function initials(): string
    {
        return mb_substr($this->first_name ?? '', 0, 1)
            .mb_substr($this->last_name ?? '', 0, 1);
    }

    private function employmentTypeLabel(): string
    {
        return match ($this->employment_type) {
            'full_time' => 'Full time',
            'part_time' => 'Part-time',
            'contract' => 'Contract',
            'temporary' => 'Temporary',
            default => (string) $this->employment_type,
        };
    }
}
