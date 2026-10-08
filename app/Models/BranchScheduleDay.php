<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;

class BranchScheduleDay extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'branch_id', 'day', 'is_working', 'start_time', 'grace_minutes', 'expected_end_time'];

    protected function casts(): array
    {
        return ['is_working' => 'boolean'];
    }

    public function branch() { return $this->belongsTo(Branch::class); }
}