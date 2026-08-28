<?php

namespace App\Application\Queries;

use Countable;
use Illuminate\Support\Collection;
use IteratorAggregate;
use Traversable;

/**
 * Pagina dello stream di attivita' di un profilo, con URL della pagina
 * successiva per lo scorrimento infinito (stesso schema della galleria foto).
 *
 * @implements IteratorAggregate<int, ActorActivityItem>
 */
final class ActivityPage implements Countable, IteratorAggregate
{
    /**
     * @param  Collection<int, ActorActivityItem>  $items
     */
    public function __construct(
        private readonly Collection $items,
        private readonly ?string $nextPageUrl,
    ) {}

    /**
     * @return Collection<int, ActorActivityItem>
     */
    public function getCollection(): Collection
    {
        return $this->items;
    }

    public function isEmpty(): bool
    {
        return $this->items->isEmpty();
    }

    public function hasMorePages(): bool
    {
        return $this->nextPageUrl !== null;
    }

    public function hasPages(): bool
    {
        return $this->hasMorePages();
    }

    public function nextPageUrl(): ?string
    {
        return $this->nextPageUrl;
    }

    public function count(): int
    {
        return $this->items->count();
    }

    public function getIterator(): Traversable
    {
        return $this->items->getIterator();
    }
}
