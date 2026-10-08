<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;

class AttendanceAttempt extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'member_id', 'type', 'result', 'reason', 'gps_lat', 'gps_lng', 'distance_m',
    ];

    public function member() { return $this->belongsTo(Member::class); }
}