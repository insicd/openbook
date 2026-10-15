<?php

namespace App\Domain\Polls;

use Illuminate\Support\Facades\Validator;

/** Validated input; expiry is calculated only when the post is published. */
final readonly class LocalPollDefinition
{
    public const MIN_OPTIONS = 2;

    public const MAX_OPTIONS = 6;

    public const MAX_OPTION_LENGTH = 50;

    public const MIN_DURATION = 300;

    public const MAX_DURATION = 604800;

    public const DEFAULT_DURATION = 86400;

    /** @param list<string> $options */
    private function __construct(public array $options, public bool $multiple, public int $durationSeconds) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'poll' => ['nullable', 'array:options,multiple,duration_seconds'],
            'poll.options' => ['required_with:poll', 'array', 'list', 'min:'.self::MIN_OPTIONS, 'max:'.self::MAX_OPTIONS],
            'poll.options.*' => ['required', 'string', 'max:'.self::MAX_OPTION_LENGTH, 'distinct:strict'],
            'poll.multiple' => ['required_with:poll', 'boolean'],
            'poll.duration_seconds' => ['required_with:poll', 'integer', 'min:'.self::MIN_DURATION, 'max:'.self::MAX_DURATION],
        ];
    }

    public static function fromInput(mixed $input): self
    {
        if (is_array($input) && isset($input['options']) && is_array($input['options'])) {
            $input['options'] = array_map(static fn ($name) => is_string($name) ? trim($name) : $name, $input['options']);
        }
        $rules = self::rules();
        $rules['poll'] = ['required', 'array:options,multiple,duration_seconds'];
        $data = Validator::make(['poll' => $input], $rules)->validate()['poll'];

        return new self($data['options'], (bool) $data['multiple'], (int) $data['duration_seconds']);
    }

    /** @return array{options: list<string>, multiple: bool, duration_seconds: int} */
    public function toArray(): array
    {
        return ['options' => $this->options, 'multiple' => $this->multiple, 'duration_seconds' => $this->durationSeconds];
    }
}
