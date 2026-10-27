<?php

use App\Jobs\SendDefenseCountdownEmail;
use App\Mail\DefenseCountdownReminder;
use App\Models\DefenseRequest;
use App\Models\Notification;
use App\Models\User;
use App\Services\DefenseReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);
beforeEach(function () {
    Schema::create('users', function (Blueprint $t) {
        $t->id();
        foreach (['first_name', 'last_name', 'email', 'role', 'program'] as $c) {
            $t->string($c)->nullable();
        }
        $t->timestamp('email_verified_at')->nullable();
        $t->timestamps();
    });
    Schema::create('defense_requests', function (Blueprint $t) {
        $t->id();
        foreach (['submitted_by', 'adviser_user_id', 'coordinator_user_id'] as $c) {
            $t->unsignedBigInteger($c)->nullable();
        }
        foreach (['thesis_title', 'program', 'workflow_state', 'status', 'scheduled_time', 'scheduled_end_time', 'defense_venue'] as $c) {
            $t->string($c)->nullable();
        }
        $t->date('scheduled_date')->nullable();
        $t->json('workflow_history')->nullable();
        $t->timestamps();
    });
    $file = glob(database_path('migrations/*create_notifications_table.php'))[0];
    (require $file)->up();
    (require database_path('migrations/2026_10_23_000003_add_notification_event_tracking.php'))->up();
    foreach (['Student', 'Adviser', 'Coordinator'] as $i => $role) {
        DB::table('users')->insert(['id' => $i + 1, 'first_name' => 'Test', 'last_name' => 'User', 'role' => $role, 'program' => 'Test', 'email' => strtolower($role).'@example.test', 'email_verified_at' => now()]);
    }
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-23 09:00:00', 'Asia/Manila'));
});
afterEach(function () {
    CarbonImmutable::setTestNow();
    foreach (['notifications', 'defense_requests', 'users'] as $table) {
        Schema::dropIfExists($table);
    }
});
function notificationDefense(array $attributes = []): DefenseRequest
{
    return DefenseRequest::create(array_merge(['submitted_by' => 1, 'adviser_user_id' => 2, 'coordinator_user_id' => 3, 'program' => 'Test', 'thesis_title' => 'Research Study', 'workflow_state' => 'submitted', 'status' => 'Pending'], $attributes));
}
it('notifies the adviser and coordinator once on submission', function () {
    $r = notificationDefense();
    $r->touch();
    expect(Notification::where('user_id', 2)->count())->toBe(1)->and(Notification::where('user_id', 3)->count())->toBe(1)->and(Notification::where('user_id', 1)->count())->toBe(0);
});
it('notifies the student and coordinator on endorsement and student on approval', function () {
    $r = notificationDefense();
    $r->update(['workflow_state' => 'adviser-approved']);
    expect(Notification::where('user_id', 1)->value('title'))->toBe('Adviser Endorsed Your Defense Request');
    expect(Notification::where('user_id', 3)->count())->toBe(2);
    $r->update(['workflow_state' => 'coordinator-approved']);
    $r->touch();
    expect(Notification::where('user_id', 1)->where('title', 'Defense Request Approved')->count())->toBe(1);
});
it('sends a correct daily countdown without duplicate reminders', function () {
    notificationDefense(['workflow_state' => 'scheduled', 'scheduled_date' => '2026-10-26', 'scheduled_time' => '13:00:00', 'defense_venue' => 'Room 1']);
    $service = app(DefenseReminderService::class);
    expect($service->send())->toBe(1)->and($service->send())->toBe(0);
    expect(Notification::where('type', 'defense-reminder')->value('message'))->toContain('3 days remaining');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-24 09:00:00', 'Asia/Manila'));
    expect($service->send())->toBe(1);
    expect(Notification::where('type', 'defense-reminder')->latest('id')->value('message'))->toContain('2 days remaining');
});
it('excludes cancelled completed unapproved distant and elapsed defenses', function () {
    foreach (['cancelled', 'completed', 'adviser-review'] as $state) {
        notificationDefense(['workflow_state' => $state, 'scheduled_date' => '2026-10-26']);
    }
    notificationDefense(['workflow_state' => 'scheduled', 'scheduled_date' => '2026-11-10']);
    notificationDefense(['workflow_state' => 'scheduled', 'scheduled_date' => '2026-10-23', 'scheduled_time' => '08:00:00']);
    expect(app(DefenseReminderService::class)->send())->toBe(0);
});
it('clears stale reminders on rescheduling and cancellation', function () {
    $r = notificationDefense(['workflow_state' => 'scheduled', 'scheduled_date' => '2026-10-26', 'scheduled_time' => '13:00:00']);
    app(DefenseReminderService::class)->send();
    $r->update(['scheduled_date' => '2026-10-27']);
    expect(Notification::where('type', 'defense-reminder')->where('read', false)->count())->toBe(0);
    expect(app(DefenseReminderService::class)->send())->toBe(1);
    $r->update(['workflow_state' => 'cancelled']);
    expect(Notification::where('type', 'defense-reminder')->where('read', false)->count())->toBe(0);
});
it('protects notification ownership and returns only the current users feed', function () {
    notificationDefense();
    $student = User::find(1);
    $other = Notification::where('user_id', 2)->first();
    $this->actingAs($student)->patchJson('/notifications/'.$other->id.'/read')->assertForbidden();
    $this->getJson('/notifications/feed')->assertOk()->assertJsonCount(0, 'notifications');
    $this->postJson('/notifications/read-all')->assertOk();
    expect($other->fresh()->read)->toBeFalse();
});
it('reminds on defense day before its start and stops after it starts', function () {
    notificationDefense(['workflow_state' => 'scheduled', 'scheduled_date' => '2026-10-23', 'scheduled_time' => '13:00:00']);
    expect(app(DefenseReminderService::class)->send())->toBe(1);
    expect(Notification::where('type', 'defense-reminder')->value('message'))->toContain('Your defense is today');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-23 14:00:00', 'Asia/Manila'));
    expect(app(DefenseReminderService::class)->send())->toBe(0);
});

