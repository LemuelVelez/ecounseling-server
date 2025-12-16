<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Upgrade messages table to support:
     * - student/guest -> counselor
     * - counselor -> student/guest
     * - counselor -> counselor
     *
     * We keep the existing "is_read" as the STUDENT/GUEST read flag (so the current student UI stays compatible),
     * and introduce "counselor_is_read" for the counselor side.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Who authored the message (user record, if applicable)
            if (!Schema::hasColumn('messages', 'sender_id')) {
                $table->foreignId('sender_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            // Who receives the message (user record, if applicable)
            if (!Schema::hasColumn('messages', 'recipient_id')) {
                $table->foreignId('recipient_id')
                    ->nullable()
                    ->after('sender_id')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            // Recipient role group ("counselor", "student", "guest")
            if (!Schema::hasColumn('messages', 'recipient_role')) {
                $table->string('recipient_role', 50)
                    ->nullable()
                    ->after('recipient_id');
            }

            // Thread identifier (for grouping)
            if (!Schema::hasColumn('messages', 'conversation_id')) {
                $table->string('conversation_id', 120)
                    ->nullable()
                    ->after('recipient_role');

                $table->index('conversation_id');
            }

            // Counselor read flag (separate from student read flag)
            if (!Schema::hasColumn('messages', 'counselor_is_read')) {
                $table->boolean('counselor_is_read')
                    ->default(false)
                    ->after('is_read');

                $table->index('counselor_is_read');
            }

            // Optional read timestamps
            if (!Schema::hasColumn('messages', 'student_read_at')) {
                $table->timestamp('student_read_at')
                    ->nullable()
                    ->after('is_read');
            }

            if (!Schema::hasColumn('messages', 'counselor_read_at')) {
                $table->timestamp('counselor_read_at')
                    ->nullable()
                    ->after('counselor_is_read');
            }

            // Helpful indexes for inbox queries
            if (!Schema::hasColumn('messages', 'recipient_role')) {
                // (already created above if missing)
            } else {
                $table->index('recipient_role');
            }

            if (!Schema::hasColumn('messages', 'recipient_id')) {
                // (already created above if missing)
            } else {
                $table->index('recipient_id');
            }
        });

        /**
         * Backfill existing records so counselor inbox can see old student messages.
         * Old table assumption:
         * - user_id points to the student owner
         * - sender is "student" / "counselor" / "system"
         * - is_read is student read status
         */

        // If sender is student/guest and sender_id is missing, set sender_id = user_id
        DB::statement("UPDATE messages SET sender_id = user_id WHERE sender_id IS NULL AND sender IN ('student','guest')");

        // Default recipient_role based on sender
        // student/guest messages go to counselor office
        DB::statement("UPDATE messages SET recipient_role = 'counselor' WHERE recipient_role IS NULL AND sender IN ('student','guest')");

        // counselor/system messages go to the student owner
        DB::statement("UPDATE messages SET recipient_role = 'student' WHERE recipient_role IS NULL AND sender IN ('counselor','system')");

        // Create a conversation_id for all old messages based on user_id (student thread)
        DB::statement("UPDATE messages SET conversation_id = CONCAT('student-', user_id) WHERE conversation_id IS NULL AND user_id IS NOT NULL");

        // If is_read is true, set student_read_at if missing (best-effort)
        DB::statement("UPDATE messages SET student_read_at = created_at WHERE is_read = 1 AND student_read_at IS NULL");

        // For counselor/system messages, counselor_is_read can be true since counselor authored them (optional)
        DB::statement("UPDATE messages SET counselor_is_read = 1 WHERE sender IN ('counselor','system') AND counselor_is_read = 0");
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            if (Schema::hasColumn('messages', 'sender_id')) {
                $table->dropConstrainedForeignId('sender_id');
            }

            if (Schema::hasColumn('messages', 'recipient_id')) {
                $table->dropConstrainedForeignId('recipient_id');
            }

            if (Schema::hasColumn('messages', 'recipient_role')) {
                $table->dropColumn('recipient_role');
            }

            if (Schema::hasColumn('messages', 'conversation_id')) {
                $table->dropIndex(['conversation_id']);
                $table->dropColumn('conversation_id');
            }

            if (Schema::hasColumn('messages', 'counselor_is_read')) {
                $table->dropIndex(['counselor_is_read']);
                $table->dropColumn('counselor_is_read');
            }

            if (Schema::hasColumn('messages', 'student_read_at')) {
                $table->dropColumn('student_read_at');
            }

            if (Schema::hasColumn('messages', 'counselor_read_at')) {
                $table->dropColumn('counselor_read_at');
            }

            // recipient_role / recipient_id indexes
            // (Laravel will auto-drop indexes when dropping columns; kept safe here)
        });
    }
};