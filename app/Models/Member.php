<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;

class Member extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'user_id',
        'company_id',
        'branch_id',
        'shift_id',
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
    public function shift() { return $this->belongsTo(Shift::class); }
    public function attendance() { return $this->hasMany(Attendance::class); }
}