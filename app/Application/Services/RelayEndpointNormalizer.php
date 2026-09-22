<?php

namespace App\Application\Services;

use InvalidArgumentException;

final class RelayEndpointNormalizer
{
    public function normalize(string $value): string
    {
        $value = trim($value);
        $parts = parse_url($value);

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new InvalidArgumentException(__('openbook.admin.relays.invalid_url'));
        }

        $host = strtolower(rtrim((string) $parts['host'], '.'));

        if ($host === '' || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new InvalidArgumentException(__('openbook.admin.relays.invalid_url'));
        }

        $port = isset($parts['port']) && (int) $parts['port'] !== 443 ? ':'.(int) $parts['port'] : '';
        $path = '/'.ltrim((string) ($parts['path'] ?? '/'), '/');
        $path = $path === '/' ? '' : rtrim($path, '/');

        return 'https://'.$host.$port.$path;
    }
}
