<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\MoneyRequest;
use App\Services\MoneyRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

class MoneyRequestController extends Controller
{
    public function __construct(private readonly MoneyRequestService $money) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = MoneyRequest::query()
            ->with(['employee:id,first_name,last_name', 'type:id,name', 'decidedBy:id,name'])
            ->latest();

        if (! $user->isHr()) {
            $query->where('employee_id', $user->employee_id);
        } elseif ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }

        if ($request->filled('status')) {
            $status = $request->string('status')->value();
            $status === 'all'
                ? $query->whereIn('status', ['pending', 'approved', 'paid', 'declined'])
                : $query->where('status', $status);
        }

        $requests = $query->paginate(min($request->integer('per_page', 25), 100));

        return response()->json([
            'data' => collect($requests->items())->map(fn ($r) => $this->payload($r))->all(),
            'meta' => [
                'current_page' => $requests->currentPage(),
                'total' => $requests->total(),
                'limit_minor' => (int) (json_decode((string) DB::table('settings')->where('key', 'advance_limit_minor')->value('value'), true) ?? 2500000),
                'counts' => MoneyRequest::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'money_request_type_id' => ['required', 'integer', 'exists:money_request_types,id'],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:999999999'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'needed_by' => ['nullable', 'date'],
            'payment_method' => ['nullable', 'in:bank_transfer,mobile_money'],
            'document_id' => ['nullable', 'integer', 'exists:employee_documents,id'],
        ]);

        // HR may raise a request on someone's behalf.
        if ($user->isHr() && $request->filled('employee_id')) {
            $data['employee_id'] = $request->integer('employee_id');
            $employee = Employee::findOrFail($data['employee_id']);
        } else {
            $employee = Employee::find($user->employee_id);
        }

        if (! $employee) {
            return response()->json(['message' => 'No employee record is linked to this account.'], 404);
        }

        try {
            $created = $this->money->submit($employee, $data);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Money request submitted.',
            'money_request' => $this->payload($created),
        ], 201);
    }

    public function show(Request $request, MoneyRequest $moneyRequest): JsonResponse
    {
        Gate::authorize('view', $moneyRequest);

        $moneyRequest->load(['employee:id,first_name,last_name', 'type:id,name', 'decidedBy:id,name']);

        return response()->json(['money_request' => $this->payload($moneyRequest)]);
    }

    public function approve(Request $request, MoneyRequest $moneyRequest): JsonResponse
    {
        return $this->decide($request, $moneyRequest, 'approve');
    }

    public function decline(Request $request, MoneyRequest $moneyRequest): JsonResponse
    {
        return $this->decide($request, $moneyRequest, 'decline');
    }

    public function markPaid(Request $request, MoneyRequest $moneyRequest): JsonResponse
    {
        abort_unless($request->user()->isHr(), 403);

        try {
            $paid = $this->money->markPaid($moneyRequest);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Marked as paid.', 'money_request' => $this->payload($paid)]);
    }

    private function decide(Request $request, MoneyRequest $moneyRequest, string $action): JsonResponse
    {
        abort_unless($request->user()->isHr(), 403, 'Only HR staff can decide money requests.');

        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        try {
            $decided = $action === 'approve'
                ? $this->money->approve($moneyRequest, $request->user(), $data['note'] ?? null)
                : $this->money->decline($moneyRequest, $request->user(), $data['note'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Money request '.$decided->status.'.',
            'money_request' => $this->payload($decided),
        ]);
    }

    private function payload(MoneyRequest $r): array
    {
        return [
            'id' => $r->id,
            'reference' => $r->reference,
            'receipt_number' => $r->receipt_number,
            'employee' => $r->employee ? [
                'id' => $r->employee->id,
                'name' => $r->employee->full_name,
                'initials' => mb_substr((string) $r->employee->first_name, 0, 1).mb_substr((string) $r->employee->last_name, 0, 1),
            ] : null,
            'kind' => $r->type?->name,
            'amount_minor' => (int) $r->amount_minor,
            'currency' => $r->currency,
            'reason' => $r->reason,
            'needed_by' => $r->needed_by?->toDateString(),
            'method' => $r->payment_method,
            'status' => $r->status,
            'decided_by' => $r->decidedBy?->name,
            'decided_on' => $r->decided_at?->toDateString(),
            'note' => $r->decision_note,
            'paid_at' => $r->paid_at?->toDateString(),
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
