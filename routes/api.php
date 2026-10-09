<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\EmployeeAvatarController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\EmployeeDocumentController;
use App\Http\Controllers\Api\LeaveRequestController;
use App\Http\Controllers\Api\MoneyRequestController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PayrollController;
use App\Http\Controllers\Api\PdfController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes
|--------------------------------------------------------------------------
|
| Registered through the `api` file in bootstrap/app.php, which Laravel
| already prefixes with /api, so paths here must not repeat it.
|
| The token is issued by POST /api/auth/login and returned to the frontend
| at https://hr.goldencreations.online to send as a Bearer header.
|
*/

Route::post('auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:6,1')
    ->name('api.auth.login');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me'])->name('api.auth.me');

    Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');

    Route::put('auth/password', [AuthController::class, 'updatePassword'])
        ->middleware('throttle:6,1')
        ->name('api.auth.password');

    // The employee portal's own records.
    Route::get('me/profile', [EmployeeController::class, 'me'])->name('api.me.profile');
    Route::get('me/dashboard', [DashboardController::class, 'me'])->name('api.me.dashboard');
    Route::get('me/payslips', [PayrollController::class, 'payslips'])->name('api.me.payslips');

    // Server-rendered PDFs. Ownership is checked per document.
    Route::get('payslips/{payslip}/pdf', [PdfController::class, 'payslip'])->name('api.pdf.payslip');
    Route::get('money-requests/{money_request}/receipt-pdf', [PdfController::class, 'receipt'])->name('api.pdf.receipt');
    Route::get('contracts/{contract}/pdf', [PdfController::class, 'contract'])->name('api.pdf.contract');
    Route::get('me/work-progress/pdf', [PdfController::class, 'workProgress'])->name('api.pdf.work-progress');
    Route::get('me/leave-balances', [LeaveRequestController::class, 'balances'])->name('api.me.leave-balances');

    Route::post('leave-requests', [LeaveRequestController::class, 'store'])->name('api.leave.store');
    Route::get('leave-requests', [LeaveRequestController::class, 'index'])->name('api.leave.index');
    Route::get('leave-requests/{leave_request}', [LeaveRequestController::class, 'show'])->name('api.leave.show');
    Route::delete('leave-requests/{leave_request}', [LeaveRequestController::class, 'destroy'])->name('api.leave.destroy');

    Route::post('money-requests', [MoneyRequestController::class, 'store'])->name('api.money.store');
    Route::get('money-requests', [MoneyRequestController::class, 'index'])->name('api.money.index');
    Route::get('money-requests/{money_request}', [MoneyRequestController::class, 'show'])->name('api.money.show');

    Route::get('contracts', [ContractController::class, 'index'])->name('api.contracts.index');
    Route::get('contract-types', [ContractController::class, 'types'])->name('api.contracts.types');
    Route::post('contracts/{contract}/respond', [ContractController::class, 'respond'])->name('api.contracts.respond');

    // Payslips are readable by any authenticated caller; the controller
    // restricts non-HR users to their own employee record.
    Route::get('payslips', [PayrollController::class, 'payslips'])->name('api.payslips.index');

    Route::get('notifications', [NotificationController::class, 'index'])->name('api.notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('api.notifications.read-all');
    Route::post('notifications/{id}/read', [NotificationController::class, 'markRead'])->name('api.notifications.read');
    Route::delete('notifications/{id}', [NotificationController::class, 'destroy'])->name('api.notifications.destroy');

    /*
    | Documents. Reads are open to any authenticated caller because the
    | policy restricts them to HR or the owning employee; writes are HR only.
    | Downloads stream through PHP after that check, so no upload is ever
    | reachable by a static URL.
    */
    Route::get('files/{document}/download', [EmployeeDocumentController::class, 'download'])
        ->name('api.files.download');

    Route::get('documents', [EmployeeDocumentController::class, 'index'])
        ->name('api.documents.index');

    Route::get('documents/{document}', [EmployeeDocumentController::class, 'show'])
        ->name('api.documents.show');

    Route::get('employees/{employee}/avatar', [EmployeeAvatarController::class, 'show'])
        ->name('api.employees.avatar');

    Route::middleware('hr')->group(function () {
        Route::get('dashboard', [DashboardController::class, 'hr'])->name('api.dashboard.hr');

        Route::get('employees', [EmployeeController::class, 'index'])->name('api.employees.index');
        Route::post('employees', [EmployeeController::class, 'store'])->name('api.employees.store');
        Route::get('employees/{employee}', [EmployeeController::class, 'show'])->name('api.employees.show');
        Route::patch('employees/{employee}', [EmployeeController::class, 'update'])->name('api.employees.update');
        Route::delete('employees/{employee}', [EmployeeController::class, 'destroy'])->name('api.employees.destroy');

        Route::get('departments', [DepartmentController::class, 'index'])->name('api.departments.index');
        Route::post('departments', [DepartmentController::class, 'store'])->name('api.departments.store');
        Route::get('departments/{department}', [DepartmentController::class, 'show'])->name('api.departments.show');
        Route::patch('departments/{department}', [DepartmentController::class, 'update'])->name('api.departments.update');
        Route::delete('departments/{department}', [DepartmentController::class, 'destroy'])->name('api.departments.destroy');
        Route::post('departments/{department}/roles', [DepartmentController::class, 'addRoles'])->name('api.departments.roles.store');
        Route::patch('departments/{department}/roles/{role}', [DepartmentController::class, 'updateRole'])->name('api.departments.roles.update');
        Route::delete('departments/{department}/roles/{role}', [DepartmentController::class, 'destroyRole'])->name('api.departments.roles.destroy');

        Route::post('documents', [EmployeeDocumentController::class, 'store'])
            ->name('api.documents.store');

        Route::post('documents/{document}/replace', [EmployeeDocumentController::class, 'replace'])
            ->name('api.documents.replace');

        Route::delete('documents/{document}', [EmployeeDocumentController::class, 'destroy'])
            ->name('api.documents.destroy');

        Route::post('employees/{employee}/avatar', [EmployeeAvatarController::class, 'store'])
            ->name('api.employees.avatar.store');

        // Leave decisions.
        Route::post('leave-requests/{leave_request}/approve', [LeaveRequestController::class, 'approve'])->name('api.leave.approve');
        Route::post('leave-requests/{leave_request}/decline', [LeaveRequestController::class, 'decline'])->name('api.leave.decline');
        Route::get('leave-balances', [LeaveRequestController::class, 'balances'])->name('api.leave.balances');

        // Payroll.
        Route::get('payroll/runs', [PayrollController::class, 'runs'])->name('api.payroll.runs');
        Route::post('payroll/runs', [PayrollController::class, 'storeRun'])->name('api.payroll.runs.store');
        Route::get('payroll/runs/{run}', [PayrollController::class, 'showRun'])->name('api.payroll.runs.show');
        Route::post('payroll/runs/{run}/approve', [PayrollController::class, 'approveRun'])->name('api.payroll.runs.approve');
        Route::post('payroll/runs/{run}/pay', [PayrollController::class, 'payRun'])->name('api.payroll.runs.pay');

        // Money request decisions.
        Route::post('money-requests/{money_request}/approve', [MoneyRequestController::class, 'approve'])->name('api.money.approve');
        Route::post('money-requests/{money_request}/decline', [MoneyRequestController::class, 'decline'])->name('api.money.decline');
        Route::post('money-requests/{money_request}/mark-paid', [MoneyRequestController::class, 'markPaid'])->name('api.money.mark-paid');

        // Contracts.
        Route::post('contracts', [ContractController::class, 'store'])->name('api.contracts.store');
        Route::patch('contracts/{contract}', [ContractController::class, 'update'])->name('api.contracts.update');
        Route::post('contracts/{contract}/send-for-signature', [ContractController::class, 'sendForSignature'])->name('api.contracts.send');

        Route::get('payroll/runs/{run}/pdf', [PdfController::class, 'payrollReport'])->name('api.pdf.payroll-report');
        Route::get('employees/{employee}/record-pdf', [PdfController::class, 'employeeRecord'])->name('api.pdf.employee-record');
        Route::get('reports/monthly', [PdfController::class, 'monthlyJson'])->name('api.reports.monthly');
        Route::get('reports/monthly/pdf', [PdfController::class, 'monthlyReport'])->name('api.pdf.monthly-report');
    });
});
