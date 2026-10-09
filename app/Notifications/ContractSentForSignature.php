<?php

namespace App\Notifications;

use App\Models\Contract;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ContractSentForSignature extends Notification
{
    use Queueable;

    public function __construct(public readonly Contract $contract) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'contract',
            'title' => 'Contract ready for signature',
            'body' => sprintf(
                'Your %s starting %s was sent for signature. Accept or decline from your portal.',
                strtolower((string) $this->contract->contractType?->name),
                $this->contract->start_date?->toDateString()
            ),
            'actor_user_id' => $this->contract->created_by,
            'subject_type' => Contract::class,
            'subject_id' => $this->contract->id,
        ];
    }
}
