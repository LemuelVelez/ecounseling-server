<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the sessions table used by SESSION_DRIVER=database.
     */
    public function up(): void
    {
        if (! Schema::hasTable('sessions')) {
            Schema::create('sessions', function (Blueprint $table) {
                // Session ID (the value stored in the cookie)
                $table->string('id')->primary();

                // Related user (if logged in)
                $table->foreignId('user_id')->nullable()->index();

                // Extra metadata for debugging/auditing
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();

                // Serialized session data
                $table->longText('payload');

                // Unix timestamp of last activity
                $table->integer('last_activity')->index();
            });
        }
    }

    /**
     * Drop the sessions table.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};