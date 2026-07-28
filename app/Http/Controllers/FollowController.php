<?php

namespace App\Http\Controllers;

use App\Application\Services\FollowManager;
use App\Domain\Accounts\User;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class FollowController extends Controller
{
    public function __construct(
        private readonly FollowManager $followManager,
    ) {}

    public function store(User $user): RedirectResponse
    {
        try {
            $this->followManager->follow(auth()->user()->actor, $user->actor);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['follow' => $exception->getMessage()]);
        }

        return back()->with('status', 'Richiesta inviata.');
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->followManager->unfollow(auth()->user()->actor, $user->actor);

        return back();
    }

    /**
     * Equivalenti dei due metodi precedenti per un Actor *remoto*: la
     * richiesta resta sempre "in attesa" finche' non arriva un Accept dal
     * server remoto (vedi {@see FollowManager::follow()}).
     */
    public function storeForActor(Actor $actor): RedirectResponse
    {
        try {
            $this->followManager->follow(auth()->user()->actor, $actor);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['follow' => $exception->getMessage()]);
        }

        return back()->with('status', 'Richiesta di follow inviata.');
    }

    public function destroyForActor(Actor $actor): RedirectResponse
    {
        $this->followManager->unfollow(auth()->user()->actor, $actor);

        return back();
    }

    public function accept(Follow $follow): RedirectResponse
    {
        $follower = $follow->follower;
        $target = $follow->following;

        abort_unless($target->user_id === auth()->id(), 403);

        $this->followManager->accept($target, $follower);

        return back()->with('status', 'Richiesta di follow accettata.');
    }

    public function reject(Follow $follow): RedirectResponse
    {
        $follower = $follow->follower;
        $target = $follow->following;

        abort_unless($target->user_id === auth()->id(), 403);

        $this->followManager->reject($target, $follower);

        return back()->with('status', 'Richiesta di follow rifiutata.');
    }
}
