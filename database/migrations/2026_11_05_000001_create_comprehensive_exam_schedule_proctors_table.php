<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comprehensive_exam_schedule_proctors', function (Blueprint $table) {
            $table->foreignId('schedule_id')->constrained('comprehensive_exam_schedules')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['schedule_id', 'user_id']);
            $table->index(['user_id', 'schedule_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comprehensive_exam_schedule_proctors');
    }
};
