<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add assessment-related fields to the existing intake_requests table.
     */
    public function up(): void
    {
        if (! Schema::hasTable('intake_requests')) {
            // If the table doesn't exist yet, do nothing here.
            // The original create_intake_requests_table migration will handle creation.
            return;
        }

        Schema::table('intake_requests', function (Blueprint $table) {
            // Consent flag
            if (! Schema::hasColumn('intake_requests', 'consent')) {
                $table->boolean('consent')->default(false)->after('preferred_time');
            }

            // Demographic snapshot
            if (! Schema::hasColumn('intake_requests', 'student_name')) {
                $table->string('student_name')->nullable()->after('consent');
            }

            if (! Schema::hasColumn('intake_requests', 'age')) {
                $table->unsignedTinyInteger('age')->nullable()->after('student_name');
            }

            if (! Schema::hasColumn('intake_requests', 'gender')) {
                $table->string('gender', 50)->nullable()->after('age');
            }

            if (! Schema::hasColumn('intake_requests', 'occupation')) {
                $table->string('occupation')->nullable()->after('gender');
            }

            if (! Schema::hasColumn('intake_requests', 'living_situation')) {
                $table->string('living_situation', 50)->nullable()->after('occupation');
            }

            if (! Schema::hasColumn('intake_requests', 'living_situation_other')) {
                $table
                    ->string('living_situation_other')
                    ->nullable()
                    ->after('living_situation');
            }

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
                if (! Schema::hasColumn('intake_requests', $column)) {
                    $table->string($column, 30)->nullable()->after('living_situation_other');
                }
            }
        });
    }

    /**
     * Remove the assessment-related fields (safe to run even if some columns are missing).
     */
    public function down(): void
    {
        if (! Schema::hasTable('intake_requests')) {
            return;
        }

        Schema::table('intake_requests', function (Blueprint $table) {
            $columns = [
                'consent',
                'student_name',
                'age',
                'gender',
                'occupation',
                'living_situation',
                'living_situation_other',
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

            foreach ($columns as $column) {
                if (Schema::hasColumn('intake_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};