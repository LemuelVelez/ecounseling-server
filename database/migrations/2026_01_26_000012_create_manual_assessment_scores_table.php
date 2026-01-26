<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('manual_assessment_scores')) {
            Schema::create('manual_assessment_scores', function (Blueprint $table) {
                $table->id();

                $table->foreignId('student_id')
                    ->constrained('users')
                    ->cascadeOnDelete();

                $table->foreignId('counselor_id')
                    ->constrained('users')
                    ->cascadeOnDelete();

                // numeric score from hardcopy exam
                $table->decimal('score', 6, 2);

                // Poor | Fair | Good | Very Good
                $table->string('rating', 30);

                // date of assessment
                $table->date('assessed_date');

                $table->text('remarks')->nullable();

                $table->timestamps();

                $table->index(['student_id', 'assessed_date'], 'mas_student_date_idx');
                $table->index(['counselor_id', 'assessed_date'], 'mas_counselor_date_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_assessment_scores');
    }
};