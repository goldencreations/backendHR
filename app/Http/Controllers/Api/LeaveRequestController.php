<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LeaveRequestResource;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\LeaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

class LeaveRequestController extends Controller
{
    public function __construct(private readonly LeaveService $leave) {}

    /**
     * HR sees the whole queue; an employee sees only their own requests.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = LeaveRequest::query()
            ->with(['employee:id,first_name,last_name,department_id', 'leaveType:id,name', 'coverageEmployee:id,first_name,last_name', 'approver:id,name'])
            ->latest();

        if (! $user->isHr()) {
            $query->where('employee_id', $user->employee_id);
        } elseif ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }

        if ($request->filled('status')) {
            $status = $request->string('status')->value();
            $status === 'all' ? $query->whereIn('status', ['pending', 'approved', 'declined']) : $query->where('status', $status);
        } elseif ($user->isHr()) {
            // The approval screen opens on the pending queue.
            $query->where('status', 'pending');
        }

        $requests = $query->paginate(min($request->integer('per_page', 25), 100));

        return response()->json([
            'data' => LeaveRequestResource::collection($requests->items())->resolve($request),
            'meta' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'total' => $requests->total(),
                'counts' => $this->counts(),
            ],
        ]);
    }

    private function counts(): array
    {
        $all = LeaveRequest::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'pending' => (int) ($all['pending'] ?? 0),
            'approved' => (int) ($all['approved'] ?? 0),
            'declined' => (int) ($all['declined'] ?? 0),
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $employee = $this->resolveSubject($request);

        $data = $request->validate([
            'leave_type_id' => ['required', 'integer', 'exists:leave_types,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date'],
            'days' => ['required', 'numeric', 'gt:0', 'max:366'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'coverage_employee_id' => ['nullable', 'integer', 'exists:employees,id', 'different:employee_id'],
        ]);

        try {
            $leave = $this->leave->submit($employee, $data);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Leave request submitted for review.',
            'leave_request' => (new LeaveRequestResource($leave))->resolve($request),
        ], 201);
    }

    public function show(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        Gate::authorize('view', $leaveRequest);

        $leaveRequest->load(['employee:id,first_name,last_name,department_id', 'leaveType:id,name', 'coverageEmployee:id,first_name,last_name', 'approver:id,name']);

        return response()->json([
            'leave_request' => (new LeaveRequestResource($leaveRequest))->resolve($request),
        ]);
    }

    public function approve(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        return $this->decide($request, $leaveRequest, 'approved');
    }

    public function decline(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        return $this->decide($request, $leaveRequest, 'declined');
    }

    private function decide(Request $request, LeaveRequest $leaveRequest, string $status): JsonResponse
    {
        abort_unless($request->user()->isHr(), 403, 'Only HR staff can decide leave requests.');

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $decided = $status === 'approved'
                ? $this->leave->approve($leaveRequest, $request->user(), $data['note'] ?? null)
                : $this->leave->decline($leaveRequest, $request->user(), $data['note'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Leave request '.$status.'.',
            'leave_request' => (new LeaveRequestResource($decided))->resolve($request),
        ]);
    }

    public function destroy(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $isOwner = (int) $leaveRequest->employee_id === (int) $request->user()->employee_id;

        abort_unless($isOwner && $leaveRequest->status === 'pending' || $request->user()->isHr(), 403);

        if ($leaveRequest->status === 'approved') {
            return response()->json([
                'message' => 'Approved leave cannot be cancelled here; adjust the record with People Operations.',
            ], 422);
        }

        // Release the reserved days.
        $balance = $this->leave->balanceFor(
            $leaveRequest->employee,
            $leaveRequest->leaveType,
            (int) $leaveRequest->start_date->format('Y')
        );
        $balance->decrement('booked_days', (float) $leaveRequest->days);

        $leaveRequest->delete();

        return response()->json(['message' => 'Leave request withdrawn.']);
    }

    /**
     * Balances for the signed-in employee, or another employee when HR asks.
     */
    public function balances(Request $request): JsonResponse
    {
        $year = (int) $request->integer('year', now()->year);

        $employee = $this->resolveSubject($request);

        $balances = LeaveType::where('is_active', true)
            ->get()
            ->map(function (LeaveType $type) use ($employee, $year) {
                $balance = $this->leave->balanceFor($employee, $type, $year);

                return [
                    'leave_type' => $type->name,
                    'leave_type_id' => $type->id,
                    // null when no allowance has been set for this type.
                    // Reported honestly rather than as 0, which would read
                    // as "no entitlement" when it actually means "unset".
                    'entitled_days' => (float) $balance->entitled_days,
                    'entitlement_configured' => $type->annual_allowance_days !== null,
                    'used_days' => (float) $balance->used_days,
                    'booked_days' => (float) $balance->booked_days,
                    'remaining_days' => $this->leave->availableOn($balance),
                ];
            });

        return response()->json(['year' => $year, 'balances' => $balances]);
    }

    /**
     * An employee books for themselves; HR may book on someone's behalf.
     */
    private function resolveSubject(Request $request): Employee
    {
        if ($request->user()->isHr() && $request->filled('employee_id')) {
            return Employee::findOrFail($request->integer('employee_id'));
        }

        $employee = Employee::find($request->user()->employee_id);

        if (! $employee) {
            abort(404, 'No employee record is linked to this account.');
        }

        return $employee;
    }
}
