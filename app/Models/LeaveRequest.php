<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;

class LeaveRequest extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'member_id', 'type', 'start_date', 'end_date', 'reason',
        'status', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'reviewed_at' => 'datetime',
        ];
    }

    public function member() { return $this->belongsTo(Member::class); }
    public function reviewer() { return $this->belongsTo(User::class, 'reviewed_by'); }
}