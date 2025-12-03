<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the intake_assessments table for Steps 1–3 of the intake.
     */
    public function up(): void
    {
        if (! Schema::hasTable('intake_assessments')) {
            Schema::create('intake_assessments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();

                // Consent flag
                $table->boolean('consent')->default(false);

                // Demographic snapshot
                $table->string('student_name')->nullable();
                $table->unsignedTinyInteger('age')->nullable();
                $table->string('gender', 50)->nullable();
                $table->string('occupation')->nullable();
                $table->string('living_situation', 50)->nullable();
                $table->string('living_situation_other')->nullable();

                // Mental health questionnaire fields (enum-like strings)
                $mhColumns = [
                    'mh_little_interest',
                    'mh_feeling_down',
                    'mh_sleep',
                    'mh_energy',
                    'mh_appetite',
                    'mh_self_esteem',
                    'mh_concentration',
                    'mh_motor',
                    'mh_self_harm',
                ];

                foreach ($mhColumns as $column) {
                    $table->string($column, 30)->nullable();
                }

                $table->timestamps();

                $table->index('user_id');
            });
        }
    }

    /**
     * Drop the intake_assessments table.
     */
    public function down(): void
    {
        Schema::dropIfExists('intake_assessments');
    }
};