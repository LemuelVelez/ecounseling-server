<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IntakeRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'concern_type',
        'urgency',
        'preferred_date',
        'preferred_time',
        'details',
        'status',
    ];

    /**
     * The student who submitted this intake request.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}