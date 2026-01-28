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

                $table->unsignedBigInteger('student_id');
                $table->unsignedBigInteger('requested_by_id')->nullable();
                $table->unsignedBigInteger('counselor_id')->nullable();

                $table->string('concern_type');
                $table->enum('urgency', ['low', 'medium', 'high'])->default('medium');
                $table->text('details');

                $table->string('status')->default('pending');

                $table->timestamp('handled_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->text('remarks')->nullable();

                $table->timestamps();

                $table->foreign('student_id')->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('requested_by_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('counselor_id')->references('id')->on('users')->nullOnDelete();

                $table->index(['status', 'created_at']);
                $table->index(['requested_by_id', 'created_at']);
                $table->index(['student_id', 'created_at']);
            });

            return;
        }

        // Table exists: patch missing columns safely.
        Schema::table('referrals', function (Blueprint $table) {
            if (!Schema::hasColumn('referrals', 'student_id')) {
                $table->unsignedBigInteger('student_id')->after('id');
            }
            if (!Schema::hasColumn('referrals', 'requested_by_id')) {
                $table->unsignedBigInteger('requested_by_id')->nullable()->after('student_id');
            }
            if (!Schema::hasColumn('referrals', 'counselor_id')) {
                $table->unsignedBigInteger('counselor_id')->nullable()->after('requested_by_id');
            }
            if (!Schema::hasColumn('referrals', 'concern_type')) {
                $table->string('concern_type')->after('counselor_id');
            }
            if (!Schema::hasColumn('referrals', 'urgency')) {
                $table->enum('urgency', ['low', 'medium', 'high'])->default('medium')->after('concern_type');
            }
            if (!Schema::hasColumn('referrals', 'details')) {
                $table->text('details')->after('urgency');
            }
            if (!Schema::hasColumn('referrals', 'status')) {
                $table->string('status')->default('pending')->after('details');
            }
            if (!Schema::hasColumn('referrals', 'handled_at')) {
                $table->timestamp('handled_at')->nullable()->after('status');
            }
            if (!Schema::hasColumn('referrals', 'closed_at')) {
                $table->timestamp('closed_at')->nullable()->after('handled_at');
            }
            if (!Schema::hasColumn('referrals', 'remarks')) {
                $table->text('remarks')->nullable()->after('closed_at');
            }
        });
    }

    public function down(): void
    {
        // Keep safe: don't drop automatically in patch migrations.
        // Uncomment if you truly want rollback to drop:
        // Schema::dropIfExists('referrals');
    }
};