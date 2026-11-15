<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comprehensive_workflows', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('application_id')->unique();
            $t->foreign('application_id')->references('application_id')->on('exam_application')->cascadeOnDelete();
            $t->foreignId('student_user_id')->constrained('users');
            $t->string('semester', 20)->nullable();
            $t->string('aa_status')->default('pending');
            $t->string('registrar_status')->default('pending');
            $t->text('aa_note')->nullable();
            $t->text('registrar_note')->nullable();
            $t->text('record_reference')->nullable();
            $t->boolean('sms_opt_in')->default(false);
            $t->json('history')->nullable();
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
        });
        Schema::create('comprehensive_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workflow_id')->constrained('comprehensive_workflows')->cascadeOnDelete();
            $t->unsignedBigInteger('subject_id');
            $t->foreign('subject_id')->references('id')->on('exam_application_subject')->cascadeOnDelete();
            $t->string('room', 150);
            $t->date('exam_date');
            $t->time('start_time');
            $t->time('end_time');
            $t->json('faculty');
            $t->timestamps();
            $t->unique(['workflow_id', 'subject_id']);
        });
        Schema::create('comprehensive_workflow_locks', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
        });
        DB::table('comprehensive_workflow_locks')->insert(['id' => 1]);
        Schema::create('comprehensive_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users');
            $t->foreignId('workflow_id')->constrained('comprehensive_workflows');
            $t->string('channel');
            $t->string('status');
            $t->text('message');
            $t->string('detail')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comprehensive_deliveries');
        Schema::dropIfExists('comprehensive_sessions');
        Schema::dropIfExists('comprehensive_workflow_locks');
        Schema::dropIfExists('comprehensive_workflows');
    }
};
