<?php

namespace App\Federation\Polls;

use App\Federation\Inbox\RemotePostObject;
use App\Federation\Support\ActivityPubTimestamp;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/** Normalized poll metadata; author, audience and relevance belong to the importer. */
final readonly class RemotePollDocument
{
    public const MAX_OPTIONS = 50;

    public const MAX_OPTION_LENGTH = 1000;

    /** @param list<array{name: string, votes_count: int|null}> $options */
    private function __construct(
        public bool $multiple,
        public array $options,
        public ?Carbon $endsAt,
        public bool $closed,
        public ?Carbon $closedAt,
        public ?int $votersCount,
        public ?Carbon $updatedAt,
    ) {}

    /** @param array<string, mixed> $document */
    public static function fromArray(array $document): self
    {
        if (! RemotePostObject::hasType($document['type'] ?? null, 'Question')) {
            throw new InvalidArgumentException('Expected an ActivityStreams Question.');
        }

        $single = array_key_exists('oneOf', $document);
        $multiple = array_key_exists('anyOf', $document);

        if ($single === $multiple) {
            throw new InvalidArgumentException('A poll must declare exactly one choice mode.');
        }

        $rawOptions = $document[$multiple ? 'anyOf' : 'oneOf'];

        if (! is_array($rawOptions) || ! array_is_list($rawOptions)
            || count($rawOptions) < 2 || count($rawOptions) > self::MAX_OPTIONS) {
            throw new InvalidArgumentException('Unsupported poll options.');
        }

        $options = [];
        $names = [];

        foreach ($rawOptions as $option) {
            $name = is_array($option) ? ($option['name'] ?? null) : null;

            if (! is_array($option) || ! RemotePostObject::hasType($option['type'] ?? null, 'Note')
                || ! is_string($name) || ! mb_check_encoding($name, 'UTF-8')
                || trim($name) === '' || mb_strlen($name) > self::MAX_OPTION_LENGTH
                || in_array($name, $names, true)) {
                throw new InvalidArgumentException('Invalid or ambiguous poll option.');
            }

            $names[] = $name;
            $replies = $option['replies'] ?? null;

            if ($replies !== null && ! is_array($replies)) {
                throw new InvalidArgumentException('Invalid poll option replies.');
            }

            $options[] = ['name' => $name, 'votes_count' => self::count($replies['totalItems'] ?? null)];
        }

        $closed = $document['closed'] ?? false;

        if (! is_bool($closed) && ! is_string($closed)) {
            throw new InvalidArgumentException('Invalid poll closure.');
        }

        return new self(
            $multiple,
            $options,
            self::timestamp($document['endTime'] ?? null),
            $closed === true,
            is_string($closed) ? self::timestamp($closed) : null,
            self::count($document['votersCount'] ?? null),
            self::timestamp($document['updated'] ?? null),
        );
    }

    private static function count(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException('Invalid poll count.');
        }

        return $value;
    }

    private static function timestamp(mixed $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || strlen($value) > 64
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
            throw new InvalidArgumentException('Invalid poll timestamp.');
        }

        try {
            $date = ActivityPubTimestamp::parse($value);
            $errors = Carbon::getLastErrors();

            if ($date->year < 1000 || $date->year > 9999
                || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                throw new InvalidArgumentException('Invalid poll timestamp.');
            }

            return $date;
        } catch (\Exception $exception) {
            throw new InvalidArgumentException('Invalid poll timestamp.', 0, $exception);
        }
    }
}
