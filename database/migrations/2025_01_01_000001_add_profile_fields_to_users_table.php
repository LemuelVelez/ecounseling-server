<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add extra profile / account fields used by the React auth flow.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'role')) {
                $table->string('role')->nullable()->default('student')->after('password');
            }

            if (! Schema::hasColumn('users', 'gender')) {
                $table->string('gender', 50)->nullable()->after('role');
            }

            if (! Schema::hasColumn('users', 'account_type')) {
                $table->enum('account_type', ['student', 'guest'])->default('student')->after('gender');
            }

            if (! Schema::hasColumn('users', 'student_id')) {
                $table->string('student_id')->nullable()->after('account_type');
            }

            if (! Schema::hasColumn('users', 'year_level')) {
                $table->string('year_level')->nullable()->after('student_id');
            }

            if (! Schema::hasColumn('users', 'program')) {
                $table->string('program')->nullable()->after('year_level');
            }

            if (! Schema::hasColumn('users', 'course')) {
                $table->string('course')->nullable()->after('program');
            }
        });
    }

    /**
     * Roll back the profile fields.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'course')) {
                $table->dropColumn('course');
            }
            if (Schema::hasColumn('users', 'program')) {
                $table->dropColumn('program');
            }
            if (Schema::hasColumn('users', 'year_level')) {
                $table->dropColumn('year_level');
            }
            if (Schema::hasColumn('users', 'student_id')) {
                $table->dropColumn('student_id');
            }
            if (Schema::hasColumn('users', 'account_type')) {
                $table->dropColumn('account_type');
            }
            if (Schema::hasColumn('users', 'gender')) {
                $table->dropColumn('gender');
            }
            if (Schema::hasColumn('users', 'role')) {
                $table->dropColumn('role');
            }
        });
    }
};