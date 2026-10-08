<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;

class Branch extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'name',
        'address',
        'gps_lat',
        'gps_lng',
        'geofence_radius_m',
        'use_shifts',
    ];

    protected function casts(): array
    {
        return ['use_shifts' => 'boolean'];
    }

    public function members() { return $this->hasMany(Member::class); }
    public function scheduleDays() { return $this->hasMany(BranchScheduleDay::class); }
    public function shifts() { return $this->hasMany(Shift::class); }
}