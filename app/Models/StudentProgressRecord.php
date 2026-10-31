<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentProgressRecord extends Model
{
    protected $guarded = [];

    protected $casts = ['subjects' => 'array', 'history' => 'array', 'recorded_at' => 'datetime', 'enrollment_year' => 'integer', 'completion_year' => 'integer', 'version' => 'integer'];

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
