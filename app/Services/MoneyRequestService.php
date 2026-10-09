<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\MoneyRequest;
use App\Models\MoneyRequestType;
use App\Models\User;
use App\Notifications\MoneyRequestDecided;
use App\Notifications\MoneyRequestSubmitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

class MoneyRequestService
{
    public function __construct(private readonly ReferenceGenerator $references) {}

    public function submit(Employee $employee, array $data): MoneyRequest
    {
        $amount = (int) $data['amount_minor'];

        if ($amount <= 0) {
            throw new RuntimeException('The amount must be greater than zero.');
        }

        $type = MoneyRequestType::findOrFail($data['money_request_type_id']);

        // The cap lives in settings rather than being hard-coded, matching
        // the advanceLimit the UI currently declares inline.
        $limit = (int) $this->setting('advance_limit_minor', 2500000);

        if ($amount > $limit) {
            throw new RuntimeException(sprintf(
                'The requested amount exceeds the limit of %s.',
                number_format($limit)
            ));
        }

        if ($type->requires_receipt && empty($data['document_id'])) {
            throw new RuntimeException('This request type requires a supporting document.');
        }

        $request = MoneyRequest::create([
            'reference' => $this->references->next('money_requests', 'MR'),
            'employee_id' => $employee->id,
            'money_request_type_id' => $type->id,
            'amount_minor' => $amount,
            'currency' => $employee->currency ?: 'TZS',
            'reason' => $data['reason'] ?? null,
            'needed_by' => $data['needed_by'] ?? null,
            'payment_method' => $data['payment_method'] ?? 'mobile_money',
            'status' => 'pending',
            'receipt_number' => $this->references->next('money_requests', 'RCP'),
        ]);

        $hr = User::whereIn('role', [User::ROLE_HR_ADMIN, User::ROLE_HR_OFFICER])
            ->where('is_active', true)
            ->get();

        if ($hr->isNotEmpty()) {
            Notification::send($hr, new MoneyRequestSubmitted($request));
        }

        return $request->fresh(['type', 'employee']);
    }

    public function approve(MoneyRequest $request, User $approver, ?string $note = null): MoneyRequest
    {
        return $this->decide($request, 'approved', $approver, $note);
    }

    public function decline(MoneyRequest $request, User $approver, ?string $note = null): MoneyRequest
    {
        return $this->decide($request, 'declined', $approver, $note);
    }

    public function markPaid(MoneyRequest $request): MoneyRequest
    {
        if ($request->status !== 'approved') {
            throw new RuntimeException('Only an approved request can be marked as paid.');
        }

        $request->update(['status' => 'paid', 'paid_at' => now()->toDateString()]);

        return $request->fresh(['type', 'employee', 'decidedBy']);
    }

    private function decide(MoneyRequest $request, string $status, User $approver, ?string $note): MoneyRequest
    {
        if ($request->status !== 'pending') {
            throw new RuntimeException('This request has already been decided.');
        }

        return DB::transaction(function () use ($request, $status, $approver, $note) {
            $request->update([
                'status' => $status,
                'decided_by' => $approver->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);

            if ($request->employee?->user) {
                Notification::send($request->employee->user, new MoneyRequestDecided($request, $status, $note));
            }

            return $request->fresh(['type', 'employee', 'decidedBy']);
        });
    }

    private function setting(string $key, mixed $default = null): mixed
    {
        $row = DB::table('settings')->where('key', $key)->value('value');

        return $row === null ? $default : json_decode((string) $row, true);
    }
}
