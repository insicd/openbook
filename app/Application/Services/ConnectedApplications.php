<?php

namespace App\Application\Services;

use App\Domain\Accounts\User;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Passport\Bridge\AccessTokenRepository;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;

final class ConnectedApplications
{
    public function forUser(User $user): Paginator
    {
        return $this->available($user)
            ->with(['client', 'refreshToken'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->simplePaginate(20)
            ->appends(['tab' => 'applications']);
    }

    public function close(User $user, string $id): bool
    {
        return Passport::token()->getConnection()->transaction(function () use ($user, $id): bool {
            // Always scope the lookup to the owner, including already closed accesses.
            $token = Passport::token()->newQuery()->where('user_id', $user->id)
                ->whereKey($id)->lockForUpdate()->firstOrFail();
            if (! $this->available($user)->whereKey($token->id)->exists()) {
                return false;
            }

            Passport::refreshToken()->newQuery()->where('access_token_id', $token->id)->update(['revoked' => true]);
            app(AccessTokenRepository::class)->revokeAccessToken($token->id);

            return true;
        });
    }

    /** @return Builder<Token> */
    private function available(User $user): Builder
    {
        return Passport::token()->newQuery()
            ->where('user_id', $user->id)
            ->whereHas('client', fn (Builder $query) => $query->where('revoked', false))
            ->where(function (Builder $query): void {
                $query->where(fn (Builder $access) => $access->where('revoked', false)->where('expires_at', '>', now()))
                    ->orWhereHas('refreshToken', fn (Builder $refresh) => $refresh->where('revoked', false)->where('expires_at', '>', now()));
            });
    }
}
