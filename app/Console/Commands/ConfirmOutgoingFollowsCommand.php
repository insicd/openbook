<?php

namespace App\Console\Commands;

use App\Domain\SocialGraph\Follow;
use App\Federation\SocialGraph\OutgoingFollowConfirmer;
use Illuminate\Console\Command;

/**
 * Guarisce Follow in uscita bloccati in "pending" quando il remoto ha gia'
 * accettato (collection followers) ma l'Accept non e' mai arrivato in inbox.
 */
class ConfirmOutgoingFollowsCommand extends Command
{
    protected $signature = 'openbook:confirm-outgoing-follows
        {--limit=8 : Numero massimo di Follow pending da verificare}';

    protected $description = 'Conferma Follow remoti pending verificando la collection followers.';

    public function handle(OutgoingFollowConfirmer $confirmer): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $follows = Follow::query()
            ->with(['follower', 'following.endpoints'])
            ->where('status', Follow::STATUS_PENDING)
            ->where('requested_at', '<=', now()->subSeconds(20))
            ->whereHas('follower', static fn ($query) => $query->where('is_local', true))
            ->whereHas('following', static fn ($query) => $query
                ->where('is_local', false)
                ->where('type', '!=', \App\Federation\Actors\Actor::TYPE_FEED))
            ->orderBy('requested_at')
            ->limit($limit)
            ->get();

        $confirmed = 0;

        foreach ($follows as $follow) {
            if ($confirmer->confirm($follow)) {
                $confirmed++;
            }
        }

        $this->info("Controllati {$follows->count()} Follow pending, confermati {$confirmed}.");

        return self::SUCCESS;
    }
}
