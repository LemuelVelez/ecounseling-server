<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function isPgsql(): bool
    {
        return DB::getDriverName() === 'pgsql';
    }

    /**
     * Check if a foreign key exists on a given table+column (PostgreSQL).
     */
    private function pgForeignKeyExists(string $table, string $column): bool
    {
        $row = DB::selectOne(
            "
            SELECT 1
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
              ON tc.constraint_name = kcu.constraint_name
             AND tc.constraint_schema = kcu.constraint_schema
            WHERE tc.constraint_type = 'FOREIGN KEY'
              AND tc.table_name = ?
              AND kcu.column_name = ?
            LIMIT 1
            ",
            [$table, $column]
        );

        return $row !== null;
    }

    /**
     * Safely drop the accidental FK constraint named "1" if it exists on messages.
     * This only drops it when it is a FOREIGN KEY on the messages table.
     */
    private function dropBadConstraintOneIfPresent(): void
    {
        if (! $this->isPgsql()) return;

        $row = DB::selectOne(
            "
            SELECT conname
            FROM pg_constraint
            WHERE conrelid = 'messages'::regclass
              AND contype = 'f'
              AND conname = '1'
            LIMIT 1
            "
        );

        if ($row) {
            DB::statement('ALTER TABLE "messages" DROP CONSTRAINT IF EXISTS "1"');
        }
    }

    public function up(): void
    {
        // If you previously ran the broken version, it may have left FK constraint "1".
        $this->dropBadConstraintOneIfPresent();

        // 1) Add columns (NO constrained() here to avoid FK naming issues)
        Schema::table('messages', function (Blueprint $table) {
            if (! Schema::hasColumn('messages', 'sender_id')) {
                $table->foreignId('sender_id')->nullable();
            }

            if (! Schema::hasColumn('messages', 'recipient_id')) {
                $table->foreignId('recipient_id')->nullable();
            }

            if (! Schema::hasColumn('messages', 'recipient_role')) {
                $table->string('recipient_role', 50)->nullable();
            }

            if (! Schema::hasColumn('messages', 'conversation_id')) {
                $table->string('conversation_id', 120)->nullable();
            }

            if (! Schema::hasColumn('messages', 'counselor_is_read')) {
                $table->boolean('counselor_is_read')->default(false);
            }

            if (! Schema::hasColumn('messages', 'student_read_at')) {
                $table->timestamp('student_read_at')->nullable();
            }

            if (! Schema::hasColumn('messages', 'counselor_read_at')) {
                $table->timestamp('counselor_read_at')->nullable();
            }
        });

        // 2) Add foreign keys only if missing (Postgres-safe)
        if ($this->isPgsql()) {
            if (Schema::hasColumn('messages', 'sender_id') && ! $this->pgForeignKeyExists('messages', 'sender_id')) {
                Schema::table('messages', function (Blueprint $table) {
                    $table->foreign('sender_id', 'messages_sender_id_foreign')
                        ->references('id')
                        ->on('users')
                        ->nullOnDelete();
                });
            }

            if (Schema::hasColumn('messages', 'recipient_id') && ! $this->pgForeignKeyExists('messages', 'recipient_id')) {
                Schema::table('messages', function (Blueprint $table) {
                    $table->foreign('recipient_id', 'messages_recipient_id_foreign')
                        ->references('id')
                        ->on('users')
                        ->nullOnDelete();
                });
            }
        } else {
            // If you ever switch DBs, you can extend this; for now your environment is pgsql.
        }

        // 3) Create indexes (Postgres: IF NOT EXISTS avoids duplicate errors)
        if ($this->isPgsql()) {
            DB::statement('CREATE INDEX IF NOT EXISTS messages_sender_id_idx ON messages(sender_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS messages_recipient_id_idx ON messages(recipient_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS messages_recipient_role_idx ON messages(recipient_role)');
            DB::statement('CREATE INDEX IF NOT EXISTS messages_conversation_id_idx ON messages(conversation_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS messages_counselor_is_read_idx ON messages(counselor_is_read)');
        }

        // 4) Backfill (Postgres boolean-safe)
        if (Schema::hasColumn('messages', 'sender_id')) {
            DB::table('messages')
                ->whereNull('sender_id')
                ->whereIn('sender', ['student', 'guest'])
                ->update(['sender_id' => DB::raw('user_id')]);
        }

        if (Schema::hasColumn('messages', 'recipient_role')) {
            DB::table('messages')
                ->whereNull('recipient_role')
                ->whereIn('sender', ['student', 'guest'])
                ->update(['recipient_role' => 'counselor']);

            DB::table('messages')
                ->whereNull('recipient_role')
                ->whereIn('sender', ['counselor', 'system'])
                ->update(['recipient_role' => 'student']);
        }

        if (Schema::hasColumn('messages', 'conversation_id')) {
            DB::table('messages')
                ->whereNull('conversation_id')
                ->whereNotNull('user_id')
                ->update(['conversation_id' => DB::raw("CONCAT('student-', user_id)")]);
        }

        if (Schema::hasColumn('messages', 'student_read_at')) {
            DB::table('messages')
                ->where('is_read', true)
                ->whereNull('student_read_at')
                ->update(['student_read_at' => DB::raw('created_at')]);
        }

        if (Schema::hasColumn('messages', 'counselor_is_read')) {
            DB::table('messages')
                ->whereIn('sender', ['counselor', 'system'])
                ->where('counselor_is_read', false)
                ->update(['counselor_is_read' => true]);
        }

        if (Schema::hasColumn('messages', 'counselor_read_at')) {
            DB::table('messages')
                ->where('counselor_is_read', true)
                ->whereNull('counselor_read_at')
                ->update(['counselor_read_at' => DB::raw('created_at')]);
        }
    }

    public function down(): void
    {
        // Drop the bad constraint if it exists (from old broken migrations)
        $this->dropBadConstraintOneIfPresent();

        if ($this->isPgsql()) {
            DB::statement('DROP INDEX IF EXISTS messages_sender_id_idx');
            DB::statement('DROP INDEX IF EXISTS messages_recipient_id_idx');
            DB::statement('DROP INDEX IF EXISTS messages_recipient_role_idx');
            DB::statement('DROP INDEX IF EXISTS messages_conversation_id_idx');
            DB::statement('DROP INDEX IF EXISTS messages_counselor_is_read_idx');
        }

        Schema::table('messages', function (Blueprint $table) {
            // Drop FKs if they exist
            if (Schema::hasColumn('messages', 'sender_id')) {
                try { $table->dropForeign('messages_sender_id_foreign'); } catch (\Throwable $e) {}
            }

            if (Schema::hasColumn('messages', 'recipient_id')) {
                try { $table->dropForeign('messages_recipient_id_foreign'); } catch (\Throwable $e) {}
            }

            // Drop columns
            $cols = [
                'sender_id',
                'recipient_id',
                'recipient_role',
                'conversation_id',
                'counselor_is_read',
                'student_read_at',
                'counselor_read_at',
            ];

            foreach ($cols as $c) {
                if (Schema::hasColumn('messages', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};