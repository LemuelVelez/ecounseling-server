<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Referral extends Model
{
    protected $fillable = [
        'student_id',
        'requested_by_id',
        'counselor_id',
        'concern_type',
        'urgency',
        'details',
        'status',
        'handled_at',
        'closed_at',
        'remarks',
    ];

    protected $casts = [
        'handled_at' => 'datetime',
        'closed_at'  => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function counselor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counselor_id');
    }
}