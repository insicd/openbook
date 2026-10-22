<?php

namespace App\Application\Services;

use App\Federation\Inbox\InboxItem;

final readonly class PostDeliverySource
{
    public function __construct(public string $kind = 'unknown', public ?string $relayKey = null, public ?string $label = null) {}

    public static function inbox(InboxItem $item): self
    {
        $relay = $item->relay;
        if ($relay === null) {
            return new self('inbox');
        }

        $identity = $relay->actor_uri ?: $relay->inbox_url;
        $host = parse_url($identity, PHP_URL_HOST);

        return new self('relay', hash('sha256', $identity), is_string($host) ? $host : null);
    }

    public function key(): string
    {
        return hash('sha256', $this->kind.':'.$this->relayKey);
    }
}
