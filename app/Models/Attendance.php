<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    protected $table = 'attendance';

    protected $fillable = [
        'member_id',
        'shift_id',
        'device_id',
        'clock_in',
        'clock_out',
        'gps_lat_in',
        'gps_lng_in',
        'gps_lat_out',
        'gps_lng_out',
        'mock_location_flag',
        'clock_in_method',
        'clock_out_method',
        'working_hours',
        'status',
        'remarks',
    ];

    public function member() { return $this->belongsTo(Member::class); }
}