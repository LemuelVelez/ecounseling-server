<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the email_verification_tokens table.
     */
    public function up(): void
    {
        if (! Schema::hasTable('email_verification_tokens')) {
            Schema::create('email_verification_tokens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('token', 64)->unique();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamp('used_at')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    /**
     * Drop the email_verification_tokens table.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_verification_tokens');
    }
};