<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the messages table for student ↔ counselor/system messages.
     *
     * This table backs the frontend API:
     *   src/api/messages/route.ts
     *
     * Fields map to MessageDto:
     *   - id
     *   - user_id
     *   - sender
     *   - sender_name
     *   - content
     *   - is_read
     *   - created_at
     *   - updated_at
     */
    public function up(): void
    {
        if (! Schema::hasTable('messages')) {
            Schema::create('messages', function (Blueprint $table) {
                $table->id();

                // The student who owns this message thread
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();

                // Who authored this specific message
                // e.g. "student", "counselor", "system"
                $table->string('sender', 50);

                // Display name for the sender at the time of sending
                $table->string('sender_name')->nullable();

                // Message body
                $table->text('content');

                // Whether this message has been marked as read from the student's perspective
                $table->boolean('is_read')->default(false);

                $table->timestamps();

                $table->index(['user_id', 'created_at']);
                $table->index(['user_id', 'is_read']);
            });
        }
    }

    /**
     * Drop the messages table.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};