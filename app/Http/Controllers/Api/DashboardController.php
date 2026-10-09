<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\LeaveRequest;
use App\Models\MoneyRequest;
use App\Models\Payslip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Counts and totals for the two dashboards, computed from live tables.
 *
 * Replaces the module-level arrays the frontend currently ships
 * (reportData at page.tsx:1448 and the static tiles at page.tsx:1933).
 */
class DashboardController extends Controller
{
    public function hr(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isHr(), 403);

        $activeEmployees = Employee::where('status', 'active')->count();
        $pendingLeave = LeaveRequest::where('status', 'pending')->count();
        $pendingMoney = MoneyRequest::where('status', 'pending')->count();

        $year = (int) $request->integer('year', now()->year);

        // Net pay for the most recent payroll run.
        $latestRun = DB::table('payroll_runs')
            ->where('period_year', $year)
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->first();

        $contractStats = Contract::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $renewalsDue = Contract::where('status', 'renewal_due')->count()
            + Contract::whereNotNull('end_date')
                ->where('status', 'active')
                ->whereBetween('end_date', [now()->toDateString(), now()->addDays(60)->toDateString()])
                ->count();

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'year' => $year,
            'headcount' => [
                'active' => $activeEmployees,
                'on_leave' => Employee::where('status', 'on_leave')->count(),
                'suspended' => Employee::where('status', 'suspended')->count(),
                'terminated_this_year' => Employee::where('status', 'terminated')
                    ->whereYear('termination_date', $year)
                    ->count(),
                'joined_this_year' => Employee::whereYear('hire_date', $year)->count(),
            ],
            'departments' => Department::where('is_active', true)->count(),
            'leave' => [
                'pending' => $pendingLeave,
                'approved_this_month' => LeaveRequest::where('status', 'approved')
                    ->whereYear('created_at', $year)
                    ->whereMonth('created_at', now()->month)
                    ->count(),
                'declined_this_month' => LeaveRequest::where('status', 'declined')
                    ->whereYear('created_at', $year)
                    ->whereMonth('created_at', now()->month)
                    ->count(),
                'on_leave_today' => DB::table('leave_requests')
                    ->where('status', 'approved')
                    ->whereDate('start_date', '<=', now()->toDateString())
                    ->whereDate('end_date', '>=', now()->toDateString())
                    ->distinct()
                    ->count('employee_id'),
            ],
            'payroll' => [
                'latest_run' => $latestRun ? [
                    'period' => sprintf('%04d-%02d', $latestRun->period_year, $latestRun->period_month),
                    'status' => $latestRun->status,
                    'gross_total_minor' => (int) $latestRun->gross_total_minor,
                    'net_total_minor' => (int) $latestRun->net_total_minor,
                    'payslip_count' => (int) $latestRun->payslip_count,
                ] : null,
                'year_net_minor' => (int) Payslip::where('period_year', $year)->sum('net_minor'),
                'year_gross_minor' => (int) Payslip::where('period_year', $year)->sum('gross_minor'),
            ],
            'money_requests' => [
                'pending' => $pendingMoney,
                'pending_amount_minor' => (int) MoneyRequest::where('status', 'pending')->sum('amount_minor'),
                'paid_this_year_minor' => (int) MoneyRequest::where('status', 'paid')
                    ->whereYear('paid_at', $year)
                    ->sum('amount_minor'),
            ],
            'contracts' => [
                'active' => (int) ($contractStats['active'] ?? 0),
                'renewal_due' => $renewalsDue,
                'pending_signature' => (int) ($contractStats['pending_signature'] ?? 0),
            ],
            'documents' => [
                'total' => EmployeeDocument::count(),
                'pending_review' => EmployeeDocument::where('status', 'pending_review')->count(),
                'expiring_soon' => EmployeeDocument::where('status', 'expiring_soon')->count(),
            ],
        ]);
    }

    /**
     * The employee portal dashboard: their own figures only.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = Employee::find($user->employee_id);

        if (! $employee) {
            return response()->json([
                'message' => 'No employee record is linked to this account.',
            ], 404);
        }

        $year = (int) $request->integer('year', now()->year);

        $payslips = Payslip::where('employee_id', $employee->id)
            ->where('period_year', $year)
            ->orderByDesc('period_month')
            ->get(['period_year', 'period_month', 'net_minor', 'status']);

        $leaveBooked = LeaveRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereYear('start_date', $year)
            ->sum('days');

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'year' => $year,
            'payslips' => [
                'count' => $payslips->count(),
                'latest_net_minor' => (int) ($payslips->first()->net_minor ?? 0),
                'year_net_minor' => (int) $payslips->sum('net_minor'),
                'series' => $payslips->reverse()->values()->map(fn ($p) => [
                    'month' => sprintf('%04d-%02d', $p->period_year, $p->period_month),
                    'net_minor' => (int) $p->net_minor,
                ]),
            ],
            'leave' => [
                'pending' => LeaveRequest::where('employee_id', $employee->id)->where('status', 'pending')->count(),
                'approved_days' => (float) $leaveBooked,
                'requests_count' => LeaveRequest::where('employee_id', $employee->id)->count(),
            ],
            'money_requests' => [
                'requested_minor' => (int) MoneyRequest::where('employee_id', $employee->id)->sum('amount_minor'),
                'pending_minor' => (int) MoneyRequest::where('employee_id', $employee->id)->where('status', 'pending')->sum('amount_minor'),
                'count' => MoneyRequest::where('employee_id', $employee->id)->count(),
            ],
            'contract' => $employee->contracts()
                ->whereIn('status', ['active', 'renewal_due'])
                ->orderByDesc('start_date')
                ->first()?->only(['reference', 'status', 'start_date', 'end_date']),
        ]);
    }
}
