<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Payslip;
use App\Models\User;
use App\Notifications\PayrollApproved;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationFeedTest extends TestCase
{
    use RefreshDatabase;

    private function employeeUser(): User
    {
        $employee = Employee::factory()->create();

        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employee_id' => $employee->id,
        ]);

        $employee->forceFill(['user_id' => $user->id])->save();

        return $user->fresh();
    }

    public function test_the_feed_requires_authentication(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();
    }

    public function test_a_notification_appears_in_the_feed_as_unread(): void
    {
        $user = $this->employeeUser();

        $this->assertSame(0, $user->unreadNotifications()->count());

        Notification::send($user, new PayrollApproved(
            Payslip::factory()->create()
        ));

        $response = $this->actingAs($user)->getJson('/api/notifications')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame(1, $response->json('meta.unread_count'));
        $this->assertFalse($response->json('data.0.read'));
    }

    public function test_marking_read_clears_the_badge(): void
    {
        $user = $this->employeeUser();

        $notification = new PayrollApproved(
            Payslip::factory()->create()
        );

        Notification::send($user, $notification);

        $id = $user->fresh()->notifications()->first()->id;

        $this->actingAs($user)->postJson("/api/notifications/{$id}/read")->assertOk();

        $this->assertSame(0, $user->fresh()->unreadNotifications()->count());

        $this->actingAs($user)->getJson('/api/notifications?unread=1')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_mark_all_read_clears_everything(): void
    {
        $user = $this->employeeUser();

        foreach (range(1, 3) as $i) {
            Notification::send($user, new PayrollApproved(
                Payslip::factory()->create(['reference' => 'PR-T-'.$i])
            ));
        }

        $this->assertSame(3, $user->unreadNotifications()->count());

        $this->actingAs($user)->postJson('/api/notifications/read-all')->assertOk();

        $this->assertSame(0, $user->fresh()->unreadNotifications()->count());
    }

    /**
     * One user's notification must not be readable or mutable by another.
     */
    public function test_a_notification_cannot_be_read_by_another_user(): void
    {
        $owner = $this->employeeUser();
        $other = $this->employeeUser();

        Notification::send($owner, new PayrollApproved(
            Payslip::factory()->create()
        ));

        $id = $owner->fresh()->notifications()->first()->id;

        // actingAs only sets the default user; the resolved guard is cached,
        // so it must be cleared before switching to the other account.
        $this->app['auth']->forgetGuards();

        $this->actingAs($other)->postJson("/api/notifications/{$id}/read")->assertNotFound();
        $this->actingAs($other)->deleteJson("/api/notifications/{$id}")->assertNotFound();

        $this->assertSame(1, $owner->fresh()->unreadNotifications()->count());
    }
}
