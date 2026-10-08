<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;

class Shift extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'branch_id', 'name', 'start_time', 'end_time', 'grace_minutes'];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function members() { return $this->hasMany(Member::class); }
}