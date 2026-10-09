<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isHr();
    }

    public function rules(): array
    {
        $employee = $this->route('employee');

        return [
            'name' => ['sometimes', 'string', 'max:241'],
            'email' => [
                'sometimes',
                'email',
                'max:191',
                Rule::unique('employees', 'email')->ignore($employee?->id),
            ],
            'phone' => ['nullable', 'string', 'max:40'],
            'date_of_birth' => ['nullable', 'date'],
            'tin' => ['nullable', 'string', 'max:32'],
            'nida_number' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:2000'],
            'emergency_contact_name' => ['nullable', 'string', 'max:150'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:40'],

            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'job_role_id' => [
                'nullable',
                'integer',
                'exists:job_roles,id',
                Rule::exists('job_roles', 'id')->where(
                    fn ($q) => $q->where('department_id', $this->input('department_id'))
                ),
            ],

            'employment_type' => ['sometimes', Rule::in(['full_time', 'part_time', 'contract', 'temporary'])],
            'start_date' => ['nullable', 'date'],
            'hire_date' => ['nullable', 'date'],
            'base_salary_minor' => ['sometimes', 'integer', 'min:0', 'max:9999999999'],
            'currency' => ['nullable', 'string', 'size:3'],
            'status' => ['sometimes', Rule::in(['active', 'on_leave', 'suspended', 'terminated'])],

            // Optional reset. Blank on both fields keeps the current
            // password, as the form states.
            'password' => ['nullable', 'confirmed', Password::min(8)],
        ];
    }

    public function messages(): array
    {
        return [
            'job_role_id.exists' => 'The selected role does not belong to the selected department.',
        ];
    }
}