it('requires an accepted assignment and resolves its adviser when the form omits it', function () {
    Schema::create('settings', function (Blueprint $t) {
        $t->string('key');
        $t->string('value')->nullable();
    });
    Schema::create('adviser_student', function (Blueprint $t) {
        $t->unsignedBigInteger('adviser_id');
        $t->unsignedBigInteger('student_id');
        $t->string('status');
        $t->unsignedBigInteger('requested_by')->nullable();
        $t->timestamps();
    });
    try {
        $this->actingAs(User::find(1))->post(route('defense-requirements.store'), [])->assertSessionHasErrors('adviser');
        DB::table('adviser_student')->insert(['adviser_id' => 2, 'student_id' => 1, 'status' => 'pending']);
        $this->post(route('defense-requirements.store'), [])->assertSessionHasErrors('adviser');
        DB::table('adviser_student')->update(['status' => 'accepted']);
        // The accepted assignment supplies adviser/name, even from a stale form.
        $this->post(route('defense-requirements.store'), [])->assertSessionHasErrors('thesis_title')->assertSessionDoesntHaveErrors('adviser');
        $this->post(route('defense-requirements.store'), ['adviser_id' => 3])->assertSessionHasErrors('adviser');
    } finally {
        Schema::dropIfExists('adviser_student');
        Schema::dropIfExists('settings');
    }
});
it('queues one optional countdown email and skips it after cancellation', function () {
    config(['defense-notifications.email_enabled' => true]);
    Queue::fake();
    Mail::fake();
    $r = notificationDefense(['workflow_state' => 'scheduled', 'scheduled_date' => '2026-10-26', 'scheduled_time' => '13:00:00']);
    $service = app(DefenseReminderService::class);
    $service->send();
    $service->send();
    Queue::assertPushed(SendDefenseCountdownEmail::class, 1);
    $notice = Notification::where('type', 'defense-reminder')->first();
    $job = new SendDefenseCountdownEmail($notice->id, '2026-10-23', '2026-10-26', '13:00:00');
    $job->handle();
    Mail::assertSent(DefenseCountdownReminder::class, 1);
    $r->update(['workflow_state' => 'cancelled']);
    $job->handle();
    Mail::assertSent(DefenseCountdownReminder::class, 1);
});
it('renders the countdown email with the latest request link', function () {
    $html = (new DefenseCountdownReminder('Demo', '3 days remaining before your defense'))->render();
    expect($html)->toContain('3 days remaining')->toContain('/defense-requirements');
});
