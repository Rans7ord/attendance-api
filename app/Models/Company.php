<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $fillable = [
        'name',
        'join_code',
        'join_code_expires_at',
        'join_code_max_uses',
        'join_code_uses_count',
        'require_selfie_on_join',
        'industry',
        'logo_path',
        'address',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'join_code_expires_at' => 'datetime',
            'require_selfie_on_join' => 'boolean',
        ];
    }

    public function users() { return $this->hasMany(User::class); }
    public function members() { return $this->hasMany(Member::class); }
    public function branches() { return $this->hasMany(Branch::class); }
    public function invites() { return $this->hasMany(Invite::class); }

    /**
     * Generate a unique, unambiguous join code (used when a company signs up
     * and whenever an admin regenerates it). Excludes easily-confused
     * characters (0/O, 1/I) since this gets typed by hand or read off a
     * poster.
     */
    public static function generateUniqueJoinCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $code = collect(range(1, 6))
                ->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])
                ->implode('');
        } while (static::where('join_code', $code)->exists());

        return $code;
    }

    public function joinCodeIsUsable(): bool
    {
        if ($this->join_code_expires_at && $this->join_code_expires_at->isPast()) {
            return false;
        }

        if (!is_null($this->join_code_max_uses) && $this->join_code_uses_count >= $this->join_code_max_uses) {
            return false;
        }

        return true;
    }
}