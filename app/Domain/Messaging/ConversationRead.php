<?php

namespace App\Domain\Messaging;

use App\Domain\Accounts\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $conversation_id
 * @property string $user_id
 * @property Carbon|null $last_read_at
 */
class ConversationRead extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'conversation_id',
        'user_id',
        'last_read_at',
    ];

    protected function casts(): array
    {
        return [
            'last_read_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
