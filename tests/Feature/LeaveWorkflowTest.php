<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\JobRole;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function employee(?Department $department = null): Employee
    {
        $department ??= Department::create(['name' => 'Design']);
        $role = JobRole::create(['department_id' => $department->id, 'title' => 'Product Designer']);

        return Employee::factory()->create([
            'department_id' => $department->id,
            'job_role_id' => $role->id,
        ]);
    }

    private function employeeUser(Employee $employee): User
    {
        return User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employee_id' => $employee->id,
        ]);
    }

    private function leaveType(float $allowance = 18): LeaveType
    {
        return LeaveType::create([
            'name' => 'Annual leave',
            'is_paid' => true,
            'annual_allowance_days' => $allowance,
        ]);
    }

    public function test_an_employee_submits_a_leave_request(): void
    {
        $employee = $this->employee();
        $type = $this->leaveType();
        $user = $this->employeeUser($employee);

        $response = $this->actingAs($user)->postJson('/api/leave-requests', [
            'leave_type_id' => $type->id,
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addDays(14)->toDateString(),
            'days' => 5,
            'reason' => 'Family visit in Arusha.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('leave_request.status', 'pending')
            ->assertJsonPath('leave_request.days', 5)
            ->assertJsonStructure(['leave_request' => ['id', 'reference', 'leave_type', 'status', 'approver']]);

        $this->assertStringStartsWith('LV-', $response->json('leave_request.reference'));

        $this->assertDatabaseHas('leave_requests', [
            'employee_id' => $employee->id,
            'status' => 'pending',
            'days' => 5,
        ]);
    }

    /**
     * The allowance is data, not a constant, so a type with 18 days must
     * refuse a 20 day request.
     */
    public function test_requesting_more_than_the_allowance_is_refused(): void
    {
        $employee = $this->employee();
        $type = $this->leaveType(allowance: 18);
        $user = $this->employeeUser($employee);

        $this->actingAs($user)->postJson('/api/leave-requests', [
            'leave_type_id' => $type->id,
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addDays(31)->toDateString(),
            'days' => 20,
        ])->assertStatus(422)->assertJsonPath('message', 'Only 18 days remain, but 20 were requested.');

        $this->assertDatabaseCount('leave_requests', 0);
    }

    /**
     * Two pending requests must not over-commit the same days.
     */
    public function test_booked_days_are_reserved_while_a_request_is_pending(): void
    {
        $employee = $this->employee();
        $type = $this->leaveType(allowance: 10);
        $user = $this->employeeUser($employee);

        $payload = [
            'leave_type_id' => $type->id,
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addDays(14)->toDateString(),
            'days' => 6,
        ];

        $this->actingAs($user)->postJson('/api/leave-requests', $payload)->assertCreated();

        // 10 entitled, 6 booked, so 4 remain.
        $this->actingAs($user)->postJson('/api/leave-requests', array_merge($payload, ['days' => 5]))
            ->assertStatus(422);

        $balances = $this->actingAs($user)->getJson('/api/me/leave-balances')->assertOk()->json('balances');
        $annual = collect($balances)->firstWhere('leave_type', 'Annual leave');

        $this->assertSame(10.0, (float) $annual['entitled_days']);
        $this->assertSame(6.0, (float) $annual['booked_days']);
        $this->assertSame(4.0, (float) $annual['remaining_days']);
    }

    public function test_hr_approving_moves_booked_days_to_used(): void
    {
        $employee = $this->employee();
        $type = $this->leaveType(allowance: 18);
        $user = $this->employeeUser($employee);

        $id = $this->actingAs($user)->postJson('/api/leave-requests', [
            'leave_type_id' => $type->id,
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addDays(14)->toDateString(),
            'days' => 5,
        ])->assertCreated()->json('leave_request.id');

        $this->actingAs(User::factory()->hrAdmin()->create())
            ->postJson("/api/leave-requests/{$id}/approve", ['note' => 'Enjoy your trip.'])
            ->assertOk()
            ->assertJsonPath('leave_request.status', 'approved');

        $balances = $this->actingAs($user)->getJson('/api/me/leave-balances')->json('balances');
        $annual = collect($balances)->firstWhere('leave_type', 'Annual leave');

        $this->assertSame(5.0, (float) $annual['used_days']);
        $this->assertSame(0.0, (float) $annual['booked_days']);
        $this->assertSame(13.0, (float) $annual['remaining_days']);
    }

    public function test_declining_releases_the_reserved_days(): void
    {
        $employee = $this->employee();
        $type = $this->leaveType(allowance: 18);
        $user = $this->employeeUser($employee);

        $id = $this->actingAs($user)->postJson('/api/leave-requests', [
            'leave_type_id' => $type->id,
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addDays(14)->toDateString(),
            'days' => 5,
        ])->assertCreated()->json('leave_request.id');

        $this->actingAs(User::factory()->hrAdmin()->create())
            ->postJson("/api/leave-requests/{$id}/decline", ['note' => 'Quarter close.'])
            ->assertOk()
            ->assertJsonPath('leave_request.status', 'declined');

        $balances = $this->actingAs($user)->getJson('/api/me/leave-balances')->json('balances');
        $annual = collect($balances)->firstWhere('leave_type', 'Annual leave');

        $this->assertSame(0.0, (float) $annual['used_days']);
        $this->assertSame(0.0, (float) $annual['booked_days']);
        $this->assertSame(18.0, (float) $annual['remaining_days']);
    }

    public function test_a_request_cannot_be_decided_twice(): void
    {
        $employee = $this->employee();
        $type = $this->leaveType();
        $user = $this->employeeUser($employee);
        $hr = User::factory()->hrAdmin()->create();

        $id = $this->actingAs($user)->postJson('/api/leave-requests', [
            'leave_type_id' => $type->id,
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addDays(11)->toDateString(),
            'days' => 2,
        ])->assertCreated()->json('leave_request.id');

        $this->actingAs($hr)->postJson("/api/leave-requests/{$id}/approve")->assertOk();

        $this->actingAs($hr)->postJson("/api/leave-requests/{$id}/decline")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This request has already been decided.');
    }

    public function test_an_employee_cannot_approve_their_own_leave(): void
    {
        $employee = $this->employee();
        $type = $this->leaveType();
        $user = $this->employeeUser($employee);

        $id = $this->actingAs($user)->postJson('/api/leave-requests', [
            'leave_type_id' => $type->id,
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addDays(11)->toDateString(),
            'days' => 2,
        ])->assertCreated()->json('leave_request.id');

        $this->actingAs($user)->postJson("/api/leave-requests/{$id}/approve")->assertStatus(403);
    }

    public function test_an_employee_only_sees_their_own_requests(): void
    {
        $type = $this->leaveType();

        // Two distinct departments so each helper call makes a new one.
        $mine = $this->employee(Department::create(['name' => 'Design']));
        $theirs = $this->employee(Department::create(['name' => 'Engineering']));

        $payload = [
            'leave_type_id' => $type->id,
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addDays(11)->toDateString(),
            'days' => 2,
        ];

        $this->actingAs($this->employeeUser($mine))->postJson('/api/leave-requests', $payload)->assertCreated();
        $this->actingAs($this->employeeUser($theirs))->postJson('/api/leave-requests', $payload)->assertCreated();

        $listed = $this->actingAs($this->employeeUser($mine))->getJson('/api/leave-requests')->json('data');

        $this->assertCount(1, $listed);
    }

    public function test_end_date_cannot_precede_start_date(): void
    {
        $employee = $this->employee();
        $type = $this->leaveType();
        $user = $this->employeeUser($employee);

        $this->actingAs($user)->postJson('/api/leave-requests', [
            'leave_type_id' => $type->id,
            'start_date' => now()->addDays(14)->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
            'days' => 2,
        ])->assertStatus(422)->assertJsonPath('message', 'The end date cannot be before the start date.');
    }

    /**
     * A type with no stated allowance is uncapped rather than blocked,
     * since there is nothing to enforce.
     */
    public function test_a_type_without_an_entitlement_is_not_capped(): void
    {
        $employee = $this->employee();
        $type = LeaveType::create(['name' => 'Bereavement', 'is_paid' => false, 'annual_allowance_days' => null]);
        $user = $this->employeeUser($employee);

        $this->actingAs($user)->postJson('/api/leave-requests', [
            'leave_type_id' => $type->id,
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addDays(20)->toDateString(),
            'days' => 11,
        ])->assertCreated();
    }

    public function test_withdrawing_a_pending_request_releases_the_days(): void
    {
        $employee = $this->employee();
        $type = $this->leaveType(allowance: 18);
        $user = $this->employeeUser($employee);

        $id = $this->actingAs($user)->postJson('/api/leave-requests', [
            'leave_type_id' => $type->id,
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addDays(14)->toDateString(),
            'days' => 5,
        ])->assertCreated()->json('leave_request.id');

        $this->actingAs($user)->deleteJson("/api/leave-requests/{$id}")->assertOk();

        $balances = $this->actingAs($user)->getJson('/api/me/leave-balances')->json('balances');
        $annual = collect($balances)->firstWhere('leave_type', 'Annual leave');

        $this->assertSame(0.0, (float) $annual['booked_days']);
    }
}
