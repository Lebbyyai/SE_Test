<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentResponse extends Model
{
    protected $fillable = [
        'user_id',
        'family_name',
        'first_name',
        'program',
        'contact_number',
        'email_address',
        'id_number',
        'evaluated_at',
        'evaluated_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'evaluated_at' => 'datetime',
    ];
}
