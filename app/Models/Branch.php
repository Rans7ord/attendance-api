<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    protected $fillable = [
        'name',
        'address',
        'gps_lat',
        'gps_lng',
        'geofence_radius_m',
    ];

    public function members() { return $this->hasMany(Member::class); }
}
