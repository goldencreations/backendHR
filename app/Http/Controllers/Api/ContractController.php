<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractType;
use App\Notifications\ContractSentForSignature;
use App\Services\ReferenceGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

class ContractController extends Controller
{
    public function __construct(private readonly ReferenceGenerator $references) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Contract::query()
            ->with(['employee:id,first_name,last_name', 'contractType:id,name'])
            ->orderByDesc('start_date');

        if (! $user->isHr()) {
            $query->where('employee_id', $user->employee_id);
        } elseif ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }

        if ($request->filled('status')) {
            $status = $request->string('status')->value();
            $status === 'all'
                ? $query->whereIn('status', ['draft', 'pending_signature', 'active', 'renewal_due', 'expired', 'terminated'])
                : $query->where('status', $status);
        }

        $contracts = $query->paginate(min($request->integer('per_page', 25), 100));

        return response()->json([
            'data' => collect($contracts->items())->map(fn ($c) => $this->payload($c))->all(),
            'meta' => [
                'current_page' => $contracts->currentPage(),
                'total' => $contracts->total(),
                'renewals_upcoming' => $this->renewalsDue(),
            ],
        ]);
    }

    /**
     * HR renewals plus contracts expiring within the window.
     */
    private function renewalsDue(): int
    {
        return Contract::where('status', 'renewal_due')->count()
            + Contract::where('status', 'active')
                ->whereNotNull('end_date')
                ->whereBetween('end_date', [now()->toDateString(), now()->addDays(60)->toDateString()])
                ->count();
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'contract_type_id' => ['required', 'integer', 'exists:contract_types,id'],
            'start_date' => ['required', 'date'],
            'duration_days' => ['nullable', 'integer', 'min:0', 'max:36500'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'base_salary_minor' => ['required', 'integer', 'min:0'],
            'description' => ['nullable', 'string', 'max:5000'],
            'termination_terms' => ['nullable', 'string', 'max:5000'],
            'status' => ['nullable', 'in:draft,pending_signature,active'],
        ]);

        // A duration of 0 means open-ended, stored as a null end date rather
        // than a magic zero.
        $endDate = $data['end_date'] ?? null;

        if (! $endDate && ! empty($data['duration_days'])) {
            // Matches the convention in the seeded records, where a three
            // year term from 2023-05-12 runs to 2026-05-11 (page.tsx:786):
            // the end date is the start plus the stated number of days.
            $endDate = now()->parse($data['start_date'])->addDays((int) $data['duration_days'])->toDateString();
        }

        if ($endDate && strtotime($endDate) < strtotime($data['start_date'])) {
            return response()->json(['message' => 'The end date cannot be before the start date.'], 422);
        }

        $contract = Contract::create([
            'reference' => $this->references->next('contracts', 'GH'),
            'employee_id' => $data['employee_id'],
            'contract_type_id' => $data['contract_type_id'],
            'start_date' => $data['start_date'],
            'end_date' => $endDate,
            'duration_days' => $endDate ? (int) round((strtotime($endDate) - strtotime($data['start_date'])) / 86400) : null,
            'base_salary_minor' => $data['base_salary_minor'],
            'currency' => 'TZS',
            'description' => $data['description'] ?? null,
            'termination_terms' => $data['termination_terms'] ?? null,
            'status' => $data['status'] ?? 'draft',
            'created_by' => $request->user()->id,
        ]);

        $contract->load(['employee:id,first_name,last_name', 'contractType:id,name']);

        return response()->json([
            'message' => 'Contract created.',
            'contract' => $this->payload($contract),
        ], 201);
    }

    public function sendForSignature(Request $request, Contract $contract): JsonResponse
    {
        abort_unless($request->user()->isHr(), 403);

        if (in_array($contract->status, ['active', 'expired'], true)) {
            return response()->json(['message' => 'This contract cannot be sent again.'], 422);
        }

        $contract->update([
            'status' => 'pending_signature',
            'sent_for_signature_at' => now(),
        ]);

        if ($contract->employee?->user) {
            Notification::send($contract->employee->user, new ContractSentForSignature($contract));
        }

        return response()->json([
            'message' => 'Contract sent for signature.',
            'contract' => $this->payload($contract->fresh()),
        ]);
    }

    /**
     * The employee accepting or declining from their portal.
     */
    public function respond(Request $request, Contract $contract): JsonResponse
    {
        $isOwner = (int) $contract->employee_id === (int) $request->user()->employee_id;

        abort_unless(
            $isOwner || $request->user()->isHr(),
            403,
            'This contract belongs to another employee.'
        );

        if ($contract->status !== 'pending_signature') {
            return response()->json(['message' => 'This contract is not awaiting a signature.'], 422);
        }

        $data = $request->validate([
            'decision' => ['required', 'in:accepted,declined'],
        ]);

        $contract->update([
            'status' => $data['decision'] === 'accepted' ? 'active' : 'terminated',
            'signed_at' => $data['decision'] === 'accepted' ? now() : null,
        ]);

        return response()->json([
            'message' => 'Contract '.$contract->status.'.',
            'contract' => $this->payload($contract->fresh()),
        ]);
    }

    public function update(Request $request, Contract $contract): JsonResponse
    {
        abort_unless($request->user()->isHr(), 403);

        $data = $request->validate([
            'description' => ['nullable', 'string', 'max:5000'],
            'termination_terms' => ['nullable', 'string', 'max:5000'],
            'base_salary_minor' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'in:draft,pending_signature,active,renewal_due,expired,terminated'],
        ]);

        $contract->update($data);

        return response()->json(['message' => 'Contract updated.', 'contract' => $this->payload($contract->fresh())]);
    }

    public function types(): JsonResponse
    {
        return response()->json([
            'data' => ContractType::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    private function payload(Contract $contract): array
    {
        $daysLeft = $contract->end_date
            ? (int) now()->startOfDay()->diffInDays($contract->end_date, false)
            : null;

        return [
            'id' => $contract->id,
            'reference' => $contract->reference,
            'employee' => $contract->employee ? [
                'id' => $contract->employee->id,
                'name' => $contract->employee->full_name,
            ] : null,
            'kind' => $contract->contractType?->name,
            'start_date' => $contract->start_date?->toDateString(),
            'end_date' => $contract->end_date?->toDateString(),
            'open_ended' => $contract->end_date === null,
            'days_left' => $daysLeft,
            'base_salary_minor' => (int) $contract->base_salary_minor,
            'currency' => $contract->currency,
            'description' => $contract->description,
            'termination_terms' => $contract->termination_terms,
            'status' => $contract->status,
            'sent_for_signature_at' => $contract->sent_for_signature_at?->toIso8601String(),
            'signed_at' => $contract->signed_at?->toIso8601String(),
        ];
    }
}
