<?php

namespace App\Support;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Builds the OpenAPI document from the registered route table.
 *
 * The route list is the source of truth, so a path that exists is documented
 * and one that is not documented does not exist. Descriptions and request
 * shapes are supplied from the metadata table below; anything not listed
 * still appears, with a generic description, rather than being silently
 * omitted.
 */
class OpenApiGenerator
{
    /**
     * Per-path documentation. Keyed by the route URI with parameters
     * stripped, e.g. "api/employees/{employee}".
     *
     * @var array<string, array{summary: string, tags: array<int, string>, description?: string, secured?: bool}>
     */
    private const PATHS = [
        // Auth
        'api/auth/login' => ['summary' => 'Sign in', 'tags' => ['Authentication'], 'secured' => false, 'description' => 'Returns a bearer token. An unknown email and a wrong password return an identical message so the endpoint cannot be used to discover accounts.'],
        'api/auth/me' => ['summary' => 'Current user', 'tags' => ['Authentication'], 'description' => 'The signed-in user plus the linked employee record, so the frontend can route to the HR or employee portal without a second request.'],
        'api/auth/logout' => ['summary' => 'Sign out', 'tags' => ['Authentication'], 'description' => 'Revokes the token used for the request.'],
        'api/auth/password' => ['summary' => 'Change password', 'tags' => ['Authentication'], 'description' => 'Requires the current password. Other active sessions are revoked; the caller keeps their own token.'],

        // Employees
        'api/employees' => ['summary' => 'List employees', 'tags' => ['Employees'], 'description' => 'Searchable on name, role and department. HR only.'],
        'api/employees'.'' => [],
        'api/departments' => ['summary' => 'List, create departments', 'tags' => ['Organisation'], 'description' => 'Includes roles, department leads and headcount.'],
        'api/departments/{department}' => ['summary' => 'Show or update a department', 'tags' => ['Organisation']],
        'api/departments/{department}/roles' => ['summary' => 'Add roles in bulk', 'tags' => ['Organisation'], 'description' => 'Accepts an array of titles; duplicates are collapsed case-insensitively. Matches the textarea input that splits on newlines or commas.'],
        'api/departments/{department}/roles/{role}' => ['summary' => 'Update or remove a role', 'tags' => ['Organisation'], 'description' => 'Roles held by employees are deactivated rather than deleted.'],

        // Dashboard and reports
        'api/dashboard' => ['summary' => 'HR dashboard', 'tags' => ['Dashboard'], 'description' => 'Headcount, leave, payroll, money request, contract and document totals, computed from live tables.'],
        'api/me/dashboard' => ['summary' => 'Employee dashboard', 'tags' => ['Dashboard'], 'description' => 'The signed-in employee\'s own figures only.'],
        'api/me/profile' => ['summary' => 'Own profile', 'tags' => ['Employees'], 'description' => 'Compensation is withheld; the UI marks it "Managed by People Operations".'],
        'api/reports/monthly' => ['summary' => 'Monthly report figures', 'tags' => ['Reports'], 'description' => 'Month-by-month aggregates for the on-screen charts. Replaces the hard-coded reportData array.'],
        'api/reports/monthly/pdf' => ['summary' => 'Monthly report PDF', 'tags' => ['Reports']],

        // Leave
        'api/leave-requests' => ['summary' => 'List or submit leave', 'tags' => ['Leave'], 'description' => 'HR sees the whole queue; an employee sees only their own.'],
        'api/leave-requests/{leave_request}' => ['summary' => 'Show or withdraw a leave request', 'tags' => ['Leave']],
        'api/leave-requests/{leave_request}/approve' => ['summary' => 'Approve leave', 'tags' => ['Leave'], 'description' => 'Booked days move to used. Only a pending request can be decided.'],
        'api/leave-requests/{leave_request}/decline' => ['summary' => 'Decline leave', 'tags' => ['Leave'], 'description' => 'Releases the reserved days.'],
        'api/leave-balances' => ['summary' => 'Leave balances', 'tags' => ['Leave'], 'description' => 'Per leave type: entitled, used, booked and remaining. entitlement_configured distinguishes "not set" from zero.'],
        'api/me/leave-balances' => ['summary' => 'Own leave balances', 'tags' => ['Leave']],

        // Payroll
        'api/payroll/runs' => ['summary' => 'List or create payroll runs', 'tags' => ['Payroll'], 'description' => 'A run cannot be created twice for the same period.'],
        'api/payroll/runs/{run}' => ['summary' => 'Show a payroll run', 'tags' => ['Payroll']],
        'api/payroll/runs/{run}/approve' => ['summary' => 'Approve a run', 'tags' => ['Payroll'], 'description' => 'Every payslip in the run follows the run status.'],
        'api/payroll/runs/{run}/pay' => ['summary' => 'Mark a run paid', 'tags' => ['Payroll'], 'description' => 'Requires the run to be approved first.'],
        'api/payroll/runs/{run}/pdf' => ['summary' => 'Payroll report PDF', 'tags' => ['Payroll', 'Documents']],
        'api/payslips' => ['summary' => 'List payslips', 'tags' => ['Payroll'], 'description' => 'An employee only ever receives their own.'],
        'api/me/payslips' => ['summary' => 'Own payslips', 'tags' => ['Payroll']],

        // Contracts
        'api/contracts' => ['summary' => 'List or create contracts', 'tags' => ['Contracts']],
        'api/contracts/{contract}' => ['summary' => 'Update a contract', 'tags' => ['Contracts']],
        'api/contracts/{contract}/send-for-signature' => ['summary' => 'Send for signature', 'tags' => ['Contracts']],
        'api/contracts/{contract}/respond' => ['summary' => 'Accept or decline a contract', 'tags' => ['Contracts'], 'description' => 'The owner responds from their own portal.'],
        'api/contracts/{contract}/pdf' => ['summary' => 'Contract summary PDF', 'tags' => ['Contracts', 'Documents']],
        'api/contract-types' => ['summary' => 'Contract types', 'tags' => ['Contracts']],

        // Money requests
        'api/money-requests' => ['summary' => 'List or raise money requests', 'tags' => ['Money requests'], 'description' => 'The amount cap is read from settings, not hard-coded.'],
        'api/money-requests/{money_request}' => ['summary' => 'Show a money request', 'tags' => ['Money requests']],
        'api/money-requests/{money_request}/approve' => ['summary' => 'Approve a money request', 'tags' => ['Money requests']],
        'api/money-requests/{money_request}/decline' => ['summary' => 'Decline a money request', 'tags' => ['Money requests']],
        'api/money-requests/{money_request}/mark-paid' => ['summary' => 'Mark as paid', 'tags' => ['Money requests']],
        'api/money-requests/{money_request}/receipt-pdf' => ['summary' => 'Receipt PDF', 'tags' => ['Money requests', 'Documents']],

        // Documents
        'api/documents' => ['summary' => 'List or upload documents', 'tags' => ['Documents'], 'description' => 'Files are private and streamed through an authorised route; no upload is reachable by a static URL.'],
        'api/documents/{document}' => ['summary' => 'Show or delete a document', 'tags' => ['Documents']],
        'api/documents/{document}/replace' => ['summary' => 'Replace a document', 'tags' => ['Documents'], 'description' => 'The previous file is removed once the row points at the new one.'],
        'api/files/{document}/download' => ['summary' => 'Download a document', 'tags' => ['Documents'], 'description' => 'HR, or the owning employee. The URL is built per response, so it can be revoked.'],
        'api/employees/{employee}/avatar' => ['summary' => 'Profile image', 'tags' => ['Documents']],
        'api/employees/{employee}/record-pdf' => ['summary' => 'Employee record PDF', 'tags' => ['Documents'], 'description' => 'HR only.'],

        // Notifications
        'api/notifications' => ['summary' => 'Notification feed', 'tags' => ['Notifications'], 'description' => 'Read state is per recipient, which is what the unread badge needs.'],
        'api/notifications/{id}/read' => ['summary' => 'Mark one as read', 'tags' => ['Notifications']],
        'api/notifications/read-all' => ['summary' => 'Mark all as read', 'tags' => ['Notifications']],

        // Documents / PDFs
        'api/payslips/{payslip}/pdf' => ['summary' => 'Payslip PDF', 'tags' => ['Payroll', 'Documents'], 'description' => 'A server-rendered PDF. The employee may download only their own.'],
        'api/me/work-progress/pdf' => ['summary' => 'Work progress PDF', 'tags' => ['Reports', 'Documents']],
    ];

