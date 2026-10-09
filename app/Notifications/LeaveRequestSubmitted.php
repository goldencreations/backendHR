<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class LeaveRequestSubmitted extends Notification
{
    use Queueable;

    public function __construct(public readonly LeaveRequest $leaveRequest) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'leave',
            'title' => 'New leave request',
            'body' => sprintf(
                '%s requested %s %s of %s days from %s to %s.',
                $this->leaveRequest->employee?->full_name,
                strtolower((string) $this->leaveRequest->leaveType?->name),
                $this->leaveRequest->days,
                rtrim(rtrim(number_format((float) $this->leaveRequest->days, 2), '0'), '.'),
                $this->leaveRequest->start_date?->toDateString(),
                $this->leaveRequest->end_date?->toDateString()
            ),
            'actor_user_id' => $this->leaveRequest->employee?->user_id,
            'subject_type' => LeaveRequest::class,
            'subject_id' => $this->leaveRequest->id,
        ];
    }
}
