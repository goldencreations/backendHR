<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class LeaveRequestDecided extends Notification
{
    use Queueable;

    public function __construct(
        public readonly LeaveRequest $leaveRequest,
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
            'kind' => 'leave',
            'title' => 'Leave request '.$this->status,
            'body' => sprintf(
                'Your %s of %s days (%s to %s) was %s by %s.%s',
                strtolower((string) $this->leaveRequest->leaveType?->name),
                rtrim(rtrim(number_format((float) $this->leaveRequest->days, 2), '0'), '.'),
                $this->leaveRequest->start_date?->toDateString(),
                $this->leaveRequest->end_date?->toDateString(),
                $this->status,
                $this->leaveRequest->approver?->name ?? 'People Operations',
                $this->note ? ' Note: '.$this->note : ''
            ),
            'actor_user_id' => $this->leaveRequest->approver_id,
            'subject_type' => LeaveRequest::class,
            'subject_id' => $this->leaveRequest->id,
        ];
    }
}
