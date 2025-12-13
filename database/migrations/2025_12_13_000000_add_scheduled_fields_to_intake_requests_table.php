<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intake_requests', function (Blueprint $table) {
            $table->date('scheduled_date')->nullable()->after('preferred_date');
            $table->string('scheduled_time', 50)->nullable()->after('preferred_time');
        });
    }

    public function down(): void
    {
        Schema::table('intake_requests', function (Blueprint $table) {
            $table->dropColumn(['scheduled_date', 'scheduled_time']);
        });
    }
};