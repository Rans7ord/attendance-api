<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A day (or run of days) when some people are not expected to work:
 * a public holiday, a short break, or a one-off closure. Entirely optional —
 * a company with no rows here behaves exactly as before.
 *
 * scope: company  -> everyone
 *        branch   -> everyone in branch_id
 *        members  -> only the members listed in day_off_member
 */
class DayOff extends Model
{
    use BelongsToCompany;

    protected $table = 'day_offs';

    protected $fillable = [
        'company_id', 'title', 'kind', 'scope', 'branch_id', 'start_date', 'end_date', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
        ];
    }

    public function branch() { return $this->belongsTo(Branch::class); }
    public function members() { return $this->belongsToMany(Member::class, 'day_off_member'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }

    /** Does this day off apply to the member? ($this->members must be loaded for scope "members".) */
    public function appliesTo(Member $member): bool
    {
        return match ($this->scope) {
            'company' => true,
            'branch' => $member->branch_id !== null && (int) $this->branch_id === (int) $member->branch_id,
            'members' => $this->members->contains('id', $member->id),
            default => false,
        };
    }

    /** All day offs touching the date range, with their member lists loaded. */
    public static function loadFor(Carbon $from, Carbon $to): Collection
    {
        return static::with('members:id')
            ->where('start_date', '<=', $to->toDateString())
            ->where('end_date', '>=', $from->toDateString())
            ->get();
    }

    /** date (Y-m-d) => title of the day off covering that date for this member. */
    public static function expand(Collection $dayOffs, Member $member, Carbon $from, Carbon $to): array
    {
        $dates = [];

        foreach ($dayOffs as $off) {
            if (!$off->appliesTo($member)) {
                continue;
            }

            $start = Carbon::parse(max($off->start_date->toDateString(), $from->toDateString()));
            $end = Carbon::parse(min($off->end_date->toDateString(), $to->toDateString()));

            for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                $dates[$d->toDateString()] ??= $off->title;
            }
        }

        return $dates;
    }

    /** The day off (if any) that applies to this member on one date. */
    public static function forMemberOn(Member $member, string $date): ?self
    {
        $day = Carbon::parse($date);

        foreach (static::loadFor($day, $day) as $off) {
            if ($off->appliesTo($member)) {
                return $off;
            }
        }

        return null;
    }
}