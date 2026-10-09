<?php

namespace App\Notifications;

use App\Models\Payslip;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class PayrollApproved extends Notification
{
    use Queueable;

    public function __construct(public readonly Payslip $payslip) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'payroll',
            'title' => 'Payroll approved',
            'body' => sprintf(
                'Your %04d-%02d payslip of %s %s net was approved.',
                $this->payslip->period_year,
                $this->payslip->period_month,
                $this->payslip->currency,
                number_format((int) $this->payslip->net_minor)
            ),
            'actor_user_id' => $this->payslip->run?->approved_by,
            'subject_type' => Payslip::class,
            'subject_id' => $this->payslip->id,
        ];
    }
}
