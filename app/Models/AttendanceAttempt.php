<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class AttendanceAttempt extends Model
{
    protected $fillable = [
        'member_id', 'type', 'result', 'gps_lat', 'gps_lng', 'distance_m',
    ];

    public function member() { return $this->belongsTo(Member::class); }
}
