<?php

namespace App\Services;

use App\Models\DepartmentLead;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Notifications\LeaveRequestDecided;
use App\Notifications\LeaveRequestSubmitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * Leave booking, approval and balance tracking.
 *
 * The allowance is deliberately not a constant. The mock data disagreed with
 * itself (18 days on the approval screen, 21 on the employee dashboard), so
 * the entitlement lives on leave_types and can be set per type and year.
 */
class LeaveService
{
    /**
     * Books leave for an employee, reserving the days against their balance.
     */
    public function submit(Employee $employee, array $data): LeaveRequest
    {
        return DB::transaction(function () use ($employee, $data) {
            $type = LeaveType::findOrFail($data['leave_type_id']);
            $days = (float) $data['days'];

            if ($days <= 0) {
                throw new RuntimeException('Leave must be at least one day.');
            }

            if (strtotime((string) $data['end_date']) < strtotime((string) $data['start_date'])) {
                throw new RuntimeException('The end date cannot be before the start date.');
            }

            // The balance belongs to the year the leave starts in, not the year the
            // request happens to be submitted.
            $year = (int) strtotime((string) $data['start_date']) > 0
                ? (int) date('Y', strtotime((string) $data['start_date']))
                : (int) now()->format('Y');

            $balance = $this->balanceFor($employee, $type, $year);

            // Uncapped leave types (unpaid, or types with no stated
            // allowance) skip the balance check rather than being blocked by
            // a null entitlement.
            if ((float) $balance->entitled_days > 0 && $this->availableOn($balance) < $days) {
                throw new RuntimeException(sprintf(
                    'Only %s days remain, but %s were requested.',
                    rtrim(rtrim(number_format($this->availableOn($balance), 2), '0'), '.'),
                    rtrim(rtrim(number_format($days, 2), '0'), '.')
                ));
            }

            $request = LeaveRequest::create([
                'reference' => app(ReferenceGenerator::class)->next('leave_requests', 'LV'),
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'days' => $days,
                'reason' => $data['reason'] ?? null,
                'coverage_employee_id' => $data['coverage_employee_id'] ?? null,
                'status' => 'pending',
            ]);

            // Booked immediately so a second request cannot over-commit the
            // same days before the first is decided.
            $balance->increment('booked_days', $days);

            $this->notifyApprovers($request);

            return $request->fresh(['leaveType', 'employee', 'coverageEmployee', 'approver']);
        });
    }

    public function approve(LeaveRequest $request, User $approver, ?string $note = null): LeaveRequest
    {
        return $this->decide($request, 'approved', $approver, $note);
    }

    public function decline(LeaveRequest $request, User $approver, ?string $note = null): LeaveRequest
    {
        return $this->decide($request, 'declined', $approver, $note);
    }

    private function decide(LeaveRequest $request, string $status, User $approver, ?string $note): LeaveRequest
    {
        if ($request->status !== 'pending') {
            throw new RuntimeException('This request has already been decided.');
        }

        return DB::transaction(function () use ($request, $status, $approver, $note) {
            $request->update([
                'status' => $status,
                'approver_id' => $approver->id,
                'decided_at' => now(),
                'reviewer_note' => $note,
            ]);

            $balance = $this->balanceFor(
                $request->employee,
                $request->leaveType,
                (int) $request->start_date->format('Y'),
            );

            // Booked days become used on approval, or are released on decline.
            $balance->decrement('booked_days', (float) $request->days);

            if ($status === 'approved') {
                $balance->increment('used_days', (float) $request->days);
            }

            if ($request->employee->user) {
                Notification::send($request->employee->user, new LeaveRequestDecided($request, $status, $note));
            }

            return $request->fresh(['leaveType', 'employee', 'approver']);
        });
    }

    /**
     * Reads or creates the balance row for an employee, type and year.
     */
    public function balanceFor(Employee $employee, LeaveType $type, int $year): LeaveBalance
    {
        return LeaveBalance::firstOrCreate(
            [
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'year' => $year,
            ],
            [
                'entitled_days' => $type->annual_allowance_days ?? 0,
                'used_days' => 0,
                'booked_days' => 0,
            ]
        );
    }

    public function availableOn(LeaveBalance $balance): float
    {
        return max(
            0,
            (float) $balance->entitled_days - (float) $balance->used_days - (float) $balance->booked_days
        );
    }

    /**
     * The approver is the department lead when one is set, otherwise any
     * active HR user, so an unassigned department still has a queue.
     */
    private function notifyApprovers(LeaveRequest $request): void
    {
        $lead = DepartmentLead::where('department_id', $request->employee->department_id)
            ->where('is_primary', true)
            ->with('employee.user')
            ->first();

        $recipients = [];

        if ($lead?->employee?->user) {
            $recipients[] = $lead->employee->user;
        }

        $request->update(['approver_id' => $lead?->employee?->user?->id]);

        $hrUsers = User::whereIn('role', [User::ROLE_HR_ADMIN, User::ROLE_HR_OFFICER])
            ->where('is_active', true)
            ->get();

        $targets = $hrUsers->concat(collect($recipients))->unique('id');

        if ($targets->isNotEmpty()) {
            Notification::send($targets, new LeaveRequestSubmitted($request));
        }
    }
}
