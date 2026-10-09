<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DepartmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isHr(), 403);

        $departments = Department::query()
            ->with(['roles' => fn ($q) => $q->where('is_active', true)->orderBy('title'),
                'leads' => fn ($q) => $q->with('employee:id,first_name,last_name'),
            ])
            ->withCount(['employees' => fn ($q) => $q->where('status', '!=', 'terminated')])
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $departments->map(function (Department $department) {
                return [
                    'id' => $department->id,
                    'name' => $department->name,
                    'description' => $department->description,
                    'is_active' => $department->is_active,
                    // Resolves departmentData[].lead in the UI.
                    'leads' => $department->leads->map(fn ($lead) => [
                        'employee_id' => $lead->employee_id,
                        'name' => $lead->employee?->full_name,
                        'is_primary' => $lead->is_primary,
                    ]),
                    'roles' => $department->roles->map(fn (JobRole $role) => [
                        'id' => $role->id,
                        'title' => $role->title,
                        'level' => $role->level,
                    ]),
                    'headcount' => $department->employees_count,
                    'fully_assigned' => DB::table('employee_assignments')
                        ->where('department_id', $department->id)
                        ->where('is_current', true)
                        ->count(),
                ];
            }),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->isHr(), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:departments,name'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $department = Department::create($data);

        return response()->json([
            'message' => 'Department created.',
            'department' => $department,
        ], 201);
    }

    public function show(Request $request, Department $department): JsonResponse
    {
        abort_unless($request->user()->isHr(), 403);

        $department->load(['roles' => fn ($q) => $q->orderBy('title')]);
        $employees = Employee::query()
            ->where('department_id', $department->id)
            ->where('status', '!=', 'terminated')
            ->with(['jobRole:id,title,level', 'assignments' => fn ($q) => $q->where('is_current', true)])
            ->orderBy('first_name')
            ->get()
            ->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'name' => $employee->full_name,
                'assigned_role_id' => $employee->currentAssignment()?->job_role_id,
                'assigned_role' => $employee->currentAssignment()?->jobRole?->title,
                'level' => $employee->currentAssignment()?->jobRole?->level,
                'status' => $employee->status,
            ]);

        return response()->json([
            'department' => [
                'id' => $department->id,
                'name' => $department->name,
                'description' => $department->description,
            ],
            'roles' => $department->roles,
            'employees' => $employees,
        ]);
    }

    public function update(Request $request, Department $department): JsonResponse
    {
        abort_unless($request->user()->isHr(), 403);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120', 'unique:departments,name,'.$department->id],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $department->update($data);

        return response()->json(['message' => 'Department updated.', 'department' => $department->fresh()]);
    }

    public function destroy(Request $request, Department $department): JsonResponse
    {
        abort_unless($request->user()->isHr(), 403);

        // People reference the department, so deactivate rather than delete.
        if (Employee::where('department_id', $department->id)->exists()) {
            $department->update(['is_active' => false]);

            return response()->json([
                'message' => 'Department has employees, so it was deactivated instead of deleted.',
                'department' => $department->fresh(),
            ]);
        }

        $department->delete();

        return response()->json(['message' => 'Department deleted.']);
    }

    /**
     * Adds several roles at once. The UI parses a textarea on newlines or
     * commas (page.tsx:335), so duplicates are collapsed case-insensitively.
     */
    public function addRoles(Request $request, Department $department): JsonResponse
    {
        abort_unless($request->user()->isHr(), 403);

        $data = $request->validate([
            'roles' => ['required', 'array', 'min:1', 'max:100'],
            'roles.*' => ['required', 'string', 'max:150'],
        ]);

        $added = [];

        foreach ($data['roles'] as $title) {
            $title = trim($title);

            if ($title === '') {
                continue;
            }

            $exists = JobRole::where('department_id', $department->id)
                ->whereRaw('LOWER(title) = ?', [mb_strtolower($title)])
                ->exists();

            if ($exists) {
                continue;
            }

            $added[] = JobRole::create([
                'department_id' => $department->id,
                'title' => $title,
                'level' => 'mid',
            ]);
        }

        return response()->json([
            'message' => count($added) === 1 ? '1 role added.' : count($added).' roles added.',
            'added' => $added,
        ], 201);
    }

    public function updateRole(Request $request, Department $department, JobRole $role): JsonResponse
    {
        abort_unless($request->user()->isHr(), 403);

        abort_unless($role->department_id === $department->id, 404);

        $data = $request->validate([
            'level' => ['sometimes', 'in:lead,senior,mid,junior,intern'],
            'title' => ['sometimes', 'string', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $role->update($data);

        return response()->json(['message' => 'Role updated.', 'role' => $role->fresh()]);
    }

    public function destroyRole(Request $request, Department $department, JobRole $role): JsonResponse
    {
        abort_unless($request->user()->isHr(), 403);

        abort_unless($role->department_id === $department->id, 404);

        if (Employee::where('job_role_id', $role->id)->exists()) {
            $role->update(['is_active' => false]);

            return response()->json([
                'message' => 'Role is held by employees, so it was deactivated instead of deleted.',
                'role' => $role->fresh(),
            ]);
        }

        $role->delete();

        return response()->json(['message' => 'Role deleted.']);
    }
}
