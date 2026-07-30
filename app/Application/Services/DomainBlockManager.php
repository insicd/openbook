<?php

namespace App\Application\Services;

use App\Domain\Accounts\User;
use App\Domain\Moderation\DomainBlock;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Blocchi di dominio federato: impediscono fetch, consegna e inbox.
 */
final class DomainBlockManager
{
    private const CACHE_KEY = 'openbook.domain_blocks';

    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function isBlockedHost(string $host): bool
    {
        $host = $this->normalizeDomain($host);

        if ($host === '' || strcasecmp($host, (string) config('openbook.domain')) === 0) {
            return false;
        }

        return in_array($host, $this->blockedHosts(), true);
    }

    public function isBlockedUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $this->isBlockedHost($host);
    }

    /**
     * @return list<string>
     */
    public function blockedHosts(): array
    {
        /** @var list<string> $hosts */
        $hosts = Cache::remember(self::CACHE_KEY, 60, function () {
            return DomainBlock::query()
                ->orderBy('domain')
                ->pluck('domain')
                ->map(fn (string $domain) => $this->normalizeDomain($domain))
                ->filter()
                ->values()
                ->all();
        });

        return $hosts;
    }

    public function block(User $actor, string $domain, ?string $reason = null): DomainBlock
    {
        if (! $actor->canAdminister()) {
            throw new InvalidArgumentException('Solo un amministratore puo bloccare un dominio.');
        }

        $domain = $this->normalizeDomain($domain);

        if ($domain === '') {
            throw new InvalidArgumentException('Dominio non valido.');
        }

        if (strcasecmp($domain, (string) config('openbook.domain')) === 0) {
            throw new InvalidArgumentException('Non puoi bloccare il dominio di questa istanza.');
        }

        $existing = DomainBlock::query()->where('domain', $domain)->first();

        if ($existing !== null) {
            return $existing;
        }

        $block = DomainBlock::query()->create([
            'domain' => $domain,
            'reason' => filled($reason) ? trim($reason) : null,
            'created_by' => $actor->id,
        ]);

        Cache::forget(self::CACHE_KEY);
        $this->auditLogger->log($actor, 'domain.block', $block, ['domain' => $domain]);

        return $block;
    }

    public function unblock(User $actor, DomainBlock $block): void
    {
        if (! $actor->canAdminister()) {
            throw new InvalidArgumentException('Solo un amministratore puo sbloccare un dominio.');
        }

        $domain = $block->domain;
        $block->delete();
        Cache::forget(self::CACHE_KEY);
        $this->auditLogger->log($actor, 'domain.unblock', null, ['domain' => $domain]);
    }

    public function normalizeDomain(string $domain): string
    {
        $domain = mb_strtolower(trim($domain));
        $domain = preg_replace('#^https?://#i', '', $domain) ?? $domain;
        $domain = explode('/', $domain, 2)[0];
        $domain = explode(':', $domain, 2)[0];
        $domain = rtrim($domain, '.');

        if ($domain === '' || ! preg_match('/^[a-z0-9.-]+\.[a-z0-9.-]+$/', $domain)) {
            return '';
        }

        return $domain;
    }
}
