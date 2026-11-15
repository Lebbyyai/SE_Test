<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comprehensive_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 30);
            $table->boolean('required')->default(false);
            $table->text('instructions')->nullable();
            $table->string('path')->nullable();
            $table->string('original_name')->nullable();
            $table->string('status', 30)->default('missing');
            $table->text('remarks')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->json('history')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comprehensive_documents');
    }
};
