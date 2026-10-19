<?php

namespace App\Application\Services;

use App\Domain\Accounts\User;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Federation\Actors\LocalActorResolver;
use App\Federation\Actors\RemoteActorResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class FollowingCsvService
{
    public const MAX_ROWS = 10000;

    public function __construct(
        private readonly FollowManager $follows,
        private readonly RemoteActorResolver $remotes,
        private readonly LocalActorResolver $locals,
        private readonly DomainBlockManager $blocks,
    ) {}

    public function exportQuery(User $user): Builder
    {
        $this->assertOwner($user);

        return Follow::query()->where('follower_id', $user->actor->id)
            ->where('status', Follow::STATUS_ACCEPTED)->with('following');
    }

    /** @param resource $stream */
    public function writeExport(User $user, $stream): void
    {
        fputcsv($stream, ['Account address'], ',', '"', '');
        foreach ($this->exportQuery($user)->lazyById(500, 'following_id') as $follow) {
            $target = $follow->following;
            if ($target !== null && ! $target->isFeed() && filled($target->preferred_username) && filled($target->domain)
                && $this->normalize($target->handle()) !== null) {
                fputcsv($stream, [$target->handle()], ',', '"', '');
            }
        }
    }

    /** @return array{entries: list<array{address: string, valid: bool}>, duplicates: int} */
    private function read(UploadedFile $file): array
    {
        $contents = $file->getContent();
        if (strlen($contents) > 1024 * 1024 || ! mb_check_encoding($contents, 'UTF-8') || str_contains($contents, "\0")) {
            $this->invalid('csv_invalid');
        }
        $stream = fopen($file->getRealPath(), 'rb');
        $entries = $seen = [];
        $duplicates = $rowNumber = $dataRows = 0;
        $column = 0;
        try {
            while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
                if ($row === [null] || count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0) {
                    continue;
                }
                if (++$rowNumber === 1) {
                    $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0] ?? '');
                    $headers = array_map(fn ($value) => mb_strtolower(trim((string) $value)), $row);
                    $header = array_search('account address', $headers, true);
                    if ($header !== false) {
                        $column = $header;

                        continue;
                    }
                    if (count($row) > 1 || $this->normalize((string) $row[0]) === null) {
                        $this->invalid('csv_invalid');
                    }
                }
                if (++$dataRows > self::MAX_ROWS) {
                    $this->invalid('csv_too_many');
                }
                $value = trim((string) ($row[$column] ?? ''));
                $address = $this->normalize($value);
                $key = mb_strtolower($address ?? $value);
                if (isset($seen[$key])) {
                    $duplicates++;

                    continue;
                }
                $seen[$key] = true;
                $entries[] = ['address' => mb_substr($address ?? $value, 0, 320), 'valid' => $address !== null];
            }
        } finally {
            fclose($stream);
        }
        if ($entries === []) {
            $this->invalid('csv_invalid');
        }

        return compact('entries', 'duplicates');
    }

    /** @return array{followed: int, pending: int, existing: int, failed: list<string>, duplicates: int} */
    public function import(User $user, UploadedFile $file): array
    {
        $source = $this->assertOwner($user);
        $data = $this->read($file);
        $result = ['followed' => 0, 'pending' => 0, 'existing' => 0, 'failed' => [], 'duplicates' => $data['duplicates']];
        foreach ($data['entries'] as $entry) {
            $address = $entry['address'];
            if (! $entry['valid']) {
                $result['failed'][] = $address;

                continue;
            }
            try {
                [$username, $domain] = explode('@', $address, 2);
                if ($this->blocks->isBlockedHost($domain)) {
                    $result['failed'][] = $address;

                    continue;
                }
                $target = strcasecmp($domain, (string) config('openbook.domain')) === 0
                    ? $this->locals->findByUsername($username)
                    : (Actor::query()->where('preferred_username', $username)->where('domain', $domain)
                        ->where('is_local', false)->first() ?? $this->remotes->resolveByHandle($address));
                if ($target === null || $target->isFeed() || $target->id === $source->id
                    || ! $target->isActive() || $target->isRemotelySuspended() || $this->blocks->isBlockedUrl($target->activityPubId())) {
                    $result['failed'][] = $address;

                    continue;
                }
                if (Follow::query()->where('follower_id', $source->id)->where('following_id', $target->id)->exists()) {
                    $result['existing']++;

                    continue;
                }
                $follow = $this->follows->follow($source, $target);
                $result[$follow->isAccepted() ? 'followed' : 'pending']++;
            } catch (InvalidArgumentException|AuthorizationException $exception) {
                $result['failed'][] = $address;
            } catch (\Throwable $exception) {
                report($exception);
                $result['failed'][] = $address;
            }
        }

        return $result;
    }

    private function normalize(string $address): ?string
    {
        $address = ltrim(trim($address), '@');
        if (! mb_check_encoding($address, 'UTF-8') || strlen($address) > 320
            || preg_match('~^(?![=+\-])([^@\s/:?#\x00-\x1F]+)@([^@\s/?#\x00-\x1F]+)$~uD', $address, $parts) !== 1) {
            return null;
        }

        return $parts[1].'@'.mb_strtolower($parts[2]);
    }

    private function assertOwner(User $user): Actor
    {
        $actor = $user->actor;
        if (! $user->isActive() || $actor === null || ! $actor->isLocal() || ! $actor->isPerson()
            || ! $actor->isActive() || $actor->user_id !== $user->id) {
            throw new AuthorizationException;
        }

        return $actor;
    }

    private function invalid(string $key): never
    {
        throw ValidationException::withMessages(['csv' => __('openbook.settings.'.$key)])
            ->redirectTo(route('settings.edit', ['tab' => 'import_export']));
    }
}
