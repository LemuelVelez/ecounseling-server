<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the password_reset_tokens table.
     *
     * This matches what our AuthController expects:
     *   DB::table('password_reset_tokens')
     */
    public function up(): void
    {
        // Only create if it doesn't already exist (defensive)
        if (Schema::hasTable('password_reset_tokens')) {
            return;
        }

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            // Using email as the primary key is consistent with Laravel's default
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Drop the password_reset_tokens table.
     */
    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};