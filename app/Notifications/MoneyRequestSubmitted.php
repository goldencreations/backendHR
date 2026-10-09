<?php

namespace App\Notifications;

use App\Models\MoneyRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class MoneyRequestSubmitted extends Notification
{
    use Queueable;

    public function __construct(public readonly MoneyRequest $moneyRequest) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'money',
            'title' => 'New money request',
            'body' => sprintf(
                '%s requested %s %s as %s.',
                $this->moneyRequest->employee?->full_name,
                $this->moneyRequest->currency,
                number_format((int) $this->moneyRequest->amount_minor),
                strtolower((string) $this->moneyRequest->type?->name)
            ),
            'actor_user_id' => $this->moneyRequest->employee?->user_id,
            'subject_type' => MoneyRequest::class,
            'subject_id' => $this->moneyRequest->id,
        ];
    }
}
