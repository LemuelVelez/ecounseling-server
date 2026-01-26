<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('intake_requests') && ! Schema::hasColumn('intake_requests', 'counselor_id')) {
            Schema::table('intake_requests', function (Blueprint $table) {
                $table->foreignId('counselor_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('users')
                    ->nullOnDelete();

                $table->index(['counselor_id', 'status'], 'ir_counselor_status_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('intake_requests') && Schema::hasColumn('intake_requests', 'counselor_id')) {
            Schema::table('intake_requests', function (Blueprint $table) {
                $table->dropIndex('ir_counselor_status_idx');
                $table->dropConstrainedForeignId('counselor_id');
            });
        }
    }
};