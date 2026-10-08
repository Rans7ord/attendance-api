<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;

class UserNotification extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'user_id', 'type', 'title', 'body', 'data', 'read_at'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    /**
     * company_id is passed explicitly (not left to the trait) so this also
     * works from a scheduled command, where nobody is logged in.
     */
    public static function send(User $user, string $type, string $title, ?string $body = null, array $data = []): self
    {
        return static::create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);
    }
}