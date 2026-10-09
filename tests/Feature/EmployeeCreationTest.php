<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\JobRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EmployeeCreationTest extends TestCase
{
    use RefreshDatabase;

    private function hr(): User
    {
        return User::factory()->hrAdmin()->create();
    }

    private function departmentWithRole(): array
    {
        $department = Department::create(['name' => 'Design']);

        return [$department, JobRole::create([
            'department_id' => $department->id,
            'title' => 'Product Designer',
            'level' => 'mid',
        ])];
    }

    /**
     * The behaviour the whole phase exists for: the employee form collects a
     * password, and that password must immediately work for the employee.
     */
    public function test_creating_an_employee_with_a_password_produces_a_working_login(): void
    {
        [$department, $role] = $this->departmentWithRole();

        $response = $this->actingAs($this->hr())->postJson('/api/employees', [
            'name' => 'Amara Okafor',
            'email' => 'amara@goldenhr.com',
            'phone' => '+255 712 448 201',
            'date_of_birth' => '1996-04-18',
            'tin' => 'TIN-4482-9917',
            'nida_number' => '19901234-56789-00001-12',
            'department_id' => $department->id,
            'job_role_id' => $role->id,
            'employment_type' => 'full_time',
            'start_date' => '2023-05-12',
            'hire_date' => '2023-05-12',
            'base_salary_minor' => 4860000,
            'password' => 'AmaraStrong2026',
            'password_confirmation' => 'AmaraStrong2026',
        ]);

        $response->assertCreated()
            ->assertJsonPath('login_created', true)
            ->assertJsonPath('employee.name', 'Amara Okafor')
            ->assertJsonPath('employee.employee_code', 'GH-0001')
            ->assertJsonPath('employee.department', 'Design')
            ->assertJsonPath('employee.role', 'Product Designer')
            ->assertJsonPath('employee.employment_type_label', 'Full time');

        // The name is split for sorting and searching.
        $this->assertDatabaseHas('employees', [
            'first_name' => 'Amara',
            'last_name' => 'Okafor',
            'base_salary_minor' => 4860000,
        ]);

        // The login exists and is linked both ways.
        $user = User::where('email', 'amara@goldenhr.com')->firstOrFail();
        $this->assertSame('employee', $user->role);
        $this->assertTrue(Hash::check('AmaraStrong2026', $user->password));
        $this->assertNotNull($user->employee_id);

        // The role assignment was opened.
        $this->assertDatabaseHas('employee_assignments', [
            'employee_id' => $employeeId = Employee::where('email', 'amara@goldenhr.com')->value('id'),
            'job_role_id' => $role->id,
            'is_current' => true,
        ]);

        // The decisive check: the new employee can actually sign in.
        $this->postJson('/api/auth/login', [
            'email' => 'amara@goldenhr.com',
            'password' => 'AmaraStrong2026',
        ])
            ->assertOk()
            ->assertJsonPath('user.role', 'employee')
            ->assertJsonPath('user.employee.full_name', 'Amara Okafor')
            ->assertJsonPath('user.is_hr', false);
    }

    public function test_creating_an_employee_without_a_password_creates_no_login(): void
    {
        [$department, $role] = $this->departmentWithRole();

        $response = $this->actingAs($this->hr())->postJson('/api/employees', [
            'name' => 'Noah Williams',
            'email' => 'noah@goldenhr.com',
            'department_id' => $department->id,
            'job_role_id' => $role->id,
            'employment_type' => 'full_time',
            'hire_date' => '2022-01-08',
            'base_salary_minor' => 6420000,
        ]);

        $response->assertCreated()->assertJsonPath('login_created', false);

        $this->assertDatabaseMissing('users', ['email' => 'noah@goldenhr.com']);
    }

    public function test_password_must_be_confirmed(): void
    {
        [$department, $role] = $this->departmentWithRole();

        $this->actingAs($this->hr())->postJson('/api/employees', [
            'name' => 'Sofia Martin',
            'email' => 'sofia@goldenhr.com',
            'department_id' => $department->id,
            'job_role_id' => $role->id,
            'employment_type' => 'full_time',
            'base_salary_minor' => 3920000,
            'password' => 'SofiaStrong2026',
            'password_confirmation' => 'something-else',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        // Nothing half-written survives a rejected request.
        $this->assertDatabaseMissing('employees', ['email' => 'sofia@goldenhr.com']);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        [$department, $role] = $this->departmentWithRole();

        $payload = [
            'name' => 'Liam Chen',
            'email' => 'liam@goldenhr.com',
            'department_id' => $department->id,
            'job_role_id' => $role->id,
            'employment_type' => 'full_time',
            'base_salary_minor' => 3100000,
        ];

        $this->actingAs($this->hr())->postJson('/api/employees', $payload)->assertCreated();

        $this->actingAs($this->hr())->postJson('/api/employees', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    /**
     * The form restricts the role dropdown to the chosen department, so the
     * server must reject a mismatched pair rather than trust the client.
     */
    public function test_role_must_belong_to_the_selected_department(): void
    {
        [$design, $designRole] = $this->departmentWithRole();

        $engineering = Department::create(['name' => 'Engineering']);
        $engineeringRole = JobRole::create([
            'department_id' => $engineering->id,
            'title' => 'Engineering Lead',
            'level' => 'lead',
        ]);

        $this->actingAs($this->hr())->postJson('/api/employees', [
            'name' => 'Mismatched Person',
            'email' => 'mismatch@goldenhr.com',
            'department_id' => $design->id,
            'job_role_id' => $engineeringRole->id,
            'employment_type' => 'full_time',
            'base_salary_minor' => 1000000,
        ])->assertStatus(422)->assertJsonValidationErrors('job_role_id');
    }

    public function test_an_employee_cannot_be_created_by_a_non_hr_user(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]))
            ->postJson('/api/employees', [
                'name' => 'Nope Person',
                'email' => 'nope@goldenhr.com',
                'employment_type' => 'full_time',
                'base_salary_minor' => 1000000,
            ])
            ->assertStatus(403);
    }

    public function test_creation_requires_authentication(): void
    {
        $this->postJson('/api/employees', [])->assertUnauthorized();
    }

    /**
     * Changing role must close the previous assignment rather than leaving
     * two rows flagged current.
     */
    public function test_changing_role_closes_the_previous_assignment(): void
    {
        [$design, $designRole] = $this->departmentWithRole();
        $hr = $this->hr();

        $employeeId = $this->actingAs($hr)->postJson('/api/employees', [
            'name' => 'Maya Patel',
            'email' => 'maya@goldenhr.com',
            'department_id' => $design->id,
            'job_role_id' => $designRole->id,
            'employment_type' => 'full_time',
            'base_salary_minor' => 4200000,
        ])->assertCreated()->json('employee.id');

        $engineering = Department::create(['name' => 'Engineering']);
        $leadRole = JobRole::create([
            'department_id' => $engineering->id,
            'title' => 'Engineering Lead',
            'level' => 'lead',
        ]);

        $this->actingAs($hr)->patchJson("/api/employees/{$employeeId}", [
            'department_id' => $engineering->id,
            'job_role_id' => $leadRole->id,
        ])->assertOk()->assertJsonPath('employee.role', 'Engineering Lead');

        // Exactly one current assignment, pointing at the new role.
        $current = DB::table('employee_assignments')
            ->where('employee_id', $employeeId)
            ->where('is_current', true)
            ->get();

        $this->assertCount(1, $current);
        $this->assertSame($leadRole->id, $current->first()->job_role_id);

        // History retained.
        $this->assertSame(2, DB::table('employee_assignments')->where('employee_id', $employeeId)->count());
    }

    public function test_blank_password_fields_keep_the_existing_password(): void
    {
        [$department, $role] = $this->departmentWithRole();
        $hr = $this->hr();

        $employeeId = $this->actingAs($hr)->postJson('/api/employees', [
            'name' => 'Sofia Martin',
            'email' => 'sofia@goldenhr.com',
            'department_id' => $department->id,
            'job_role_id' => $role->id,
            'employment_type' => 'full_time',
            'base_salary_minor' => 3920000,
            'password' => 'SofiaStrong2026',
            'password_confirmation' => 'SofiaStrong2026',
        ])->assertCreated()->json('employee.id');

        $originalHash = User::where('email', 'sofia@goldenhr.com')->value('password');

        // Both fields blank, as the form instructs.
        $response = $this->actingAs($hr)->patchJson("/api/employees/{$employeeId}", [
            'phone' => '+255 716 229 810',
            'password' => '',
            'password_confirmation' => '',
        ]);

        $response->assertOk()->assertJsonPath('password_reset', false);
        $this->assertSame($originalHash, User::where('email', 'sofia@goldenhr.com')->value('password'));
    }

    public function test_hr_can_reset_an_employee_password_and_the_new_one_works(): void
    {
        [$department, $role] = $this->departmentWithRole();
        $hr = $this->hr();

        $employeeId = $this->actingAs($hr)->postJson('/api/employees', [
            'name' => 'Noah Williams',
            'email' => 'noah@goldenhr.com',
            'department_id' => $department->id,
            'job_role_id' => $role->id,
            'employment_type' => 'full_time',
            'base_salary_minor' => 6420000,
            'password' => 'NoahStrong2026',
            'password_confirmation' => 'NoahStrong2026',
        ])->assertCreated()->json('employee.id');

        $this->postJson('/api/auth/login', ['email' => 'noah@goldenhr.com', 'password' => 'NoahStrong2026'])
            ->assertOk();

        $this->actingAs($hr)->patchJson("/api/employees/{$employeeId}", [
            'password' => 'NoahReset2026',
            'password_confirmation' => 'NoahReset2026',
        ])->assertOk()->assertJsonPath('password_reset', true);

        $this->postJson('/api/auth/login', ['email' => 'noah@goldenhr.com', 'password' => 'NoahStrong2026'])
            ->assertStatus(422);

        $this->postJson('/api/auth/login', ['email' => 'noah@goldenhr.com', 'password' => 'NoahReset2026'])
            ->assertOk();
    }

    public function test_terminating_an_employee_disables_their_login(): void
    {
        [$department, $role] = $this->departmentWithRole();
        $hr = $this->hr();

        $employeeId = $this->actingAs($hr)->postJson('/api/employees', [
            'name' => 'Luca Rossi',
            'email' => 'luca@goldenhr.com',
            'department_id' => $department->id,
            'job_role_id' => $role->id,
            'employment_type' => 'contract',
            'base_salary_minor' => 3500000,
            'password' => 'LucaStrong2026',
            'password_confirmation' => 'LucaStrong2026',
        ])->assertCreated()->json('employee.id');

        $this->actingAs($hr)->deleteJson("/api/employees/{$employeeId}")->assertOk();

        // The record survives for payroll and document history.
        $this->assertDatabaseHas('employees', ['id' => $employeeId, 'status' => 'terminated']);

        // But they can no longer sign in.
        $this->postJson('/api/auth/login', ['email' => 'luca@goldenhr.com', 'password' => 'LucaStrong2026'])
            ->assertStatus(422);
    }

    public function test_the_employee_self_endpoint_hides_salary(): void
    {
        [$department, $role] = $this->departmentWithRole();
        $hr = $this->hr();

        $employeeId = $this->actingAs($hr)->postJson('/api/employees', [
            'name' => 'Amara Okafor',
            'email' => 'amara@goldenhr.com',
            'department_id' => $department->id,
            'job_role_id' => $role->id,
            'employment_type' => 'full_time',
            'base_salary_minor' => 4860000,
            'password' => 'AmaraStrong2026',
            'password_confirmation' => 'AmaraStrong2026',
        ])->assertCreated()->json('employee.id');

        $token = User::where('email', 'amara@goldenhr.com')->firstOrFail()->createToken('t')->plainTextToken;

        // The auth manager caches the resolved user for the lifetime of the
        // test process; without this the request would still be acting as
        // the HR admin from the earlier call.
        $this->app['auth']->forgetGuards();

        $response = $this->withToken($token)->getJson('/api/me/profile')->assertOk();

        $payload = $response->json('employee');

        $this->assertArrayNotHasKey('base_salary_minor', $payload);
        $this->assertArrayNotHasKey('currency', $payload);
        $this->assertSame('People Operations', $payload['salary_managed_by']);
        $this->assertSame('Design', $payload['department']);
    }
}
