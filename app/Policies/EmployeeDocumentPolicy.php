<?php

namespace App\Policies;

use App\Models\EmployeeDocument;
use App\Models\User;

/**
 * Who may read or remove an employee document.
 *
 * The Documents screen states the rule it enforces in the UI: "Only HR
 * administrators and {name} can access these files" (page.tsx:1021). That
 * is implemented here rather than trusted to the frontend.
 */
class EmployeeDocumentPolicy
{
    /**
     * HR staff can see everything; an employee can see only their own
     * records. The subject employee is resolved through the caller's
     * users.employee_id link.
     */
    public function view(User $user, EmployeeDocument $document): bool
    {
        if ($user->isHr()) {
            return true;
        }

        return $this->documentBelongsToCaller($user, $document);
    }

    public function download(User $user, EmployeeDocument $document): bool
    {
        return $this->view($user, $document);
    }

    /**
     * Uploading and deleting are HR-only. An employee attaching their own
     * bank confirmation goes through the registration endpoint, which checks
     * the subject instead of granting blanket write access here.
     */
    public function delete(User $user, EmployeeDocument $document): bool
    {
        return $user->isHr();
    }

    public function updateStatus(User $user, EmployeeDocument $document): bool
    {
        return $user->isHr();
    }

    private function documentBelongsToCaller(User $user, EmployeeDocument $document): bool
    {
        $employeeId = $user->employee_id;

        return $employeeId !== null && (int) $document->employee_id === (int) $employeeId;
    }
}
