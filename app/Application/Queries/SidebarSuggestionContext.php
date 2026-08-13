<?php

namespace App\Application\Queries;

use App\Domain\Posts\Hashtag;
use App\Http\Support\FederatedHandleParser;

/**
 * Termine di contesto per i suggerimenti laterali (hashtag o keyword di ricerca).
 */
final class SidebarSuggestionContext
{
    public static function bioSearchTerm(): ?string
    {
        $request = request();

        if ($request->routeIs('hashtags.show')) {
            $name = $request->route('name');

            return is_string($name) ? Hashtag::normalize($name) : null;
        }

        if ($request->routeIs('search.create')) {
            $query = ltrim(trim((string) $request->query('q', '')), '#');

            if ($query === '' || mb_strlen($query) < (int) config('openbook.search.min_length', 2)) {
                return null;
            }

            if (FederatedHandleParser::parse($query) !== null) {
                return null;
            }

            return $query;
        }

        return null;
    }
}
