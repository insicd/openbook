<?php

namespace App\Domain\Notifications;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushNotification extends Model
{
    use HasUuids;

    protected $fillable = [
        'notification_id',
    ];

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }
}
