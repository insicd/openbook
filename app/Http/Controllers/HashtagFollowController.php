<?php

namespace App\Http\Controllers;

use App\Domain\Posts\Hashtag;
use Illuminate\Http\RedirectResponse;

class HashtagFollowController extends Controller
{
    public function store(string $name): RedirectResponse
    {
        $normalized = $this->normalizedName($name);
        $hashtag = Hashtag::query()->firstOrCreate(['name' => $normalized]);

        auth()->user()->actor->followedHashtags()->syncWithoutDetaching([$hashtag->id]);

        return back();
    }

    public function destroy(string $name): RedirectResponse
    {
        $normalized = $this->normalizedName($name);
        $hashtag = Hashtag::query()->where('name', $normalized)->first();

        if ($hashtag !== null) {
            auth()->user()->actor->followedHashtags()->detach($hashtag->id);
        }

        return back();
    }

    private function normalizedName(string $name): string
    {
        $normalized = Hashtag::normalize($name);

        abort_unless(Hashtag::isValidName($normalized), 404);

        return $normalized;
    }
}
