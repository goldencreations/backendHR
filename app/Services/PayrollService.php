<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\User;
use App\Notifications\PayrollApproved;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * Payroll runs and payslips.
 *
 * gross, total deductions and net are MySQL generated columns, so this
 * service only writes the components; the database derives the totals.
 */
class PayrollService
{
    public function __construct(private readonly ReferenceGenerator $references) {}

    /**
     * Creates a run for a period with one payslip per active employee.
     *
     * @param  array<int, array<string, mixed>>  $lines  per-employee components
     */
    public function createRun(int $year, int $month, array $lines, ?User $actor = null): PayrollRun
    {
        if ($month < 1 || $month > 12) {
            throw new RuntimeException('Month must be between 1 and 12.');
        }

        if (PayrollRun::where('period_year', $year)->where('period_month', $month)->exists()) {
            throw new RuntimeException(sprintf('A payroll run already exists for %04d-%02d.', $year, $month));
        }

        return DB::transaction(function () use ($year, $month, $lines, $actor) {
            $run = PayrollRun::create([
                'period_year' => $year,
                'period_month' => $month,
                'status' => 'pending_approval',
                'created_by' => $actor?->id,
            ]);

            foreach ($lines as $line) {
                $employee = Employee::findOrFail($line['employee_id']);

                Payslip::create([
                    'reference' => $this->references->next('payslips', 'PR', $year),
                    'run_id' => $run->id,
                    'employee_id' => $employee->id,
                    'period_year' => $year,
                    'period_month' => $month,
                    'basic_minor' => (int) ($line['basic_minor'] ?? 0),
                    'overtime_minor' => (int) ($line['overtime_minor'] ?? 0),
                    'bonus_minor' => (int) ($line['bonus_minor'] ?? 0),
                    'allowance_minor' => (int) ($line['allowance_minor'] ?? 0),
                    'tax_minor' => (int) ($line['tax_minor'] ?? 0),
                    'pension_minor' => (int) ($line['pension_minor'] ?? 0),
                    'other_deduction_minor' => (int) ($line['other_deduction_minor'] ?? 0),
                    'currency' => $employee->currency ?: 'TZS',
                    'payment_method' => $line['payment_method'] ?? 'bank_transfer',
                    'status' => 'pending_approval',
                ]);
            }

            $this->recalculate($run);

            return $run->fresh();
        });
    }

    /**
     * Sums the run totals from its payslips. Called after every mutation so
     * the cached totals cannot drift from the rows.
     */
    public function recalculate(PayrollRun $run): PayrollRun
    {
        $totals = Payslip::where('run_id', $run->id)
            ->selectRaw('COALESCE(SUM(gross_minor),0) as gross, COALESCE(SUM(net_minor),0) as net, COUNT(*) as c')
            ->first();

        $run->update([
            'gross_total_minor' => (int) $totals->gross,
            'net_total_minor' => (int) $totals->net,
            'payslip_count' => (int) $totals->c,
        ]);

        return $run;
    }

    public function approve(PayrollRun $run, User $approver): PayrollRun
    {
        if ($run->status !== 'pending_approval') {
            throw new RuntimeException('Only a run awaiting approval can be approved.');
        }

        return DB::transaction(function () use ($run, $approver) {
            $run->update([
                'status' => 'approved',
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);

            Payslip::where('run_id', $run->id)->update(['status' => 'approved']);

            foreach (Payslip::where('run_id', $run->id)->with('employee.user')->get() as $payslip) {
                if ($payslip->employee?->user) {
                    Notification::send($payslip->employee->user, new PayrollApproved($payslip));
                }
            }

            return $run->fresh();
        });
    }

    public function markPaid(PayrollRun $run): PayrollRun
    {
        if ($run->status !== 'approved') {
            throw new RuntimeException('The run must be approved before it can be paid.');
        }

        return DB::transaction(function () use ($run) {
            $run->update(['status' => 'paid', 'paid_at' => now()]);

            Payslip::where('run_id', $run->id)->update([
                'status' => 'paid',
                'paid_at' => now()->toDateString(),
            ]);

            return $run->fresh();
        });
    }
}
