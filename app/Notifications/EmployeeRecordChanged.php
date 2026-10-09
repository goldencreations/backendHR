<?php

namespace App\Notifications;

use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Recorded whenever HR changes an employee record, mirroring the "Role
 * change" and "Employee" notification kinds the portal already shows.
 */
class EmployeeRecordChanged extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Employee $employee,
        public readonly string $action,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->action === 'role_changed' ? 'role' : 'employee',
            'title' => $this->title(),
            'body' => $this->body(),
            'actor_user_id' => $notifiable->id,
            'subject_type' => Employee::class,
            'subject_id' => $this->employee->id,
        ];
    }

    private function title(): string
    {
        return match ($this->action) {
            'role_changed' => 'Role assignment updated',
            'created' => 'New employee added',
            default => 'Employee record updated',
        };
    }

    private function body(): string
    {
        $role = $this->employee->jobRole?->title ?? 'no role';
        $department = $this->employee->department?->name ?? 'no department';

        return match ($this->action) {
            'created' => sprintf(
                '%s joined the %s department as %s.',
                $this->employee->full_name,
                $department,
                $role
            ),
            'role_changed' => sprintf(
                '%s is now %s in %s.',
                $this->employee->full_name,
                $role,
                $department
            ),
            default => sprintf('%s record was updated.', $this->employee->full_name),
        };
    }
}
