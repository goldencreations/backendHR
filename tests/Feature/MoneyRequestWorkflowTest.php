<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\MoneyRequest;
use App\Models\MoneyRequestType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MoneyRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function employee(): Employee
    {
        return Employee::factory()->create();
    }

    private function user(Employee $employee): User
    {
        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employee_id' => $employee->id,
        ]);

        // Link both directions, as EmployeeService does when it creates a
        // login. Notifications resolve the employee user through user_id.
        $employee->forceFill(['user_id' => $user->id])->save();

        return $user->fresh();
    }

    private function type(string $name = 'Salary advance', bool $receipt = false): MoneyRequestType
    {
        return MoneyRequestType::create([
            'name' => $name,
            'requires_receipt' => $receipt,
        ]);
    }

    private function seedLimit(int $value = 2500000): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => 'advance_limit_minor'],
            ['value' => json_encode($value), 'created_at' => now(), 'updated_at' => now()]
        );
    }

    public function test_an_employee_raises_a_request(): void
    {
        $this->seedLimit();

        $employee = $this->employee();
        $type = $this->type();

        $response = $this->actingAs($this->user($employee))->postJson('/api/money-requests', [
            'money_request_type_id' => $type->id,
            'amount_minor' => 900000,
            'reason' => 'Deposit on a new apartment.',
            'needed_by' => now()->addDays(10)->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        $response->assertCreated()
            ->assertJsonPath('money_request.status', 'pending')
            ->assertJsonPath('money_request.amount_minor', 900000)
            ->assertJsonStructure(['money_request' => ['id', 'reference', 'receipt_number', 'kind', 'status']]);

        $this->assertStringStartsWith('MR-', $response->json('money_request.reference'));
        $this->assertStringStartsWith('RCP-', $response->json('money_request.receipt_number'));
    }

    /**
     * No advance cap is enforced: any positive amount may be requested and
     * HR decides on approval.
     */
    public function test_no_limit_is_enforced(): void
    {
        $this->seedLimit(1000000);

        $employee = $this->employee();
        $type = $this->type();
        $user = $this->user($employee);

        $payload = ['money_request_type_id' => $type->id, 'reason' => 'test'];

        $this->actingAs($user)->postJson('/api/money-requests', array_merge($payload, ['amount_minor' => 900000]))
            ->assertCreated();

        $this->actingAs($user)->postJson('/api/money-requests', array_merge($payload, ['amount_minor' => 2500000]))
            ->assertCreated();
    }

    public function test_a_type_requiring_a_receipt_blocks_a_request_without_one(): void
    {
        $this->seedLimit();

        $employee = $this->employee();
        $type = $this->type('Expense reimbursement', receipt: true);
        $user = $this->user($employee);

        $this->actingAs($user)->postJson('/api/money-requests', [
            'money_request_type_id' => $type->id,
            'amount_minor' => 486000,
        ])->assertStatus(422)
            ->assertJsonPath('message', 'This request type requires a supporting document.');
    }

    public function test_the_approval_flow_reaches_paid(): void
    {
        $this->seedLimit();

        $employee = $this->employee();
        $type = $this->type();
        $hr = User::factory()->hrAdmin()->create();

        $id = $this->actingAs($this->user($employee))->postJson('/api/money-requests', [
            'money_request_type_id' => $type->id,
            'amount_minor' => 900000,
        ])->assertCreated()->json('money_request.id');

        // Cannot mark pending as paid.
        $this->actingAs($hr)->postJson("/api/money-requests/{$id}/mark-paid")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only an approved request can be marked as paid.');

        $this->actingAs($hr)->postJson("/api/money-requests/{$id}/approve", ['note' => 'Against July payroll.'])
            ->assertOk()
            ->assertJsonPath('money_request.status', 'approved');

        $this->actingAs($hr)->postJson("/api/money-requests/{$id}/mark-paid")
            ->assertOk()
            ->assertJsonPath('money_request.status', 'paid');

        $this->assertNotNull(MoneyRequest::findOrFail($id)->paid_at);
    }

    public function test_a_request_cannot_be_decided_twice(): void
    {
        $this->seedLimit();

        $employee = $this->employee();
        $type = $this->type();
        $hr = User::factory()->hrAdmin()->create();

        $id = $this->actingAs($this->user($employee))->postJson('/api/money-requests', [
            'money_request_type_id' => $type->id,
            'amount_minor' => 900000,
        ])->assertCreated()->json('money_request.id');

        $this->actingAs($hr)->postJson("/api/money-requests/{$id}/decline", ['note' => 'No.'])->assertOk();

        $this->actingAs($hr)->postJson("/api/money-requests/{$id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This request has already been decided.');
    }

    public function test_an_employee_cannot_approve_their_own_request(): void
    {
        $this->seedLimit();

        $employee = $this->employee();
        $type = $this->type();
        $user = $this->user($employee);

        $id = $this->actingAs($user)->postJson('/api/money-requests', [
            'money_request_type_id' => $type->id,
            'amount_minor' => 900000,
        ])->assertCreated()->json('money_request.id');

        $this->actingAs($user)->postJson("/api/money-requests/{$id}/approve")->assertStatus(403);
    }

    public function test_an_employee_only_sees_their_own_requests(): void
    {
        $this->seedLimit();

        $type = $this->type();

        $mine = $this->employee();
        $theirs = $this->employee();

        $payload = ['money_request_type_id' => $type->id, 'amount_minor' => 500000];

        $this->actingAs($this->user($mine))->postJson('/api/money-requests', $payload)->assertCreated();
        $this->actingAs($this->user($theirs))->postJson('/api/money-requests', $payload)->assertCreated();

        $listed = $this->actingAs($this->user($mine))->getJson('/api/money-requests')->json('data');

        $this->assertCount(1, $listed);
    }

    public function test_approval_notifies_the_employee(): void {
        $this->seedLimit();

        $employee = $this->employee();
        $type = $this->type();
        $user = $this->user($employee);

        $id = $this->actingAs($user)->postJson('/api/money-requests', [
            'money_request_type_id' => $type->id,
            'amount_minor' => 900000,
        ])->assertCreated()->json('money_request.id');

        $this->assertSame(0, $user->unreadNotifications()->count());

        $this->actingAs(User::factory()->hrAdmin()->create())
            ->postJson("/api/money-requests/{$id}/approve")
            ->assertOk();

        $this->app['auth']->forgetGuards();

        $this->assertSame(1, $user->fresh()->unreadNotifications()->count());
    }

    public function test_hr_can_delete_a_request_from_the_list(): void
    {
        $employee = $this->employee();
        $type = $this->type();

        $id = $this->actingAs($this->user($employee))->postJson('/api/money-requests', [
            'money_request_type_id' => $type->id,
            'amount_minor' => 900000,
        ])->assertCreated()->json('money_request.id');

        $this->actingAs(User::factory()->hrAdmin()->create())
            ->deleteJson("/api/money-requests/{$id}")
            ->assertOk()
            ->assertJsonPath('message', 'Money request deleted.');

        $this->assertDatabaseMissing('money_requests', ['id' => $id]);
    }

    public function test_an_employee_can_delete_their_own_pending_request(): void
    {
        $employee = $this->employee();
        $type = $this->type();
        $user = $this->user($employee);

        $id = $this->actingAs($user)->postJson('/api/money-requests', [
            'money_request_type_id' => $type->id,
            'amount_minor' => 900000,
        ])->assertCreated()->json('money_request.id');

        $this->actingAs($user)->deleteJson("/api/money-requests/{$id}")->assertOk();

        $this->assertDatabaseMissing('money_requests', ['id' => $id]);
    }

    public function test_an_employee_cannot_delete_a_decided_or_foreign_request(): void
    {
        $mine = $this->employee();
        $theirs = $this->employee();
        $type = $this->type();

        $theirsId = $this->actingAs($this->user($theirs))->postJson('/api/money-requests', [
            'money_request_type_id' => $type->id,
            'amount_minor' => 500000,
        ])->assertCreated()->json('money_request.id');

        $this->actingAs($this->user($mine))
            ->deleteJson("/api/money-requests/{$theirsId}")
            ->assertStatus(403);

        $myId = $this->actingAs($this->user($mine))->postJson('/api/money-requests', [
            'money_request_type_id' => $type->id,
            'amount_minor' => 500000,
        ])->assertCreated()->json('money_request.id');

        $this->actingAs(User::factory()->hrAdmin()->create())
            ->postJson("/api/money-requests/{$myId}/approve")
            ->assertOk();

        $this->actingAs($this->user($mine))
            ->deleteJson("/api/money-requests/{$myId}")
            ->assertStatus(403);
    }
}
