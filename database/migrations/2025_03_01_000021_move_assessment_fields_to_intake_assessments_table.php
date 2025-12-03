<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Move assessment-related fields from intake_requests into the
     * new intake_assessments table, then drop those columns from
     * intake_requests.
     */
    public function up(): void
    {
        if (! Schema::hasTable('intake_requests') || ! Schema::hasTable('intake_assessments')) {
            return;
        }

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

        // Only attempt to migrate data if at least the consent column exists,
        // which implies the other assessment fields were added previously.
        if (Schema::hasColumn('intake_requests', 'consent')) {
            DB::table('intake_requests')
                ->orderBy('id')
                ->chunkById(100, function ($rows) {
                    foreach ($rows as $row) {
                        DB::table('intake_assessments')->insert([
                            'user_id'                => $row->user_id,
                            'consent'                => (bool) ($row->consent ?? false),
                            'student_name'           => $row->student_name ?? null,
                            'age'                    => $row->age ?? null,
                            'gender'                 => $row->gender ?? null,
                            'occupation'             => $row->occupation ?? null,
                            'living_situation'       => $row->living_situation ?? null,
                            'living_situation_other' => $row->living_situation_other ?? null,
                            'mh_little_interest'     => $row->mh_little_interest ?? null,
                            'mh_feeling_down'        => $row->mh_feeling_down ?? null,
                            'mh_sleep'               => $row->mh_sleep ?? null,
                            'mh_energy'              => $row->mh_energy ?? null,
                            'mh_appetite'            => $row->mh_appetite ?? null,
                            'mh_self_esteem'         => $row->mh_self_esteem ?? null,
                            'mh_concentration'       => $row->mh_concentration ?? null,
                            'mh_motor'               => $row->mh_motor ?? null,
                            'mh_self_harm'           => $row->mh_self_harm ?? null,
                            'created_at'             => $row->created_at,
                            'updated_at'             => $row->updated_at,
                        ]);
                    }
                });
        }

        // Drop assessment-related columns from intake_requests.
        Schema::table('intake_requests', function (Blueprint $table) use ($columns) {
            foreach ($columns as $column) {
                if (Schema::hasColumn('intake_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    /**
     * Re-add the assessment fields back onto intake_requests.
     *
     * Note: this does not move data back from intake_assessments.
     */
    public function down(): void
    {
        if (! Schema::hasTable('intake_requests')) {
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

        // We intentionally do NOT attempt to move data back from intake_assessments
        // into intake_requests here, as there may be multiple assessments per user.
    }
};