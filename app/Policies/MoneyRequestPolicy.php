<?php

namespace App\Policies;

use App\Models\MoneyRequest;
use App\Models\User;

class MoneyRequestPolicy
{
    public function view(User $user, MoneyRequest $moneyRequest): bool
    {
        if ($user->isHr()) {
            return true;
        }

        return (int) $moneyRequest->employee_id === (int) $user->employee_id;
    }
}
