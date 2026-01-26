<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualAssessmentScore extends Model
{
    protected $table = 'manual_assessment_scores';

    protected $fillable = [
        'student_id',
        'counselor_id',
        'score',
        'rating',
        'assessed_date',
        'remarks',
    ];

    protected $casts = [
        'score' => 'decimal:2',
        'assessed_date' => 'date',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function counselor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counselor_id');
    }
}