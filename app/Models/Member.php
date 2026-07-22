<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Member extends Model
{
    protected $fillable = [
        'user_id',
        'branch_id',
        'first_name',
        'last_name',
        'phone',
        'email',
        'photo_path',
        'position',
        'pin',
        'date_joined',
        'status',
    ];

    public function user() { return $this->belongsTo(User::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function attendance() { return $this->hasMany(Attendance::class); }
}