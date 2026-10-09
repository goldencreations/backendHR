<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Payslip;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PayrollWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function hr(): User
    {
        return User::factory()->hrAdmin()->create();
    }

    /**
     * Amara's June 2024 figures exactly as the frontend mock defines them
     * at page.tsx:518: 4,500,000 + 340,000 + 400,000 = 5,240,000 gross,
     * 470,000 + 360,000 = 830,000 deductions, 4,410,000 net.
     */
    public function test_totals_come_from_the_database_generated_columns(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($this->hr())->postJson('/api/payroll/runs', [
            'period_year' => 2024,
            'period_month' => 6,
            'lines' => [[
                'employee_id' => $employee->id,
                'basic_minor' => 4500000,
                'overtime_minor' => 340000,
                'bonus_minor' => 400000,
                'allowance_minor' => 0,
                'tax_minor' => 470000,
                'pension_minor' => 360000,
                'other_deduction_minor' => 0,
            ]],
        ])->assertCreated()
            ->assertJsonPath('run.gross_total_minor', 5240000)
            ->assertJsonPath('run.net_total_minor', 4410000)
            ->assertJsonPath('run.payslip_count', 1);

        $payslip = Payslip::firstOrFail();

        $this->assertSame(5240000, $payslip->gross_minor);
        $this->assertSame(830000, $payslip->total_deductions_minor);
        $this->assertSame(4410000, $payslip->net_minor);
    }

    /**
     * The API must not be able to write the totals directly, or the
     * database guarantee is lost.
     */
    public function test_generated_columns_cannot_be_overwritten(): void
    {
        $employee = Employee::factory()->create();

        $run = $this->actingAs($this->hr())->postJson('/api/payroll/runs', [
            'period_year' => 2024,
            'period_month' => 6,
            'lines' => [[
                'employee_id' => $employee->id,
                'basic_minor' => 1000000,
                'tax_minor' => 100000,
            ]],
        ])->assertCreated()->json('run.id');

        $payslip = Payslip::firstOrFail();

        $this->expectException(QueryException::class);

        // Writing to a generated column is rejected by MySQL.
        DB::table('payslips')
            ->where('id', $payslip->id)
            ->update(['net_minor' => 9999999]);
    }

    public function test_a_run_cannot_be_created_twice_for_the_same_period(): void
    {
        $employee = Employee::factory()->create();
        $hr = $this->hr();

        $payload = [
            'period_year' => 2024,
            'period_month' => 6,
            'lines' => [['employee_id' => $employee->id, 'basic_minor' => 1000000]],
        ];

        $this->actingAs($hr)->postJson('/api/payroll/runs', $payload)->assertCreated();

        $this->actingAs($hr)->postJson('/api/payroll/runs', $payload)
            ->assertStatus(422)
            ->assertJsonPath('message', 'A payroll run already exists for 2024-06.');
    }

    public function test_the_approval_flow_moves_a_run_to_paid(): void
    {
        $employee = Employee::factory()->create();
        $hr = $this->hr();

        $runId = $this->actingAs($hr)->postJson('/api/payroll/runs', [
            'period_year' => 2024,
            'period_month' => 6,
            'lines' => [['employee_id' => $employee->id, 'basic_minor' => 4500000, 'tax_minor' => 470000]],
        ])->assertCreated()->json('run.id');

        // Cannot be paid before approval.
        $this->actingAs($hr)->postJson("/api/payroll/runs/{$runId}/pay")
            ->assertStatus(422)
            ->assertJsonPath('message', 'The run must be approved before it can be paid.');

        $this->actingAs($hr)->postJson("/api/payroll/runs/{$runId}/approve")
            ->assertOk()
            ->assertJsonPath('run.status', 'approved');

        // Every payslip follows the run.
        $this->assertSame('approved', Payslip::firstOrFail()->status);

        $this->actingAs($hr)->postJson("/api/payroll/runs/{$runId}/pay")
            ->assertOk()
            ->assertJsonPath('run.status', 'paid');

        $payslip = Payslip::firstOrFail()->fresh();
        $this->assertSame('paid', $payslip->status);
        $this->assertNotNull($payslip->paid_at);
    }

    public function test_a_run_cannot_be_approved_twice(): void
    {
        $employee = Employee::factory()->create();
        $hr = $this->hr();

        $runId = $this->actingAs($hr)->postJson('/api/payroll/runs', [
            'period_year' => 2024,
            'period_month' => 7,
            'lines' => [['employee_id' => $employee->id, 'basic_minor' => 1000000]],
        ])->assertCreated()->json('run.id');

        $this->actingAs($hr)->postJson("/api/payroll/runs/{$runId}/approve")->assertOk();

        $this->actingAs($hr)->postJson("/api/payroll/runs/{$runId}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only a run awaiting approval can be approved.');
    }

    public function test_an_employee_only_sees_their_own_payslips(): void
    {
        $mine = Employee::factory()->create();
        $theirs = Employee::factory()->create();
        $hr = $this->hr();

        $runId = $this->actingAs($hr)->postJson('/api/payroll/runs', [
            'period_year' => 2024,
            'period_month' => 6,
            'lines' => [
                ['employee_id' => $mine->id, 'basic_minor' => 4500000, 'tax_minor' => 470000],
                ['employee_id' => $theirs->id, 'basic_minor' => 6000000, 'tax_minor' => 630000],
            ],
        ])->assertCreated()->json('run.id');

        // HR sees both and the combined net.
        $hrView = $this->actingAs($hr)->getJson('/api/payslips')->assertOk();
        $this->assertCount(2, $hrView->json('data'));

        $token = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employee_id' => $mine->id,
        ])->createToken('t')->plainTextToken;

        $this->app['auth']->forgetGuards();

        $employeeView = $this->withToken($token)->getJson('/api/payslips')->assertOk();

        $this->assertCount(1, $employeeView->json('data'));
        // Only their own net, not the run total.
        $this->assertSame(4030000, $employeeView->json('meta.net_total_minor'));
    }

    public function test_only_hr_can_manage_payroll(): void
    {
        $employee = Employee::factory()->create();
        $token = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employee_id' => $employee->id,
        ])->createToken('t')->plainTextToken;

        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/payroll/runs')->assertForbidden();

        $this->withToken($token)->postJson('/api/payroll/runs', [
            'period_year' => 2024,
            'period_month' => 6,
            'lines' => [['employee_id' => $employee->id, 'basic_minor' => 1]],
        ])->assertForbidden();
    }
}
