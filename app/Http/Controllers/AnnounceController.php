<?php

namespace App\Http\Controllers;

use App\Application\Services\AnnounceManager;
use App\Domain\Posts\Post;
use Illuminate\Http\RedirectResponse;

class AnnounceController extends Controller
{
    public function __construct(
        private readonly AnnounceManager $announces,
    ) {}

    public function store(Post $post): RedirectResponse
    {
        $this->announces->announce(auth()->user()->actor, $post);

        return back()->with('status', 'Post condiviso.');
    }

    public function destroy(Post $post): RedirectResponse
    {
        $this->announces->unannounce(auth()->user()->actor, $post);

        return back();
    }
}
