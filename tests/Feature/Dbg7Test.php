<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\MoneyRequestType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Dbg7Test extends TestCase
{
    use RefreshDatabase;

    public function test_dbg(): void
    {
        DB::table('settings')->insert(['key' => 'advance_limit_minor', 'value' => json_encode(2500000), 'created_at' => now(), 'updated_at' => now()]);
        $e = Employee::factory()->create();
        $u = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'employee_id' => $e->id]);
        $t = MoneyRequestType::create(['name' => 'Salary advance', 'requires_receipt' => false]);
        $hr = User::factory()->hrAdmin()->create();
        $id = $this->actingAs($u)->postJson('/api/money-requests', ['money_request_type_id' => $t->id, 'amount_minor' => 900000])->json('money_request.id');
        $r = $this->actingAs($hr)->postJson("/api/money-requests/{$id}/approve", ['note' => 'x']);
        fwrite(STDERR, "\nSTATUS=".$r->status()."\n".json_encode($r->json(), JSON_PRETTY_PRINT)."\n");
        $this->assertTrue(true);
    }
}
