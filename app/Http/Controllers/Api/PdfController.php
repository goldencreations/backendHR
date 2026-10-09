<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\MoneyRequest;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Services\PdfService;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-rendered PDFs, replacing the window.print() calls in the frontend.
 *
 * Every route here is reachable from a real file, so the output is stored
 * and reproducible rather than dependent on whatever else is on screen.
 */
class PdfController extends Controller
{
    public function __construct(
        private readonly PdfService $pdf,
        private readonly ReportService $reports,
    ) {}

    public function payslip(Request $request, Payslip $payslip): Response
    {
        $this->authorizePayslip($request, $payslip);

        $payslip->load(['employee.department', 'employee.jobRole']);

        return $this->pdf->render(
            'payslip',
            ['payslip' => $payslip],
            sprintf('payslip-%s-%s', $payslip->period_year, $payslip->reference)
        );
    }

    public function payrollReport(Request $request, PayrollRun $run): Response
    {
        abort_unless($request->user()->isHr(), 403, 'Payroll reports are restricted to HR staff.');

        $run->load(['payslips' => fn ($q) => $q->with('employee.department')]);

        return $this->pdf->render(
            'payroll-report',
            [
                'run' => $run,
                'payslips' => $run->payslips,
                'period_label' => now()->setYear($run->period_year)->setMonth($run->period_month)->format('F Y'),
            ],
            sprintf('payroll-report-%04d-%02d', $run->period_year, $run->period_month),
            'landscape'
        );
    }

    public function receipt(Request $request, MoneyRequest $moneyRequest): Response
    {
        Gate::authorize('view', $moneyRequest);

        $moneyRequest->load(['employee.department', 'type', 'decidedBy']);

        return $this->pdf->render(
            'receipt',
            ['request' => $moneyRequest],
            sprintf('receipt-%s', $moneyRequest->receipt_number ?: $moneyRequest->reference)
        );
    }

    public function contract(Request $request, Contract $contract): Response
    {
        $isOwner = (int) $contract->employee_id === (int) $request->user()->employee_id;

        abort_unless($isOwner || $request->user()->isHr(), 403, 'This contract belongs to another employee.');

        $contract->load(['employee.department', 'employee.jobRole', 'contractType']);

        return $this->pdf->render(
            'contract',
            ['contract' => $contract],
            sprintf('contract-%s', $contract->reference)
        );
    }

    public function employeeRecord(Request $request, Employee $employee): Response
    {
        abort_unless($request->user()->isHr(), 403, 'Employee records are restricted to HR staff.');

        $employee->load(['department', 'jobRole']);

        return $this->pdf->render(
            'employee-record',
            [
                'employee' => $employee,
                'bankAccounts' => $employee->bankAccounts()->get(),
                'documents' => $employee->documents()->with('category')->get(),
            ],
            sprintf('employee-record-%s', $employee->employee_code)
        );
    }

    /**
     * The employee portal's own progress analysis.
     */
    public function workProgress(Request $request): Response
    {
        $employee = Employee::find($request->user()->employee_id);

        abort_if(! $employee, 404, 'No employee record is linked to this account.');

        $year = (int) $request->integer('year', now()->year);

        $payslips = Payslip::where('employee_id', $employee->id)
            ->where('period_year', $year)
            ->orderBy('period_month')
            ->get();

        $leaves = LeaveRequest::where('employee_id', $employee->id)
            ->whereYear('start_date', $year)
            ->with('leaveType')
            ->orderBy('start_date')
            ->get();

        $moneyRequests = MoneyRequest::where('employee_id', $employee->id)->count();

        $summary = [
            'payslip_count' => $payslips->count(),
            'year_net_minor' => (int) $payslips->sum('net_minor'),
            'leave_days' => (float) $leaves->where('status', 'approved')->sum('days'),
            'leave_requests' => $leaves->count(),
            'money_requested_minor' => (int) MoneyRequest::where('employee_id', $employee->id)->sum('amount_minor'),
            'money_requests' => $moneyRequests,
        ];

        return $this->pdf->render(
            'work-progress',
            [
                'employee' => $employee,
                'year' => $year,
                'payslips' => $payslips,
                'leaves' => $leaves,
                'summary' => $summary,
            ],
            sprintf('work-progress-%s-%d', $employee->employee_code, $year)
        );
    }

    public function monthlyReport(Request $request): Response
    {
        abort_unless($request->user()->isHr(), 403, 'Reports are restricted to HR staff.');

        $year = (int) $request->integer('year', now()->year);
        $data = $this->reports->monthly($year);

        return $this->pdf->render(
            'monthly-report',
            ['year' => $year, 'rows' => $data['rows'], 'totals' => $data['totals']],
            sprintf('monthly-report-%d', $year),
            'landscape'
        );
    }

    /**
     * JSON figures for the on-screen charts, so the graphs stop reading from
     * the hard-coded reportData array.
     */
    public function monthlyJson(Request $request): JsonResponse
    {
        abort_unless($request->user()->isHr(), 403);

        $year = (int) $request->integer('year', now()->year);

        return response()->json($this->reports->monthlyJson($year) + ['year' => $year]);
    }

    private function authorizePayslip(Request $request, Payslip $payslip): void
    {
        $isOwner = (int) $payslip->employee_id === (int) $request->user()->employee_id;

        abort_unless($isOwner || $request->user()->isHr(), 403, 'This payslip belongs to another employee.');
    }
}
