<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PinLoginAttempt extends Model
{
    protected $fillable = [
        'user_id',
        'company_id',
        'email_attempted',
        'photo_path',
        'success',
        'ip_address',
    ];

    protected function casts(): array
    {
        return ['success' => 'boolean'];
    }

    public function user() { return $this->belongsTo(User::class); }
    public function company() { return $this->belongsTo(Company::class); }
}