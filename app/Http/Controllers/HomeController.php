<?php

namespace App\Http\Controllers;

use App\Application\Queries\FeedCursor;
use App\Application\Queries\FeedQuery;
use App\Application\Queries\InstanceStaffQuery;
use App\Application\Services\InstanceSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class HomeController extends Controller
{
    public function __construct(
        private readonly InstanceStaffQuery $instanceStaff,
        private readonly InstanceSettings $settings,
        private readonly FeedQuery $feedQuery,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('feed.index');
        }

        $guestHomeFeed = $this->settings->guestHomeFeed();

        if ($request->ajax()) {
            abort_unless($guestHomeFeed, 404);

            $posts = $this->feedQuery->instancePublic(FeedCursor::fromRequest($request));

            return view('posts._feed', [
                'posts' => $posts,
                'emptyMessage' => __('openbook.home.feed_empty'),
                'asyncFeed' => true,
                'fragment' => true,
            ]);
        }

        return view('home', [
            'guestHome' => true,
            'guestHomeFeed' => $guestHomeFeed,
            'siteName' => $this->settings->siteName(),
            'siteDescription' => $this->settings->siteDescription(),
            'homeBackgroundUrl' => $this->settings->homeBackgroundUrl(),
            'instanceLogoUrl' => $this->settings->appleTouchIconUrl(),
            'staffMembers' => $this->settings->showHomeStaff()
                ? $this->instanceStaff->all()
                : collect(),
            'posts' => $guestHomeFeed
                ? $this->feedQuery->instancePublic(FeedCursor::fromRequest($request))
                : null,
        ]);
    }
}
