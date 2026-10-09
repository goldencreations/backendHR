<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isHr();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:241'],
            'email' => ['required', 'email', 'max:191', Rule::unique('employees', 'email')],
            'phone' => ['nullable', 'string', 'max:40'],
            'date_of_birth' => ['nullable', 'date'],
            'tin' => ['nullable', 'string', 'max:32'],
            'nida_number' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:2000'],
            'emergency_contact_name' => ['nullable', 'string', 'max:150'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:40'],

            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            // The role must belong to the chosen department, otherwise the
            // department/role pair the UI enforces client-side would break.
            'job_role_id' => [
                'nullable',
                'integer',
                'exists:job_roles,id',
                Rule::exists('job_roles', 'id')->where(
                    fn ($q) => $q->where('department_id', $this->input('department_id'))
                ),
            ],

            'employment_type' => ['required', Rule::in(['full_time', 'part_time', 'contract', 'temporary'])],
            'start_date' => ['nullable', 'date'],
            'hire_date' => ['nullable', 'date', 'after_or_equal:1900-01-01'],
            // Integer shillings, never a formatted string.
            'base_salary_minor' => ['required', 'integer', 'min:0', 'max:9999999999'],
            'currency' => ['nullable', 'string', 'size:3'],

            // Supplying a password creates the portal login, which is what
            // makes the account usable straight away.
            'password' => ['nullable', 'confirmed', Password::min(8)],
        ];
    }

    public function messages(): array
    {
        return [
            'job_role_id.exists' => 'The selected role does not belong to the selected department.',
            'base_salary_minor.integer' => 'The salary must be a whole number of shillings.',
        ];
    }
}