    /**
     * @return array<string, mixed>
     */
    public function generate(): array
    {
        $paths = [];

        foreach (RouteFacade::getRoutes() as $route) {
            if (! $this->isApiRoute($route)) {
                continue;
            }

            $path = '/'.ltrim($route->uri(), '/');
            $meta = $this->metadataFor($route);
            $secured = $meta['secured'] ?? $this->requiresAuth($route);

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $paths[$path][strtolower($method)] = $this->operation(
                    $route,
                    $method,
                    $meta,
                    $secured
                );
            }
        }

        ksort($paths);

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'GoldenHR API',
                'version' => '1.0.0',
                'description' => 'Backend for the GoldenHR people platform: employees, departments, leave, payroll, contracts, money requests, documents and notifications.'
                    ."\n\nAuthentication is a bearer token from `POST /api/auth/login`."
                    ."\n\nHR routes require the `hr_admin` or `hr_officer` role. Money is returned in integer shillings in columns suffixed `_minor`;"
                    .' payslip gross, total deductions and net are computed by the database and cannot be written through the API.',
            ],
            'servers' => [
                ['url' => rtrim((string) config('app.url'), '/'), 'description' => 'Production'],
            ],
            'tags' => [
                ['name' => 'Authentication'],
                ['name' => 'Employees'],
                ['name' => 'Organisation'],
                ['name' => 'Dashboard'],
                ['name' => 'Leave'],
                ['name' => 'Payroll'],
                ['name' => 'Contracts'],
                ['name' => 'Money requests'],
                ['name' => 'Documents'],
                ['name' => 'Reports'],
                ['name' => 'Notifications'],
            ],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'description' => 'Paste the token returned by POST /api/auth/login.',
                    ],
                ],
            ],
            'security' => [['bearerAuth' => []]],
            'paths' => $paths,
        ];
    }

    /**
     * @return array{summary: string, tags: array<int, string>, description?: string, secured?: bool}
     */
    private function metadataFor(Route $route): array
    {
        $uri = $route->uri();
        $found = self::PATHS[$uri] ?? null;

        if ($found !== null) {
            return $found;
        }

        // GET and POST on the same path share an entry, so fall back to the
        // method when the bare path is ambiguous.
        return [
            'summary' => ucfirst(str_replace(['-', '_'], ' ', $route->uri())),
            'tags' => [ucfirst(explode('/', $route->uri())[1] ?? 'Other')],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function operation(Route $route, string $method, array $meta, bool $secured): array
    {
        $operation = [
            'tags' => $meta['tags'] ?? ['Other'],
            'summary' => $meta['summary'] ?? 'Endpoint',
            'operationId' => $method.'_'.preg_replace('/[^a-z0-9]+/i', '_', $route->uri()),
            'responses' => $this->responsesFor($method, $secured),
        ];

        if (! empty($meta['description'])) {
            $operation['description'] = $meta['description'];
        }

        if (! $secured) {
            $operation['security'] = [];
        }

        $parameters = $this->parametersFor($route);
        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $operation['requestBody'] = $this->requestBodyFor($route);
        }

        return $operation;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parametersFor(Route $route): array
    {
        $parameters = [];

        foreach ($route->parameterNames() as $name) {
            $parameters[] = [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'integer', 'format' => 'int64'],
            ];
        }

        // Document the filters the list endpoints accept.
        $queryMap = [
            'search' => ['type' => 'string', 'description' => 'Free text search.'],
            'status' => ['type' => 'string', 'description' => 'Filter by status. Use "all" to include every status.'],
            'year' => ['type' => 'integer', 'description' => 'Reporting year.'],
            'month' => ['type' => 'integer', 'description' => 'Reporting month, 1-12.'],
            'employee_id' => ['type' => 'integer', 'description' => 'Restrict to one employee. Ignored for non-HR callers, who always see only their own records.'],
            'department_id' => ['type' => 'integer'],
            'job_role_id' => ['type' => 'integer'],
            'per_page' => ['type' => 'integer', 'default' => 25, 'maximum' => 100],
            'inline' => ['type' => 'boolean', 'description' => 'Render the file in the browser instead of downloading it.'],
        ];

        foreach ($queryMap as $name => $schema) {
            $parameters[] = array_merge(['name' => $name, 'in' => 'query', 'required' => false], ['schema' => $schema]);
        }

        return $parameters;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestBodyFor(Route $route): array
    {
        $uri = $route->uri();

        $json = [
            'api/auth/login' => [
                'required' => true,
                'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['email', 'password'], 'properties' => [
                    'email' => ['type' => 'string', 'format' => 'email'],
                    'password' => ['type' => 'string', 'format' => 'password'],
                    'device_name' => ['type' => 'string', 'example' => 'web'],
                ]]]],
            ],
            'api/auth/password' => [
                'required' => true,
                'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['current_password', 'new_password'], 'properties' => [
                    'current_password' => ['type' => 'string', 'format' => 'password'],
                    'new_password' => ['type' => 'string', 'format' => 'password', 'minLength' => 8],
                    'new_password_confirmation' => ['type' => 'string', 'format' => 'password'],
                ]]]],
            ],
            'api/employees' => [
                'required' => true,
                'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['name', 'email', 'employment_type', 'base_salary_minor'], 'properties' => [
                    'name' => ['type' => 'string', 'example' => 'Amara Okafor'],
                    'email' => ['type' => 'string', 'format' => 'email'],
                    'phone' => ['type' => 'string', 'example' => '+255 712 448 201'],
                    'date_of_birth' => ['type' => 'string', 'format' => 'date'],
                    'tin' => ['type' => 'string', 'description' => 'Tanzania tax identification number.'],
                    'nida_number' => ['type' => 'string'],
                    'address' => ['type' => 'string'],
                    'emergency_contact_name' => ['type' => 'string'],
                    'emergency_contact_phone' => ['type' => 'string'],
                    'department_id' => ['type' => 'integer'],
                    'job_role_id' => ['type' => 'integer', 'description' => 'Must belong to the chosen department.'],
                    'employment_type' => ['type' => 'string', 'enum' => ['full_time', 'part_time', 'contract', 'temporary']],
                    'start_date' => ['type' => 'string', 'format' => 'date'],
                    'hire_date' => ['type' => 'string', 'format' => 'date'],
                    'base_salary_minor' => ['type' => 'integer', 'description' => 'Whole shillings, not a formatted string.', 'example' => 4860000],
                    'password' => ['type' => 'string', 'format' => 'password', 'minLength' => 8, 'description' => 'Creates a working portal login for the employee.'],
                    'password_confirmation' => ['type' => 'string', 'format' => 'password'],
                ]]]],
            ],
            'api/leave-requests' => [
                'required' => true,
                'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['leave_type_id', 'start_date', 'end_date', 'days'], 'properties' => [
                    'leave_type_id' => ['type' => 'integer'],
                    'start_date' => ['type' => 'string', 'format' => 'date'],
                    'end_date' => ['type' => 'string', 'format' => 'date'],
                    'days' => ['type' => 'number', 'example' => 5],
                    'reason' => ['type' => 'string'],
                    'coverage_employee_id' => ['type' => 'integer', 'description' => 'Colleague covering the absence.'],
                ]]]],
            ],
            'api/money-requests' => [
                'required' => true,
                'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['money_request_type_id', 'amount_minor'], 'properties' => [
                    'money_request_type_id' => ['type' => 'integer'],
                    'amount_minor' => ['type' => 'integer', 'example' => 900000],
                    'reason' => ['type' => 'string'],
                    'needed_by' => ['type' => 'string', 'format' => 'date'],
                    'payment_method' => ['type' => 'string', 'enum' => ['bank_transfer', 'mobile_money']],
                    'document_id' => ['type' => 'integer', 'description' => 'Required for request types that need a receipt.'],
                ]]]],
            ],
            'api/payroll/runs' => [
                'required' => true,
                'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['period_year', 'period_month', 'lines'], 'properties' => [
                    'period_year' => ['type' => 'integer', 'example' => 2026],
                    'period_month' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 12],
                    'lines' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'object', 'required' => ['employee_id', 'basic_minor'], 'properties' => [
                        'employee_id' => ['type' => 'integer'],
                        'basic_minor' => ['type' => 'integer'],
                        'overtime_minor' => ['type' => 'integer'],
                        'bonus_minor' => ['type' => 'integer'],
                        'allowance_minor' => ['type' => 'integer'],
                        'tax_minor' => ['type' => 'integer', 'description' => 'PAYE income tax.'],
                        'pension_minor' => ['type' => 'integer', 'description' => 'NSSF pension.'],
                        'other_deduction_minor' => ['type' => 'integer'],
                        'payment_method' => ['type' => 'string', 'enum' => ['bank_transfer', 'mobile_money']],
                    ]]],
                ]]]],
            ],
            'api/contracts' => [
                'required' => true,
                'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['employee_id', 'contract_type_id', 'start_date', 'base_salary_minor'], 'properties' => [
                    'employee_id' => ['type' => 'integer'],
                    'contract_type_id' => ['type' => 'integer'],
                    'start_date' => ['type' => 'string', 'format' => 'date'],
                    'duration_days' => ['type' => 'integer', 'description' => 'The end date is derived as start + duration. Omit for an open-ended contract.'],
                    'end_date' => ['type' => 'string', 'format' => 'date'],
                    'base_salary_minor' => ['type' => 'integer'],
                    'description' => ['type' => 'string'],
                    'termination_terms' => ['type' => 'string'],
                ]]]],
            ],
        ];

        if (isset($json[$uri])) {
            return $json[$uri];
        }

        // Document upload endpoints.
        if (in_array($route->methods()[0] ?? '', ['POST'], true) && str_contains($uri, 'documents')) {
            return [
                'required' => true,
                'content' => ['multipart/form-data' => ['schema' => [
                    'type' => 'object',
                    'required' => ['file'],
                    'properties' => [
                        'file' => ['type' => 'string', 'format' => 'binary', 'description' => 'PDF, DOC, DOCX, JPG, JPEG or PNG. The content type is checked against the file contents, so a renamed file is rejected.'],
                        'employee_id' => ['type' => 'integer'],
                        'category_id' => ['type' => 'integer'],
                        'status' => ['type' => 'string', 'enum' => ['verified', 'pending_review', 'expiring_soon']],
                    ],
                ]]],
            ];
        }

        if (isset($json[$uri])) {
            return $json[$uri];
        }

        return [
            'required' => false,
            'content' => ['application/json' => ['schema' => ['type' => 'object', 'additionalProperties' => true]]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function responsesFor(string $method, bool $secured): array
    {
        $responses = [];

        if (in_array($method, ['GET', 'POST'], true)) {
            $responses['200'] = ['description' => 'Success'];
        } elseif ($method === 'DELETE') {
            $responses['200'] = ['description' => 'Deleted'];
        } else {
            $responses['200'] = ['description' => 'Updated'];
        }

        if ($method === 'POST') {
            $responses['201'] = ['description' => 'Created'];
        }

        if ($secured) {
            $responses['401'] = ['description' => 'Unauthenticated: missing or invalid token.'];
        }

        $responses['403'] = ['description' => 'Forbidden: the record belongs to someone else, or the role is insufficient.'];
        $responses['422'] = ['description' => 'Validation failed, or a business rule rejected the request.'];

        return $responses;
    }

    private function requiresAuth(Route $route): bool
    {
        $middleware = $route->gatherMiddleware();

        foreach (['auth:sanctum', 'auth'] as $guard) {
            if (in_array($guard, $middleware, true)) {
                return true;
            }
        }

        return false;
    }

    private function isApiRoute(Route $route): bool
    {
        if (! str_starts_with($route->uri(), 'api/')) {
            return false;
        }

        // Skip framework internals such as sanctum/csrf-cookie.
        return ! str_contains($route->uri(), 'sanctum/');
    }
}
