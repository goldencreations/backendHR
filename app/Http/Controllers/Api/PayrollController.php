<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PayslipResource;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Services\PayrollService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PayrollController extends Controller
{
    public function __construct(private readonly PayrollService $payroll) {}

    public function runs(Request $request): JsonResponse
    {
        $runs = PayrollRun::query()
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->when($request->filled('year'), fn ($q) => $q->where('period_year', $request->integer('year')))
            ->get()
            ->map(fn (PayrollRun $run) => $this->runPayload($run));

        return response()->json(['data' => $runs]);
    }

    public function showRun(Request $request, PayrollRun $run): JsonResponse
    {
        $run->load('payslips.employee:id,first_name,last_name');

        return response()->json([
            'run' => $this->runPayload($run),
            'payslips' => PayslipResource::collection($run->payslips)->resolve($request),
        ]);
    }

    /**
     * Builds a run from supplied per-employee components.
     */
    public function storeRun(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period_year' => ['required', 'integer', 'between:2000,2100'],
            'period_month' => ['required', 'integer', 'between:1,12'],
            'lines' => ['required', 'array', 'min:1', 'max:1000'],
            'lines.*.employee_id' => ['required', 'integer', 'exists:employees,id', 'distinct'],
            'lines.*.basic_minor' => ['required', 'integer', 'min:0'],
            'lines.*.overtime_minor' => ['nullable', 'integer', 'min:0'],
            'lines.*.bonus_minor' => ['nullable', 'integer', 'min:0'],
            'lines.*.allowance_minor' => ['nullable', 'integer', 'min:0'],
            'lines.*.tax_minor' => ['nullable', 'integer', 'min:0'],
            'lines.*.pension_minor' => ['nullable', 'integer', 'min:0'],
            'lines.*.other_deduction_minor' => ['nullable', 'integer', 'min:0'],
            'lines.*.payment_method' => ['nullable', 'in:bank_transfer,mobile_money'],
        ]);

        try {
            $run = $this->payroll->createRun(
                $data['period_year'],
                $data['period_month'],
                $data['lines'],
                $request->user()
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Payroll run created.',
            'run' => $this->runPayload($run),
        ], 201);
    }

    public function approveRun(Request $request, PayrollRun $run): JsonResponse
    {
        try {
            $approved = $this->payroll->approve($run, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Payroll approved.',
            'run' => $this->runPayload($approved),
        ]);
    }

    public function payRun(Request $request, PayrollRun $run): JsonResponse
    {
        try {
            $paid = $this->payroll->markPaid($run);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Payroll marked as paid.',
            'run' => $this->runPayload($paid),
        ]);
    }

    /**
     * Payslips. An employee may only ever list their own.
     */
    public function payslips(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Payslip::query()
            ->with(['employee:id,first_name,last_name', 'run:id,period_year,period_month,status'])
            ->orderByDesc('period_year')
            ->orderByDesc('period_month');

        if (! $user->isHr()) {
            $query->where('employee_id', $user->employee_id);
        } elseif ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }

        if ($request->filled('year')) {
            $query->where('period_year', $request->integer('year'));
        }

        $payslips = $query->paginate(min($request->integer('per_page', 25), 100));

        return response()->json([
            'data' => PayslipResource::collection($payslips->items())->resolve($request),
            'meta' => [
                'current_page' => $payslips->currentPage(),
                'total' => $payslips->total(),
                'net_total_minor' => (int) (clone $query)->sum('net_minor'),
            ],
        ]);
    }

    private function runPayload(PayrollRun $run): array
    {
        return [
            'id' => $run->id,
            'period' => sprintf('%04d-%02d', $run->period_year, $run->period_month),
            'period_year' => $run->period_year,
            'period_month' => $run->period_month,
            'status' => $run->status,
            'gross_total_minor' => (int) $run->gross_total_minor,
            'net_total_minor' => (int) $run->net_total_minor,
            'payslip_count' => (int) $run->payslip_count,
            'approved_at' => $run->approved_at?->toIso8601String(),
            'paid_at' => $run->paid_at?->toIso8601String(),
        ];
    }
}
