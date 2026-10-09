<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobRole;
use App\Models\MoneyRequest;
use App\Models\MoneyRequestType;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Server-rendered PDFs.
 *
 * Every export in the frontend used to call window.print(), which opened the
 * browser dialog against whatever else was on screen, or produced a .txt
 * file. These assert a real PDF comes back, and that one employee cannot
 * read another's payroll or contract.
 */
class PdfExportTest extends TestCase
{
    use RefreshDatabase;

    private function hr(): User
    {
        return User::factory()->hrAdmin()->create();
    }

    private function employeeWithLogin(string $departmentName = 'Design'): array
    {
        $department = Department::firstOrCreate(['name' => $departmentName]);
        $role = JobRole::firstOrCreate(
            ['department_id' => $department->id, 'title' => 'Product Designer'],
            ['level' => 'mid']
        );

        $employee = Employee::factory()->create([
            'department_id' => $department->id,
            'job_role_id' => $role->id,
        ]);

        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employee_id' => $employee->id,
        ]);

        $employee->forceFill(['user_id' => $user->id])->save();

        return [$employee->fresh(), $user->fresh()];
    }

    private function payslipFor(Employee $employee): Payslip
    {
        $run = PayrollRun::create([
            'period_year' => 2024,
            'period_month' => 6,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        // Generated columns are computed by the database, so the model is
        // re-read rather than relying on the value returned by create().
        return Payslip::create([
            'reference' => 'PR-2024-001',
            'run_id' => $run->id,
            'employee_id' => $employee->id,
            'period_year' => 2024,
            'period_month' => 6,
            'basic_minor' => 4500000,
            'overtime_minor' => 340000,
            'bonus_minor' => 400000,
            'allowance_minor' => 0,
            'tax_minor' => 470000,
            'pension_minor' => 360000,
            'other_deduction_minor' => 0,
            'status' => 'paid',
            'paid_at' => now()->toDateString(),
        ])->fresh();
    }

    /**
     * The core guarantee: a real PDF binary, not a text file with a .pdf
     * name.
     */
    public function test_a_payslip_returns_a_real_pdf_binary(): void
    {
        [$employee, $user] = $this->employeeWithLogin();
        $payslip = $this->payslipFor($employee);

        $response = $this->actingAs($user)
            ->getJson("/api/payslips/{$payslip->id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $body = $response->getContent();

        $this->assertStringStartsWith('%PDF-', $body, 'Output must be a genuine PDF.');
        $this->assertStringContainsString('%%EOF', $body);
        $this->assertGreaterThan(1000, strlen($body));
    }

    public function test_the_filename_is_a_pdf_attachment(): void
    {
        [$employee, $user] = $this->employeeWithLogin();
        $payslip = $this->payslipFor($employee);

        $disposition = $this->actingAs($user)
            ->getJson("/api/payslips/{$payslip->id}/pdf")
            ->assertOk()
            ->headers->get('content-disposition');

        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('.pdf', $disposition);
    }

    public function test_an_employee_cannot_download_another_employees_payslip(): void
    {
        [$owner] = $this->employeeWithLogin();
        $payslip = $this->payslipFor($owner);

        [, $intruder] = $this->employeeWithLogin('Engineering');

        $this->actingAs($intruder)
            ->getJson("/api/payslips/{$payslip->id}/pdf")
            ->assertForbidden();
    }

    public function test_hr_can_download_any_payslip(): void
    {
        [$owner] = $this->employeeWithLogin();
        $payslip = $this->payslipFor($owner);

        $this->actingAs($this->hr())
            ->getJson("/api/payslips/{$payslip->id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_pdf_downloads_require_authentication(): void
    {
        [$owner] = $this->employeeWithLogin();
        $payslip = $this->payslipFor($owner);

        $this->getJson("/api/payslips/{$payslip->id}/pdf")->assertUnauthorized();
    }

    public function test_the_receipt_renders(): void
    {
        [$employee, $user] = $this->employeeWithLogin();

        $type = MoneyRequestType::create(['name' => 'Salary advance']);

        $request = MoneyRequest::create([
            'reference' => 'MR-2024-001',
            'receipt_number' => 'RCP-2024-001',
            'employee_id' => $employee->id,
            'money_request_type_id' => $type->id,
            'amount_minor' => 900000,
            'status' => 'paid',
            'paid_at' => now()->toDateString(),
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/money-requests/{$request->id}/receipt-pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_the_contract_summary_renders(): void
    {
        [$employee, $user] = $this->employeeWithLogin();
        $type = ContractType::create(['name' => 'Full time employment']);

        $contract = Contract::create([
            'reference' => 'GH-2024-018',
            'employee_id' => $employee->id,
            'contract_type_id' => $type->id,
            'start_date' => '2023-05-12',
            'end_date' => '2026-05-11',
            'base_salary_minor' => 4860000,
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->getJson("/api/contracts/{$contract->id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_an_employee_cannot_read_another_employees_contract_pdf(): void
    {
        [$owner] = $this->employeeWithLogin();
        $type = ContractType::create(['name' => 'Full time employment']);

        $contract = Contract::create([
            'reference' => 'GH-2024-100',
            'employee_id' => $owner->id,
            'contract_type_id' => $type->id,
            'start_date' => '2023-05-12',
            'base_salary_minor' => 4860000,
            'status' => 'active',
        ]);

        [, $intruder] = $this->employeeWithLogin('Engineering');

        $this->actingAs($intruder)
            ->getJson("/api/contracts/{$contract->id}/pdf")
            ->assertForbidden();
    }

    public function test_the_employee_record_renders(): void
    {
        [$employee] = $this->employeeWithLogin();

        $this->actingAs($this->hr())
            ->getJson("/api/employees/{$employee->id}/record-pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_an_employee_cannot_export_the_employee_directory(): void
    {
        [$employee, $user] = $this->employeeWithLogin();

        $this->actingAs($user)
            ->getJson("/api/employees/{$employee->id}/record-pdf")
            ->assertForbidden();
    }

    public function test_the_payroll_report_renders(): void
    {
        [$employee] = $this->employeeWithLogin();
        $this->payslipFor($employee);

        $run = PayrollRun::first();

        $this->actingAs($this->hr())
            ->getJson("/api/payroll/runs/{$run->id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_an_employee_cannot_export_a_payroll_report(): void
    {
        [$employee, $user] = $this->employeeWithLogin();
        $this->payslipFor($employee);

        $run = PayrollRun::first();

        $this->actingAs($user)
            ->getJson("/api/payroll/runs/{$run->id}/pdf")
            ->assertForbidden();
    }

    public function test_the_monthly_report_renders(): void
    {
        $this->actingAs($this->hr())
            ->getJson('/api/reports/monthly/pdf?year=2024')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_work_progress_renders_for_the_signed_in_employee(): void
    {
        [$employee, $user] = $this->employeeWithLogin();
        $this->payslipFor($employee);

        $this->actingAs($user)
            ->getJson('/api/me/work-progress/pdf?year=2024')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    /**
     * The report figures come from the tables, not a hard-coded array.
     */
    public function test_monthly_report_json_is_computed_from_live_records(): void
    {
        [$employee] = $this->employeeWithLogin();
        $payslip = $this->payslipFor($employee);

        $payload = $this->actingAs($this->hr())
            ->getJson('/api/reports/monthly?year=2024')
            ->assertOk()
            ->json();

        $this->assertSame(2024, $payload['year']);
        $this->assertCount(12, $payload['rows']);

        $june = collect($payload['rows'])->firstWhere('month_index', 5);

        $this->assertSame('June', $june['month']);
        $this->assertSame($payslip->net_minor, $june['payroll_net']);
        $this->assertSame($payslip->gross_minor, $june['payroll_gross']);
        $this->assertSame($payslip->net_minor, $payload['totals']['payroll_net']);
    }

    public function test_reports_are_hr_only(): void
    {
        [, $user] = $this->employeeWithLogin();

        $this->actingAs($user)->getJson('/api/reports/monthly?year=2024')->assertForbidden();
        $this->actingAs($user)->getJson('/api/reports/monthly/pdf?year=2024')->assertForbidden();
    }
}
