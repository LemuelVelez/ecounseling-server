<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the avatar_url column to the users table.
     *
     * This migration is safe to run even if some columns already exist,
     * and it assumes the users table was created by an earlier migration.
     */
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'avatar_url')) {
                // Adjust the "after" column if needed based on your schema
                $table->string('avatar_url')->nullable()->after('course');
            }
        });
    }

    /**
     * Roll back the avatar_url addition.
     */
    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'avatar_url')) {
                $table->dropColumn('avatar_url');
            }
        });
    }
};