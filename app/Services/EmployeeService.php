<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\JobRole;
use App\Models\User;
use App\Notifications\EmployeeRecordChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * Creates and updates an employee together with the records that must move
 * in step: the portal login and the current role assignment.
 *
 * Everything happens in one transaction. The frontend employee form
 * (page.tsx:229) submits a profile, a role and a password together, and a
 * half-written employee with no way to sign in is worse than a rejected
 * request.
 */
class EmployeeService
{
    public function __construct(private readonly ReferenceGenerator $references) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?string $plainPassword = null, ?User $actor = null): Employee
    {
        return DB::transaction(function () use ($data, $plainPassword, $actor) {
            [$first, $last] = $this->splitName((string) $data['name']);

            $employee = Employee::create([
                'employee_code' => $data['employee_code'] ?? $this->references->employeeCode(),
                'first_name' => $first,
                'last_name' => $last,
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'tin' => $data['tin'] ?? null,
                'nida_number' => $data['nida_number'] ?? null,
                'address' => $data['address'] ?? null,
                'emergency_contact_name' => $data['emergency_contact_name'] ?? null,
                'emergency_contact_phone' => $data['emergency_contact_phone'] ?? null,
                'department_id' => $data['department_id'] ?? null,
                'job_role_id' => $data['job_role_id'] ?? null,
                'employment_type' => $data['employment_type'] ?? 'full_time',
                'start_date' => $data['start_date'] ?? null,
                'hire_date' => $data['hire_date'] ?? null,
                'base_salary_minor' => $data['base_salary_minor'] ?? 0,
                'currency' => $data['currency'] ?? 'TZS',
                'status' => $data['status'] ?? 'active',
            ]);

            if (! empty($data['job_role_id'])) {
                $this->syncCurrentAssignment($employee, (int) $data['job_role_id']);
            }

            // Creating the login is what makes the password in the form
            // usable; without this the employee exists but cannot sign in.
            if ($plainPassword !== null && $plainPassword !== '') {
                $this->createPortalUser($employee, $plainPassword);
            }

            if ($actor) {
                $this->logAction($employee, $actor, 'created');
            }

            return $employee->fresh(['department', 'jobRole', 'user']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Employee $employee, array $data, ?User $actor = null): Employee
    {
        return DB::transaction(function () use ($employee, $data, $actor) {
            if (isset($data['name'])) {
                [$first, $last] = $this->splitName((string) $data['name']);
                $data['first_name'] = $first;
                $data['last_name'] = $last;
                unset($data['name']);
            }

            $roleChanged = array_key_exists('job_role_id', $data)
                && (int) ($data['job_role_id'] ?? 0) !== (int) $employee->job_role_id;

            $employee->fill($data);
            $employee->save();

            if ($roleChanged && $employee->job_role_id) {
                $this->syncCurrentAssignment($employee->fresh(), (int) $employee->job_role_id);
            }

            if ($actor) {
                $this->logAction($employee->fresh(), $actor, $roleChanged ? 'role_changed' : 'updated');
            }

            return $employee->fresh(['department', 'jobRole', 'user']);
        });
    }

    /**
     * Closes the current assignment and opens a new one, keeping the history
     * the notifications feed refers to ("Maya Patel moved from Frontend
     * Engineer to Engineering Lead").
     *
     * MySQL cannot express a partial unique index, so the one-current-row
     * rule is enforced here, inside the transaction.
     */
    public function syncCurrentAssignment(Employee $employee, int $jobRoleId): EmployeeAssignment
    {
        $role = JobRole::findOrFail($jobRoleId);

        $existing = EmployeeAssignment::where('employee_id', $employee->id)
            ->where('is_current', true)
            ->lockForUpdate()
            ->first();

        if ($existing && (int) $existing->job_role_id === $jobRoleId) {
            return $existing;
        }

        if ($existing) {
            $existing->update([
                'is_current' => false,
                'effective_to' => now()->subDay(),
            ]);
        }

        return EmployeeAssignment::create([
            'employee_id' => $employee->id,
            'job_role_id' => $role->id,
            'department_id' => $role->department_id,
            'is_current' => true,
            'effective_from' => now(),
        ]);
    }

    /**
     * Creates the portal login. A supplied email is reused as the login
     * address, which is what the form shows the HR user.
     */
    public function createPortalUser(Employee $employee, string $plainPassword): User
    {
        if (User::where('email', $employee->email)->exists()) {
            throw new RuntimeException('A login already exists for '.$employee->email.'.');
        }

        $user = User::create([
            'name' => $employee->full_name,
            'email' => $employee->email,
            // Cast to 'hashed' by the User model.
            'password' => $plainPassword,
            'role' => User::ROLE_EMPLOYEE,
            'employee_id' => $employee->id,
            'is_active' => true,
        ]);

        $employee->forceFill(['user_id' => $user->id])->save();

        return $user;
    }

    /**
     * HR resetting someone's portal password from the employee form. Both
     * fields must be left blank to keep the current password, matching the
     * behaviour the form describes at page.tsx:252.
     */
    public function resetPortalPassword(Employee $employee, ?string $plainPassword): bool
    {
        if ($plainPassword === null || $plainPassword === '') {
            return false;
        }

        $user = $employee->user;

        if (! $user) {
            $this->createPortalUser($employee, $plainPassword);

            return true;
        }

        $user->forceFill(['password' => $plainPassword])->save();

        // A reset should end sessions opened with the old password.
        $user->tokens()->delete();

        return true;
    }

    /**
     * Terminates rather than deletes: payroll and document rows reference
     * this person and must keep their history.
     */
    public function terminate(Employee $employee, ?string $reason = null): Employee
    {
        return DB::transaction(function () use ($employee) {
            $employee->update([
                'status' => 'terminated',
                'termination_date' => now(),
            ]);

            $employee->assignments()->where('is_current', true)->update([
                'is_current' => false,
                'effective_to' => now(),
            ]);

            $employee->user?->forceFill(['is_active' => false])->save();
            $employee->user?->tokens()->delete();

            return $employee;
        });
    }

    /**
     * The form supplies a single "name" field; the schema stores the parts
     * separately so people can be sorted and searched.
     */
    private function splitName(string $full): array
    {
        $parts = preg_split('/\s+/', trim($full)) ?: [];
        $parts = array_values(array_filter($parts, fn ($p) => $p !== ''));

        if ($parts === []) {
            return ['', ''];
        }

        $first = array_shift($parts);

        return [$first, implode(' ', $parts)];
    }

    private function logAction(Employee $employee, User $actor, string $action): void
    {
        Notification::send(
            [$actor],
            new EmployeeRecordChanged($employee, $action)
        );
    }
}
