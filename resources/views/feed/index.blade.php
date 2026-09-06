@extends('layouts.app')

@section('title', __('openbook.nav.home').' - '.config('app.name'))

@section('content')
    @unless ($currentUser->hasVerifiedEmail())
        <div class="ob-alert" style="background:#fff6e0;border-color:#f0dfa6;color:#8a6d1c">
            {{ __('openbook.verify_email.body') }}
            <a href="{{ route('verification.notice') }}">{{ __('openbook.verify_email.title') }}</a>
        </div>
    @endunless

    @include('posts._composer', [
        'quotedPost' => $quotedPost ?? null,
        'composerCommunities' => $composerCommunities ?? collect(),
    ])

    @if (($pendingPublications ?? collect())->isNotEmpty())
        <section class="ob-card" style="margin-top:1rem">
            <h2>{{ __('openbook.posts.video_queue_title') }}</h2>
            @foreach ($pendingPublications as $publication)
                <div style="display:flex;gap:1rem;align-items:center;justify-content:space-between;margin-top:.75rem">
                    <span>
                        {{ \Illuminate\Support\Str::limit($publication->payload['body'] ?? '', 80) }}
                        — {{ __('openbook.posts.video_status_'.$publication->status) }}
                    </span>
                    @if ($publication->status !== \App\Domain\Posts\PendingPostPublication::STATUS_PROCESSING)
                        <form method="POST" action="{{ route('posts.pending.destroy', $publication) }}">
                            @csrf
                            @method('DELETE')
                            <button class="ob-btn ob-btn--secondary" type="submit">{{ __('openbook.actions.delete') }}</button>
                        </form>
                    @endif
                </div>
            @endforeach
        </section>
    @endif

    @if (($welcomeKit ?? null) !== null)
        @include('feed._welcome', ['welcomeKit' => $welcomeKit])
    @else
        @include('posts._feed', ['posts' => $posts, 'emptyMessage' => __('openbook.feed.empty')])
    @endif
@endsection
