<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use HasFactory;

    /**
     * Mass-assignable attributes.
     *
     * Note:
     * - `is_read` is treated as "read by student/guest"
     * - `counselor_is_read` is treated as "read by counselor"
     */
    protected $fillable = [
        'user_id',

        'sender',
        'sender_id',
        'sender_name',

        'recipient_id',
        'recipient_role',

        'conversation_id',

        'content',

        'is_read',
        'student_read_at',

        'counselor_is_read',
        'counselor_read_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'counselor_is_read' => 'boolean',
        'student_read_at' => 'datetime',
        'counselor_read_at' => 'datetime',
    ];

    /**
     * The student/guest who owns the message thread (legacy usage).
     * For counselor-counselor messages, we may still populate user_id for compatibility.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function senderUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipientUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }
}