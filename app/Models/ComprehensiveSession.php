<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComprehensiveSession extends Model
{
    protected $guarded = [];

    protected $casts = ['faculty' => 'array'];

    public function workflow()
    {
        return $this->belongsTo(ComprehensiveWorkflow::class, 'workflow_id');
    }
}
