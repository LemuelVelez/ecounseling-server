<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    use HasFactory;

    /**
     * Mass-assignable attributes.
     *
     * Matches the frontend MessageDto shape in:
     *   src/api/messages/route.ts
     */
    protected $fillable = [
        'user_id',
        'sender',
        'sender_name',
        'content',
        'is_read',
    ];

    protected $casts = [
        'is_read' => 'boolean',
    ];

    /**
     * The student who owns this message (the thread is per-student).
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}