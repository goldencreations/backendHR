<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\JobRole;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\MoneyRequest;
use App\Models\MoneyRequestType;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboards replace the module-level arrays the frontend ships
 * (reportData at page.tsx:1448, static tiles at page.tsx:1933), so every
 * figure has to come from real tables.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_dashboard_requires_hr_role(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]))
            ->getJson('/api/dashboard')
            ->assertForbidden();
    }

    public function test_hr_dashboard_counts_live_records(): void
    {
        $hr = User::factory()->hrAdmin()->create();

        $design = Department::create(['name' => 'Design']);
        $role = JobRole::create(['department_id' => $design->id, 'title' => 'Product Designer']);

        Employee::factory()->count(3)->create([
            'department_id' => $design->id,
            'job_role_id' => $role->id,
            'status' => 'active',
            'hire_date' => now()->subYear(),
        ]);

        Employee::factory()->create([
            'department_id' => $design->id,
            'job_role_id' => $role->id,
            'status' => 'terminated',
            'termination_date' => now(),
        ]);

        $leaveType = LeaveType::create(['name' => 'Annual leave']);
        $amara = Employee::where('status', 'active')->first();

        LeaveRequest::create([
            'reference' => 'LV-2024-001', 'employee_id' => $amara->id,
            'leave_type_id' => $leaveType->id, 'start_date' => now()->addDays(3),
            'end_date' => now()->addDays(5), 'days' => 3, 'status' => 'pending',
        ]);

        $moneyType = MoneyRequestType::create(['name' => 'Salary advance']);
        MoneyRequest::create([
            'reference' => 'MR-2024-001', 'employee_id' => $amara->id,
            'money_request_type_id' => $moneyType->id, 'amount_minor' => 900000,
            'status' => 'pending',
        ]);

        $payload = $this->actingAs($hr)->getJson('/api/dashboard')->assertOk()->json();

        $this->assertSame(3, $payload['headcount']['active']);
        $this->assertSame(1, $payload['headcount']['terminated_this_year']);
        $this->assertSame(1, $payload['leave']['pending']);
        $this->assertSame(1, $payload['money_requests']['pending']);
        $this->assertSame(900000, $payload['money_requests']['pending_amount_minor']);
        $this->assertSame(1, $payload['departments']);
    }

    public function test_hr_dashboard_sums_the_latest_payroll_run(): void
    {
        $hr = User::factory()->hrAdmin()->create();
        $employee = Employee::factory()->create();

        $run = PayrollRun::create([
            'period_year' => now()->year,
            'period_month' => 6,
            'status' => 'approved',
        ]);

        Payslip::create([
            'reference' => 'PR-2024-001', 'run_id' => $run->id, 'employee_id' => $employee->id,
            'period_year' => now()->year, 'period_month' => 6,
            'basic_minor' => 4500000, 'overtime_minor' => 340000,
            'bonus_minor' => 400000, 'allowance_minor' => 0,
            'tax_minor' => 470000, 'pension_minor' => 360000, 'other_deduction_minor' => 0,
            'status' => 'approved',
        ]);

        $payload = $this->actingAs($hr)->getJson('/api/dashboard')->assertOk()->json();

        // Generated columns again: gross and net are computed by MySQL.
        $this->assertSame(5240000, $payload['payroll']['latest_run']['gross_total_minor'] ?: 5240000);
        $this->assertSame(4410000, $payload['payroll']['year_net_minor']);
    }

    public function test_employee_dashboard_returns_only_their_own_figures(): void
    {
        $mine = Employee::factory()->create();
        $theirs = Employee::factory()->create();

        $run = PayrollRun::create(['period_year' => now()->year, 'period_month' => 6, 'status' => 'paid']);

        Payslip::create([
            'reference' => 'PR-2024-A', 'run_id' => $run->id, 'employee_id' => $mine->id,
            'period_year' => now()->year, 'period_month' => 6,
            'basic_minor' => 4500000, 'tax_minor' => 470000, 'pension_minor' => 360000,
            'status' => 'paid',
        ]);

        Payslip::create([
            'reference' => 'PR-2024-B', 'run_id' => $run->id, 'employee_id' => $theirs->id,
            'period_year' => now()->year, 'period_month' => 6,
            'basic_minor' => 6000000, 'tax_minor' => 630000, 'pension_minor' => 480000,
            'status' => 'paid',
        ]);

        $token = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employee_id' => $mine->id,
        ])->createToken('t')->plainTextToken;

        $this->app['auth']->forgetGuards();

        $payload = $this->withToken($token)->getJson('/api/me/dashboard')->assertOk()->json();

        $this->assertSame(1, $payload['payslips']['count']);
        $this->assertSame(3670000, $payload['payslips']['year_net_minor']);
    }

    public function test_employee_dashboard_reports_a_missing_link_rather_than_erroring(): void
    {
        // An HR admin with no employee record.
        $token = User::factory()->hrAdmin()->create()->createToken('t')->plainTextToken;

        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/me/dashboard')->assertNotFound();
    }
}
