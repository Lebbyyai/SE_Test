<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_submissions', function (Blueprint $t) {
            $t->unsignedBigInteger('exam_application_id')->nullable()->index();
            $t->index(['student_id', 'payment_type'], 'exam_receipt_student_type_index');
        });
        Schema::table('payment_submissions', fn (Blueprint $t) => $t->dropUnique(['student_id', 'payment_type']));
    }

    public function down(): void
    {
        Schema::table('payment_submissions', function (Blueprint $t) {
            $t->dropColumn('exam_application_id');
            $t->dropIndex('exam_receipt_student_type_index');
        });
    }
};
