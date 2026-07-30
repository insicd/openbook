<?php

namespace App\Http\Controllers\Admin;

use App\Application\Services\StaffUserManager;
use App\Domain\Accounts\User;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class UserController extends Controller
{
    public function __construct(
        private readonly StaffUserManager $staffUserManager,
    ) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $users = User::query()
            ->with('profile')
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower($q)).'%';
                $query->where(function ($query) use ($like) {
                    $query->whereRaw('lower(username) like ?', [$like])
                        ->orWhereRaw('lower(email) like ?', [$like]);
                });
            })
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'q' => $q,
        ]);
    }

    public function suspend(User $user): RedirectResponse
    {
        $this->runStaffAction(fn () => $this->staffUserManager->suspend(auth()->user(), $user));

        return back()->with('status', __('openbook.admin.users.suspended', ['name' => $user->username]));
    }

    public function unsuspend(User $user): RedirectResponse
    {
        $this->runStaffAction(fn () => $this->staffUserManager->unsuspend(auth()->user(), $user));

        return back()->with('status', __('openbook.admin.users.unsuspended', ['name' => $user->username]));
    }

    public function disable(User $user): RedirectResponse
    {
        $this->runStaffAction(fn () => $this->staffUserManager->disable(auth()->user(), $user));

        return back()->with('status', __('openbook.admin.users.disabled', ['name' => $user->username]));
    }

    public function promoteModerator(User $user): RedirectResponse
    {
        Gate::authorize('administer');
        $this->runStaffAction(fn () => $this->staffUserManager->promoteModerator(auth()->user(), $user));

        return back()->with('status', __('openbook.admin.users.promoted_mod', ['name' => $user->username]));
    }

    public function demoteModerator(User $user): RedirectResponse
    {
        Gate::authorize('administer');
        $this->runStaffAction(fn () => $this->staffUserManager->demoteModerator(auth()->user(), $user));

        return back()->with('status', __('openbook.admin.users.demoted_mod', ['name' => $user->username]));
    }

    public function promoteAdmin(User $user): RedirectResponse
    {
        Gate::authorize('administer');
        $this->runStaffAction(fn () => $this->staffUserManager->promoteAdmin(auth()->user(), $user));

        return back()->with('status', __('openbook.admin.users.promoted_admin', ['name' => $user->username]));
    }

    public function demoteAdmin(User $user): RedirectResponse
    {
        Gate::authorize('administer');
        $this->runStaffAction(fn () => $this->staffUserManager->demoteAdmin(auth()->user(), $user));

        return back()->with('status', __('openbook.admin.users.demoted_admin', ['name' => $user->username]));
    }

    private function runStaffAction(callable $action): void
    {
        try {
            $action();
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['user' => $exception->getMessage()]);
        }
    }
}
