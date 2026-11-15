<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComprehensiveWorkflow extends Model
{
    protected $guarded = [];

    protected $casts = ['history' => 'array', 'version' => 'integer', 'sms_opt_in' => 'boolean'];

    public function application()
    {
        return $this->belongsTo(ExamApplication::class, 'application_id', 'application_id');
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }

    public function sessions()
    {
        return $this->hasMany(ComprehensiveSession::class, 'workflow_id');
    }
}
