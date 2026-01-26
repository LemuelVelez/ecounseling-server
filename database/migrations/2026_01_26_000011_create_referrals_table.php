<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('referrals')) {
            Schema::create('referrals', function (Blueprint $table) {
                $table->id();

                // Student being referred
                $table->foreignId('student_id')
                    ->constrained('users')
                    ->cascadeOnDelete();

                // "Requested By" referral user (Dean/Registrar/Program Chair)
                $table->foreignId('requested_by_id')
                    ->constrained('users')
                    ->cascadeOnDelete();

                // Optional counselor assignment
                $table->foreignId('counselor_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->string('concern_type', 255);
                $table->string('urgency', 20)->default('medium'); // low | medium | high
                $table->text('details');

                // pending | handled | closed
                $table->string('status', 30)->default('pending');

                $table->timestamp('handled_at')->nullable();
                $table->timestamp('closed_at')->nullable();

                // Optional counselor remarks
                $table->text('remarks')->nullable();

                $table->timestamps();

                $table->index(['student_id', 'status'], 'ref_student_status_idx');
                $table->index(['requested_by_id', 'status'], 'ref_requested_by_status_idx');
                $table->index(['counselor_id', 'status'], 'ref_counselor_status_idx');
                $table->index(['status', 'created_at'], 'ref_status_created_at_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};