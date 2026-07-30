<?php

namespace App\Domain\Moderation;

use App\Domain\Accounts\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Dominio remoto bloccato a livello di istanza (federazione).
 *
 * @property string $id
 * @property string $domain
 * @property string|null $reason
 * @property string|null $created_by
 * @property Carbon|null $created_at
 */
class DomainBlock extends Model
{
    use HasUuids;

    protected $fillable = [
        'domain',
        'reason',
        'created_by',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
