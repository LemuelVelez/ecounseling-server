<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IntakeRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',

        // ✅ NEW: counselor assignment
        'counselor_id',

        // Core scheduling + status
        'concern_type',
        'urgency',

        // Student preference
        'preferred_date',
        'preferred_time',

        // Counselor final schedule ✅
        'scheduled_date',
        'scheduled_time',

        'details',
        'status',

        // Consent & demographic snapshot
        'consent',
        'student_name',
        'age',
        'gender',
        'occupation',
        'living_situation',
        'living_situation_other',

        // Mental health questionnaire fields
        'mh_little_interest',
        'mh_feeling_down',
        'mh_sleep',
        'mh_energy',
        'mh_appetite',
        'mh_self_esteem',
        'mh_concentration',
        'mh_motor',
        'mh_self_harm',
    ];

    /**
     * The student who submitted this intake request.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * ✅ NEW: assigned counselor
     */
    public function counselor()
    {
        return $this->belongsTo(User::class, 'counselor_id');
    }
}