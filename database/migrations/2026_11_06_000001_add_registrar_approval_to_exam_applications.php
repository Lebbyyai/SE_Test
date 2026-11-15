<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_application', function (Blueprint $table) {
            $table->string('registrar_approval_status')->default('pending');
            $table->timestamp('registrar_approved_at')->nullable();
            $table->foreignId('registrar_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['final_approval_status', 'registrar_approval_status'], 'exam_app_registrar_approval_idx');
        });
    }

    public function down(): void
    {
        Schema::table('exam_application', function (Blueprint $table) {
            $table->dropIndex('exam_app_registrar_approval_idx');
            $table->dropConstrainedForeignId('registrar_approved_by');
            $table->dropColumn(['registrar_approval_status', 'registrar_approved_at']);
        });
    }
};
