<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('message_conversation_deletions')) {
            Schema::create('message_conversation_deletions', function (Blueprint $table) {
                $table->id();

                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('conversation_id', 120);

                // When the user "deleted" the conversation from their inbox
                $table->timestamp('deleted_at')->nullable();

                $table->timestamps();

                $table->unique(['user_id', 'conversation_id'], 'mcd_user_conversation_unique');
                $table->index(['user_id', 'deleted_at'], 'mcd_user_deleted_at_idx');
                $table->index(['conversation_id'], 'mcd_conversation_id_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('message_conversation_deletions');
    }
};