<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function hr(): User
    {
        return User::factory()->hrAdmin()->create();
    }

    private function employeeUser(Employee $employee): User
    {
        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employee_id' => $employee->id,
        ]);

        $employee->forceFill(['user_id' => $user->id])->save();

        return $user->fresh();
    }

    public function test_hr_creates_a_contract_and_the_duration_derives_the_end_date(): void
    {
        $employee = Employee::factory()->create();
        $type = ContractType::create(['name' => 'Full time employment']);

        $response = $this->actingAs($this->hr())->postJson('/api/contracts', [
            'employee_id' => $employee->id,
            'contract_type_id' => $type->id,
            'start_date' => '2023-05-12',
            'duration_days' => 1095,
            'base_salary_minor' => 4860000,
            'description' => 'Scope of duties.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('contract.reference', 'GH-2026-001')
            ->assertJsonPath('contract.status', 'draft')
            ->assertJsonPath('contract.open_ended', false);

        $contract = Contract::firstOrFail();
        $this->assertSame('2026-05-11', $contract->end_date->toDateString());
    }

    /**
     * The UI encodes open-ended as a zero duration; it must become a null
     * end date rather than a magic zero.
     */
    public function test_an_open_ended_contract_has_a_null_end_date(): void
    {
        $employee = Employee::factory()->create();
        $type = ContractType::create(['name' => 'Full time employment']);

        $response = $this->actingAs($this->hr())->postJson('/api/contracts', [
            'employee_id' => $employee->id,
            'contract_type_id' => $type->id,
            'start_date' => '2023-05-12',
            'base_salary_minor' => 4860000,
        ])->assertCreated();

        $response->assertJsonPath('contract.open_ended', true)
            ->assertJsonPath('contract.end_date', null);

        $this->assertNull(Contract::firstOrFail()->end_date);
    }

    public function test_the_signature_flow_moves_draft_to_active(): void
    {
        $employee = Employee::factory()->create();
        $type = ContractType::create(['name' => 'Full time employment']);
        $hr = $this->hr();

        $id = $this->actingAs($hr)->postJson('/api/contracts', [
            'employee_id' => $employee->id,
            'contract_type_id' => $type->id,
            'start_date' => '2023-05-12',
            'base_salary_minor' => 4860000,
        ])->assertCreated()->json('contract.id');

        $this->actingAs($hr)->postJson("/api/contracts/{$id}/send-for-signature")
            ->assertOk()
            ->assertJsonPath('contract.status', 'pending_signature');

        $token = $this->employeeUser($employee)->createToken('t')->plainTextToken;
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->postJson("/api/contracts/{$id}/respond", ['decision' => 'accepted'])
            ->assertOk()
            ->assertJsonPath('contract.status', 'active');

        $this->assertNotNull(Contract::findOrFail($id)->signed_at);
    }

    public function test_an_employee_cannot_sign_someone_elses_contract(): void
    {
        $owner = Employee::factory()->create();
        $intruder = Employee::factory()->create();
        $type = ContractType::create(['name' => 'Full time employment']);
        $hr = $this->hr();

        $id = $this->actingAs($hr)->postJson('/api/contracts', [
            'employee_id' => $owner->id,
            'contract_type_id' => $type->id,
            'start_date' => '2023-05-12',
            'base_salary_minor' => 4860000,
        ])->assertCreated()->json('contract.id');

        $this->actingAs($hr)->postJson("/api/contracts/{$id}/send-for-signature")->assertOk();

        $token = $this->employeeUser($intruder)->createToken('t')->plainTextToken;
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->postJson("/api/contracts/{$id}/respond", ['decision' => 'accepted'])
            ->assertForbidden();
    }

    public function test_an_employee_only_sees_their_own_contracts(): void
    {
        $type = ContractType::create(['name' => 'Full time employment']);
        $hr = $this->hr();

        $mine = Employee::factory()->create();
        $theirs = Employee::factory()->create();

        foreach ([$mine, $theirs] as $employee) {
            $this->actingAs($hr)->postJson('/api/contracts', [
                'employee_id' => $employee->id,
                'contract_type_id' => $type->id,
                'start_date' => '2023-05-12',
                'base_salary_minor' => 1000000,
            ])->assertCreated();
        }

        $listed = $this->actingAs($this->employeeUser($mine))->getJson('/api/contracts')->json('data');

        $this->assertCount(1, $listed);
    }

    public function test_renewals_due_counts_contracts_expiring_within_sixty_days(): void
    {
        $employee = Employee::factory()->create();
        $type = ContractType::create(['name' => 'Fixed term contract']);
        $hr = $this->hr();

        Contract::create([
            'reference' => 'GH-2026-100', 'employee_id' => $employee->id,
            'contract_type_id' => $type->id,
            'start_date' => now()->subMonths(11)->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
            'base_salary_minor' => 3920000, 'status' => 'active',
        ]);

        Contract::create([
            'reference' => 'GH-2026-101', 'employee_id' => $employee->id,
            'contract_type_id' => $type->id,
            'start_date' => now()->subYears(2)->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'base_salary_minor' => 3920000, 'status' => 'active',
        ]);

        $meta = $this->actingAs($hr)->getJson('/api/contracts')->json('meta');

        // Only the one expiring inside the window.
        $this->assertSame(1, $meta['renewals_upcoming']);
    }

    public function test_sending_for_signature_notifies_the_employee(): void
    {
        $employee = Employee::factory()->create();
        $type = ContractType::create(['name' => 'Full time employment']);
        $hr = $this->hr();
        $employeeUser = $this->employeeUser($employee);

        $id = $this->actingAs($hr)->postJson('/api/contracts', [
            'employee_id' => $employee->id,
            'contract_type_id' => $type->id,
            'start_date' => '2023-05-12',
            'base_salary_minor' => 4860000,
        ])->assertCreated()->json('contract.id');

        $this->assertSame(0, $employeeUser->unreadNotifications()->count());

        $this->actingAs($hr)->postJson("/api/contracts/{$id}/send-for-signature")->assertOk();

        $this->assertSame(1, $employeeUser->fresh()->unreadNotifications()->count());
    }
}
