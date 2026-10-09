<?php

namespace App\Notifications;

use App\Models\MoneyRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class MoneyRequestDecided extends Notification
{
    use Queueable;

    public function __construct(
        public readonly MoneyRequest $moneyRequest,
        public readonly string $status,
        public readonly ?string $note = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'money',
            'title' => 'Money request '.$this->status,
            'body' => sprintf(
                'Your %s request of %s %s was %s.%s',
                strtolower((string) $this->moneyRequest->type?->name),
                $this->moneyRequest->currency,
                number_format((int) $this->moneyRequest->amount_minor),
                $this->status,
                $this->note ? ' Note: '.$this->note : ''
            ),
            'actor_user_id' => $this->moneyRequest->decided_by,
            'subject_type' => MoneyRequest::class,
            'subject_id' => $this->moneyRequest->id,
        ];
    }
}
