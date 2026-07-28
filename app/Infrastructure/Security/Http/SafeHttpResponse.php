<?php

namespace App\Infrastructure\Security\Http;

/**
 * Risposta HTTP gia' scaricata e sottoposta ai limiti di sicurezza (dimensione
 * massima, ridirezioni validate una a una).
 */
final readonly class SafeHttpResponse
{
    /**
     * @param  array<string, list<string>>  $headers
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {}

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $headerName => $values) {
            if (mb_strtolower($headerName) === mb_strtolower($name)) {
                return $values[0] ?? null;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function json(): ?array
    {
        try {
            $decoded = json_decode($this->body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
