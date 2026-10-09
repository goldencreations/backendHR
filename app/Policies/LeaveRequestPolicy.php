<?php

namespace App\Policies;

use App\Models\LeaveRequest;
use App\Models\User;

class LeaveRequestPolicy
{
    /**
     * HR review the queue; an employee sees and cancels only their own.
     */
    public function view(User $user, LeaveRequest $leaveRequest): bool
    {
        if ($user->isHr()) {
            return true;
        }

        return (int) $leaveRequest->employee_id === (int) $user->employee_id;
    }

    public function update(User $user, LeaveRequest $leaveRequest): bool
    {
        return $this->view($user, $leaveRequest);
    }

    public function delete(User $user, LeaveRequest $leaveRequest): bool
    {
        return $this->view($user, $leaveRequest);
    }
}
