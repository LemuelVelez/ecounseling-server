<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * These fields are also the ones we accept from the React frontend.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'gender',
        'account_type',
        'student_id',
        'year_level',
        'program',
        'course',
        'avatar_url',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        // Laravel 11+ / 12 will hash password automatically when assigned.
        'password'          => 'hashed',
    ];
}