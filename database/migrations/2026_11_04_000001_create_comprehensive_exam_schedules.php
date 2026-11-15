<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('comprehensive_exam_schedules')) {
            return;
        }

        Schema::create('comprehensive_exam_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('exam_id', 255);
            $table->string('program', 255);
            $table->date('exam_date');
            $table->time('exam_time');
            $table->string('venue', 255);
            $table->unsignedBigInteger('proctor_id')->nullable();
            $table->string('proctor_name', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comprehensive_exam_schedules');
    }
};
