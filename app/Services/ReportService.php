<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\MoneyRequest;
use App\Models\Payslip;

/**
 * Aggregates for the reporting screens.
 *
 * These replace the module-level reportData array in the frontend
 * (page.tsx:1448). Every figure is computed from live tables.
 *
 * Headcount is deliberately point-in-time at each month end rather than a
 * live count, so a June report keeps showing June's headcount after July
 * hires land.
 */
class ReportService
{
    /**
     * Month-by-month figures for a year.
     *
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    public function monthly(int $year): array
    {
        $rows = [];

        // Bucketed in PHP rather than with MONTH()/EXTRACT(), which exist in
        // MySQL but not SQLite. The test suite runs on SQLite while the
        // deployment runs on MySQL, so the SQL must stay portable.
        $payrollByMonth = [];

        Payslip::where('period_year', $year)
            ->get(['period_month', 'net_minor', 'gross_minor'])
            ->each(function (Payslip $payslip) use (&$payrollByMonth) {
                $m = (int) $payslip->period_month;
                $payrollByMonth[$m] ??= ['net' => 0, 'gross' => 0];
                $payrollByMonth[$m]['net'] += (int) $payslip->net_minor;
                $payrollByMonth[$m]['gross'] += (int) $payslip->gross_minor;
            });

        $moneyByMonth = [];

        MoneyRequest::whereYear('created_at', $year)
            ->where('status', '!=', 'declined')
            ->get(['created_at', 'amount_minor'])
            ->each(function (MoneyRequest $r) use (&$moneyByMonth) {
                $moneyByMonth[(int) $r->created_at->month] = ($moneyByMonth[(int) $r->created_at->month] ?? 0) + (int) $r->amount_minor;
            });

        $leaveByMonth = [];

        LeaveRequest::whereYear('created_at', $year)
            ->get(['created_at', 'status'])
            ->each(function (LeaveRequest $l) use (&$leaveByMonth) {
                $m = (int) $l->created_at->month;
                $leaveByMonth[$m][$l->status] = ($leaveByMonth[$m][$l->status] ?? 0) + 1;
            });

        $contractsByMonth = [];

        Contract::whereYear('start_date', $year)
            ->get(['start_date'])
            ->each(function (Contract $c) use (&$contractsByMonth) {
                $contractsByMonth[(int) $c->start_date->month] = ($contractsByMonth[(int) $c->start_date->month] ?? 0) + 1;
            });

        for ($month = 1; $month <= 12; $month++) {
            $endOfMonth = now()->setYear($year)->setMonth($month)->endOfMonth()->toDateString();

            // Point-in-time headcount.
            $headcount = Employee::query()
                ->whereDate('hire_date', '<=', $endOfMonth)
                ->where(function ($q) use ($endOfMonth) {
                    $q->whereNull('termination_date')->orWhereDate('termination_date', '>', $endOfMonth);
                })
                ->count();

            $rows[] = [
                'month' => now()->setYear($year)->setMonth($month)->format('F'),
                'month_index' => $month - 1,
                'payroll_net' => (int) ($payrollByMonth[$month]['net'] ?? 0),
                'payroll_gross' => (int) ($payrollByMonth[$month]['gross'] ?? 0),
                'money_requested' => (int) ($moneyByMonth[$month] ?? 0),
                'leave_approved' => (int) ($leaveByMonth[$month]['approved'] ?? 0),
                'leave_declined' => (int) ($leaveByMonth[$month]['declined'] ?? 0),
                'contracts' => (int) ($contractsByMonth[$month] ?? 0),
                'headcount' => $headcount,
            ];
        }

        $endOfYear = now()->setYear($year)->endOfYear()->toDateString();

        $totals = [
            'headcount' => Employee::query()
                ->whereDate('hire_date', '<=', $endOfYear)
                ->where(function ($q) use ($endOfYear) {
                    $q->whereNull('termination_date')->orWhereDate('termination_date', '>', $endOfYear);
                })
                ->count(),
            'joined' => Employee::whereYear('hire_date', $year)->count(),
            'terminated' => Employee::where('status', 'terminated')->whereYear('termination_date', $year)->count(),
            'departments' => Department::where('is_active', true)->count(),
            'payroll_net' => (int) Payslip::where('period_year', $year)->sum('net_minor'),
            'payroll_gross' => (int) Payslip::where('period_year', $year)->sum('gross_minor'),
            'money_requested' => (int) MoneyRequest::whereYear('created_at', $year)->sum('amount_minor'),
            'money_paid' => (int) MoneyRequest::where('status', 'paid')->whereYear('paid_at', $year)->sum('amount_minor'),
            'leave_approved' => LeaveRequest::where('status', 'approved')->whereYear('created_at', $year)->count(),
            'leave_declined' => LeaveRequest::where('status', 'declined')->whereYear('created_at', $year)->count(),
            'contracts' => Contract::whereYear('start_date', $year)->count(),
        ];

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * The same figures as a JSON payload for the on-screen charts.
     */
    public function monthlyJson(int $year): array
    {
        return $this->monthly($year);
    }
}
