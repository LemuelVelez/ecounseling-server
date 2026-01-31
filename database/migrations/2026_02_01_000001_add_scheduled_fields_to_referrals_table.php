<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('referrals')) {
            return;
        }

        $hasDate = Schema::hasColumn('referrals', 'scheduled_date');
        $hasTime = Schema::hasColumn('referrals', 'scheduled_time');

        if ($hasDate && $hasTime) {
            return;
        }

        Schema::table('referrals', function (Blueprint $table) use ($hasDate, $hasTime) {
            // Put after closed_at if it exists; otherwise Laravel will place at end.
            if (!$hasDate) {
                $table->date('scheduled_date')->nullable();
            }

            if (!$hasTime) {
                $table->string('scheduled_time', 50)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('referrals')) {
            return;
        }

        Schema::table('referrals', function (Blueprint $table) {
            if (Schema::hasColumn('referrals', 'scheduled_date')) {
                $table->dropColumn('scheduled_date');
            }
            if (Schema::hasColumn('referrals', 'scheduled_time')) {
                $table->dropColumn('scheduled_time');
            }
        });
    }
};