<?php

namespace App\Http\Controllers\Federation;

use App\Application\Services\InstanceSettings;
use App\Domain\Accounts\User;
use App\Domain\Comments\Comment;
use App\Domain\Posts\Post;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * Discovery NodeInfo (sezione 16 del design): un documento pubblico con
 * informazioni essenziali sull'istanza, usato da strumenti di monitoraggio e
 * directory del Fediverso.
 */
final class NodeInfoController extends Controller
{
    public function discovery(): JsonResponse
    {
        return response()->json([
            'links' => [
                [
                    'rel' => 'http://nodeinfo.diaspora.software/ns/schema/2.1',
                    'href' => url('/nodeinfo/2.1'),
                ],
            ],
        ]);
    }

    public function show(): JsonResponse
    {
        $totalUsers = User::query()->where('status', User::STATUS_ACTIVE)->count();

        $activeMonth = User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->where('last_login_at', '>=', Carbon::now()->subDays(30))
            ->count();

        $activeHalfyear = User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->where('last_login_at', '>=', Carbon::now()->subDays(180))
            ->count();

        $document = [
            'version' => '2.1',
            'software' => [
                'name' => 'openbook',
                'version' => (string) config('openbook.version'),
                'repository' => 'https://github.com/openbook-social/openbook',
                'homepage' => config('openbook.homepage'),
            ],
            'protocols' => ['activitypub'],
            'services' => [
                'inbound' => [],
                'outbound' => [],
            ],
            'openRegistrations' => app(InstanceSettings::class)->registrationOpen(),
            'usage' => [
                'users' => [
                    'total' => $totalUsers,
                    'activeMonth' => $activeMonth,
                    'activeHalfyear' => $activeHalfyear,
                ],
                'localPosts' => Post::query()->where('status', Post::STATUS_PUBLISHED)->count(),
                'localComments' => Comment::query()->where('status', Comment::STATUS_PUBLISHED)->count(),
            ],
            'metadata' => [
                'nodeName' => config('app.name'),
            ],
        ];

        return response()->json($document, 200, [
            'Content-Type' => 'application/json; profile="http://nodeinfo.diaspora.software/ns/schema/2.1#"',
        ]);
    }
}
