<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add extra profile / account fields used by the React auth flow.
     *
     * This migration is also defensive:
     * - If the "users" table does NOT exist, it will create it
     *   with all required columns.
     * - If it DOES exist, it will just add the extra profile fields.
     */
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            // Create a full users table if it doesn't exist yet.
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');

                // Our profile fields
                $table->string('role')->nullable()->default('student');
                $table->string('gender', 50)->nullable();
                $table->enum('account_type', ['student', 'guest'])->default('student');
                $table->string('student_id')->nullable();
                $table->string('year_level')->nullable();
                $table->string('program')->nullable();
                $table->string('course')->nullable();

                $table->rememberToken();
                $table->timestamps();
            });

            return;
        }

        // If the users table already exists, just add the missing columns.
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'role')) {
                $table->string('role')->nullable()->default('student')->after('password');
            }

            if (! Schema::hasColumn('users', 'gender')) {
                $table->string('gender', 50)->nullable()->after('role');
            }

            if (! Schema::hasColumn('users', 'account_type')) {
                $table
                    ->enum('account_type', ['student', 'guest'])
                    ->default('student')
                    ->after('gender');
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
     *
     * We *only* drop columns if the table/columns exist, so this is safe
     * even if the table was created elsewhere.
     */
    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

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