<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentProfile extends Model
{

    protected $fillable =['user_id', 'first_name', 'father_name', 'last_name','avatar'];
    public function user(){
        return $this->belongsTo(User::class,'user_id');
    }
}
