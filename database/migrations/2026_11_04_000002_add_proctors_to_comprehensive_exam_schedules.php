<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('comprehensive_exam_schedules', 'proctors')) {
            Schema::table('comprehensive_exam_schedules', function (Blueprint $table) {
                $table->json('proctors')->nullable()->after('proctor_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('comprehensive_exam_schedules', 'proctors')) {
            Schema::table('comprehensive_exam_schedules', function (Blueprint $table) {
                $table->dropColumn('proctors');
            });
        }
    }
};
