<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_responses', function (Blueprint $table) {
            $table->timestamp('evaluated_at')->nullable()->after('id_number');
            $table->foreignId('evaluated_by')->nullable()->after('evaluated_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('student_responses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('evaluated_by');
            $table->dropColumn('evaluated_at');
        });
    }
};
