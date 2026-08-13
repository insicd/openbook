<?php

namespace App\Application\Queries;

use App\Domain\Posts\Post;
use Countable;
use Illuminate\Support\Collection;
use IteratorAggregate;
use Traversable;

/**
 * Pagina di feed per lo scorrimento infinito: elenco di post + URL del
 * cursore successivo (se presente).
 *
 * @implements IteratorAggregate<int, Post>
 */
final class FeedPage implements Countable, IteratorAggregate
{
    /**
     * @param  Collection<int, Post>  $posts
     */
    public function __construct(
        private readonly Collection $posts,
        private readonly ?string $nextPageUrl,
    ) {}

    /**
     * @return Collection<int, Post>
     */
    public function getCollection(): Collection
    {
        return $this->posts;
    }

    public function isEmpty(): bool
    {
        return $this->posts->isEmpty();
    }

    public function hasMorePages(): bool
    {
        return $this->nextPageUrl !== null;
    }

    /**
     * Compatibilita' con il parziale feed e i test legacy.
     */
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
        return $this->posts->count();
    }

    public function getIterator(): Traversable
    {
        return $this->posts->getIterator();
    }
}
