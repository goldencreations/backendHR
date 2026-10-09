<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Services\EmployeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class EmployeeController extends Controller
{
    public function __construct(private readonly EmployeeService $employees) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeHr($request);

        $query = Employee::query()
            ->with(['department:id,name', 'jobRole:id,title,level'])
            ->orderBy('first_name')
            ->orderBy('last_name');

        // The directory search matches on name, role and department together
        // (page.tsx:271).
        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $like = '%'.$search.'%';
                $q->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhereHas('jobRole', fn ($r) => $r->where('title', 'like', $like))
                    ->orWhereHas('department', fn ($d) => $d->where('name', 'like', $like));
            });
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->integer('department_id'));
        }

        if ($request->filled('job_role_id')) {
            $query->where('job_role_id', $request->integer('job_role_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        } elseif (! $request->boolean('include_terminated')) {
            $query->where('status', '!=', 'terminated');
        }

        if ($request->filled('employment_type')) {
            $query->where('employment_type', $request->string('employment_type'));
        }

        $employees = $query->paginate(min($request->integer('per_page', 25), 100));

        return response()->json([
            'data' => EmployeeResource::collection($employees->items())->resolve($request),
            'meta' => [
                'current_page' => $employees->currentPage(),
                'last_page' => $employees->lastPage(),
                'per_page' => $employees->perPage(),
                'total' => $employees->total(),
            ],
        ]);
    }

    /**
     * Creates the employee, their role assignment and - when a password is
     * supplied - a working portal login, in one transaction.
     */
    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        try {
            $employee = $this->employees->create(
                $request->validated(),
                $request->input('password'),
                $request->user()
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Employee created.',
            'login_created' => $employee->user_id !== null,
            'employee' => (new EmployeeResource($employee->loadCount('documents')))->resolve($request),
        ], 201);
    }

    public function show(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeHr($request);

        $employee->load(['department:id,name', 'jobRole:id,title,level', 'assignments.jobRole:id,title,level'])
            ->loadCount('documents');

        return response()->json([
            'employee' => (new EmployeeResource($employee))->resolve($request),
        ]);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): JsonResponse
    {
        $data = $request->validated();
        $password = $data['password'] ?? null;
        unset($data['password']);

        try {
            $updated = $this->employees->update($employee, $data, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Blank on both fields leaves the existing password untouched, which
        // is what the form promises when it says "leave both empty to keep
        // the current password".
        $passwordReset = false;
        if ($password !== null && $password !== '') {
            $passwordReset = $this->employees->resetPortalPassword($updated, $password);
        }

        return response()->json([
            'message' => 'Employee updated.',
            'password_reset' => $passwordReset,
            'employee' => (new EmployeeResource($updated->loadCount('documents')))->resolve($request),
        ]);
    }

    public function destroy(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeHr($request);

        // Terminated rather than deleted so payroll, contracts and documents
        // keep their history.
        $this->employees->terminate($employee);

        return response()->json(['message' => 'Employee terminated.']);
    }

    /**
     * The employee portal's own profile. Compensation is withheld: the UI
     * marks that field "Managed by People Operations" and shows it read-only.
     */
    public function me(Request $request): JsonResponse
    {
        $employee = Employee::find($request->user()->employee_id);

        if (! $employee) {
            return response()->json([
                'message' => 'No employee record is linked to this account.',
            ], 404);
        }

        $employee->load(['department:id,name', 'jobRole:id,title,level'])->loadCount('documents');

        $payload = (new EmployeeResource($employee))->resolve($request);
        unset(
            $payload['base_salary_minor'],
            $payload['currency'],
            $payload['tin'],
            $payload['termination_date'],
            $payload['current_assignment'],
        );

        $payload['salary_managed_by'] = 'People Operations';

        return response()->json(['employee' => $payload]);
    }

    private function authorizeHr(Request $request): void
    {
        abort_unless($request->user()->isHr(), 403, 'This action is restricted to HR staff.');
    }
}
