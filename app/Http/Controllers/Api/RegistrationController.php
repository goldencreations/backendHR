<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Everything the Registration Info screen needs in one call: the profile,
 * the banking details, the documents on file and the current contract.
 *
 * Fetched together because the screen renders them as a single page and
 * three separate requests would be wasteful.
 */
class RegistrationController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $employee = Employee::find($request->user()->employee_id);

        if (! $employee) {
            return response()->json([
                'message' => 'No employee record is linked to this account.',
            ], 404);
        }

        $employee->load([
            'department:id,name',
            'jobRole:id,title,level',
            'bankAccounts' => fn ($q) => $q->orderByDesc('is_primary'),
        ])->loadCount('documents');

        // Compensation is not part of the self-service payload; the UI marks
        // it as managed by People Operations.
        $profile = (new EmployeeResource($employee))->resolve($request);
        unset($profile['base_salary_minor'], $profile['currency']);

        return response()->json([
            'profile' => $profile + ['salary_managed_by' => 'People Operations'],
            'bank_accounts' => $employee->bankAccounts->map(fn ($account) => [
                'id' => $account->id,
                'type' => $account->type,
                'bank_name' => $account->bank_name,
                'account_number' => $account->account_number,
                'account_name' => $account->account_name,
                'is_primary' => $account->is_primary,
                'is_verified' => $account->is_verified,
            ]),
            'documents' => $employee->documents()
                ->with('category:id,name,slug')
                ->latest()
                ->get()
                ->map(fn ($document) => [
                    'id' => $document->id,
                    'name' => $document->name,
                    'category' => $document->category?->name,
                    'mime_type' => $document->mime_type,
                    'type' => str_starts_with((string) $document->mime_type, 'image/') ? 'Image' : 'PDF',
                    'size_bytes' => $document->size_bytes,
                    'status' => $document->status,
                    'created_at' => $document->created_at?->toDateString(),
                    'url' => route('api.files.download', ['document' => $document->id]),
                ]),
            'contract' => $employee->contracts()
                ->with('contractType:id,name')
                ->orderByDesc('start_date')
                ->first()?->only(['id', 'reference', 'status', 'start_date', 'end_date', 'kind']),
        ]);
    }
}
