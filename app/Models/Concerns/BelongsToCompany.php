<?php

namespace App\Models\Concerns;

use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;

/**
 * Apply this trait to every business-scoped model (User, Member, Branch,
 * Attendance, AttendanceAttempt, and any future model like Shift or
 * LeaveRequest). It does two things:
 *
 *  1. Automatically adds `WHERE company_id = <logged-in user's company>`
 *     to every query against the model — so a controller that forgets to
 *     scope a query manually still can't leak another company's data.
 *  2. Automatically fills `company_id` when a new row is created, so
 *     controllers don't need to remember to pass it in on every ->create().
 *
 * Bypassing the scope (rare, e.g. a future super-admin/support tool that
 * needs to see across companies) is done explicitly and visibly:
 *   Member::withoutGlobalScope('company')->get();
 */
trait BelongsToCompany
{
    protected static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $builder) {
            if (auth()->check() && auth()->user()->company_id) {
                $builder->where($builder->getModel()->getTable() . '.company_id', auth()->user()->company_id);
            }
        });

        static::creating(function ($model) {
            if (empty($model->company_id) && auth()->check()) {
                $model->company_id = auth()->user()->company_id;
            }
        });
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
