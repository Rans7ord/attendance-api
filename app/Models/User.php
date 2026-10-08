<?php
namespace App\Models;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\Concerns\BelongsToCompany;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, BelongsToCompany;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'company_id',
        'attendance_pin_hash',
        'profile_photo_path',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function member()
    {
        return $this->hasOne(\App\Models\Member::class);
    }

    /**
     * False once an admin has set this person's member record to inactive.
     * A user with no member record (e.g. a fresh company admin) is active.
     */
    public function isActive(): bool
    {
        return $this->member?->status !== 'inactive';
    }

    /**
     * True for admin and super_admin — company-wide access.
     */
    public function isAdmin(): bool
    {
        return in_array($this->role, ['admin', 'super_admin'], true);
    }

    /**
     * True for manager — sees every branch and can approve leave, but
     * cannot create, edit or delete anything (admin-only).
     */
    public function isManager(): bool
    {
        return $this->role === 'manager';
    }

    /**
     * True for supervisor — branch-scoped access.
     */
    public function isSupervisor(): bool
    {
        return $this->role === 'supervisor';
    }

    /**
     * The branch this user belongs to, via their Member record (everyone
     * has one — admins and supervisors clock in too). Null if they have
     * no Member row yet.
     */
    public function branchId(): ?int
    {
        return $this->member?->branch_id;
    }
}