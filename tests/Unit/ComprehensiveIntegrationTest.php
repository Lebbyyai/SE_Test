<?php

use App\Models\User;
use App\Services\ComprehensiveEligibilityService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

uses(TestCase::class);
beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    $this->mock(ComprehensiveEligibilityService::class)->shouldReceive('check')->andReturn(['eligible' => true]);
    Schema::create('users', function (Blueprint $t) {
        $t->id();
        foreach (['first_name', 'last_name', 'school_id', 'role', 'email', 'program'] as $f) {
            $t->string($f)->nullable();
        }$t->timestamp('email_verified_at')->nullable();
        $t->timestamps();
    });
    Schema::create('advisers', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('user_id');
        $t->string('status');
    });
    Schema::create('defense_requests', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('submitted_by')->nullable();
        $t->string('workflow_state')->nullable();
        $t->string('status')->nullable();
        $t->date('scheduled_date')->nullable();
    });
    Schema::create('notifications', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('user_id');
        $t->boolean('read')->default(false);
        $t->timestamps();
    });
    foreach (['exam_application', 'exam_application_subject'] as $table) {
        (require glob(database_path('migrations/*create_'.$table.'_table.php'))[0])->up();
    }
    (require database_path('migrations/2026_10_24_000002_add_exam_subject_schedule_fields.php'))->up();
    foreach (['Student', 'Student', 'Finance'] as $i => $role) {
        DB::table('users')->insert(['id' => $i + 1, 'first_name' => 'Test', 'last_name' => 'User', 'school_id' => 'COMPRE-'.($i + 1), 'role' => $role, 'email' => 'compre'.($i + 1).'@example.test', 'email_verified_at' => now()]);
    }
});
afterEach(function () {
    foreach (['exam_application_subject', 'exam_application', 'notifications', 'defense_requests', 'advisers', 'users'] as $t) {
        Schema::dropIfExists($t);
    }
});
function integratedExamData(): array
{
    return ['schoolYear' => '2026-2027', 'program' => 'Master of Arts in Education', 'mobileNo' => '09123456789', 'subjects' => [['subject' => 'Research Methods', 'date' => '2026-11-20', 'startTime' => '09:00', 'endTime' => '11:00']]];
}
it('persists an exam application and its subject schedule using the migrated schema', function () {
    $this->actingAs(User::find(1))->post('/comprehensive-exam', integratedExamData())->assertRedirect('/comprehensive-exam');
    expect(DB::table('exam_application')->count())->toBe(1)->and(DB::table('exam_application')->value('student_id'))->toBe('COMPRE-1')->and(DB::table('exam_application_subject')->value('exam_date'))->toBe('2026-11-20');
    $this->get('/comprehensive-exam')->assertOk()->assertInertia(fn (Assert $p) => $p->component('student/submissions/comprehensive-exam/Index')->where('application.subjects.0.subject', 'Research Methods'));
});
it('does not duplicate an exam application or subjects in the same school year', function () {
    $this->actingAs(User::find(1));
    $this->post('/comprehensive-exam', integratedExamData());
    $this->post('/comprehensive-exam', integratedExamData())->assertRedirect();
    expect(DB::table('exam_application')->count())->toBe(1)->and(DB::table('exam_application_subject')->count())->toBe(1);
});
it('validates exam times before saving', function () {
    $data = integratedExamData();
    $data['subjects'][0]['endTime'] = '08:00';
    $this->actingAs(User::find(1))->postJson('/comprehensive-exam', $data)->assertUnprocessable();
    expect(DB::table('exam_application')->count())->toBe(0);
});
it('shows only the authenticated students application', function () {
    $this->actingAs(User::find(1))->post('/comprehensive-exam', integratedExamData());
    $this->actingAs(User::find(2))->get('/comprehensive-exam')->assertOk()->assertInertia(fn (Assert $p) => $p->where('application', null));
});
it('does not claim verified eligibility without a school portal session', function () {
    $this->actingAs(User::find(1))->getJson('/api/comprehensive-exam/eligibility')->assertOk()->assertJson(['eligible' => false, 'error' => 'Legacy session not available']);
    Http::assertNothingSent();
});
it('does not allow Finance to submit a student exam application', function () {
    $this->actingAs(User::find(3))->postJson('/comprehensive-exam', integratedExamData())->assertForbidden();
    expect(DB::table('exam_application')->count())->toBe(0);
});

it('enforces eligibility on the server even if a client bypasses the disabled button', function () {
    $this->mock(ComprehensiveEligibilityService::class)->shouldReceive('check')->andReturn(['eligible' => false]);
    $this->actingAs(User::find(1))->postJson('/comprehensive-exam', integratedExamData())->assertUnprocessable()->assertJsonValidationErrors('eligibility');
    expect(DB::table('exam_application')->count())->toBe(0);
});
