<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('comprehensive_exam_schedules', 'teacher_assigned')) {
            Schema::table('comprehensive_exam_schedules', function (Blueprint $table) {
                $table->string('teacher_assigned')->nullable()->after('proctors');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('comprehensive_exam_schedules', 'teacher_assigned')) {
            Schema::table('comprehensive_exam_schedules', function (Blueprint $table) {
                $table->dropColumn('teacher_assigned');
            });
        }
    }
};
