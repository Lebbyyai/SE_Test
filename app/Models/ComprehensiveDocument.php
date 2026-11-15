<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComprehensiveDocument extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'required' => 'boolean', 'history' => 'array', 'version' => 'integer',
        'uploaded_at' => 'datetime', 'reviewed_at' => 'datetime',
    ];
}
