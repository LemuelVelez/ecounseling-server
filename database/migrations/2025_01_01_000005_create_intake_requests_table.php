<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the intake_requests table for counseling requests.
     */
    public function up(): void
    {
        if (! Schema::hasTable('intake_requests')) {
            Schema::create('intake_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();

                $table->string('concern_type')->nullable();
                $table->string('urgency', 20)->nullable(); // low, medium, high
                $table->date('preferred_date')->nullable();
                $table->string('preferred_time', 50)->nullable();

                $table->text('details');
                $table->string('status', 50)->default('pending'); // pending, in_review, scheduled, closed

                $table->timestamps();

                $table->index(['user_id', 'status']);
            });
        }
    }

    /**
     * Drop the intake_requests table.
     */
    public function down(): void
    {
        Schema::dropIfExists('intake_requests');
    }
};