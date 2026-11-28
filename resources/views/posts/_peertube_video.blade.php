@php
    $video = $post->remote_video;
    $embed = $video['embedUrl'] === null ? null : new \App\Domain\Posts\VideoEmbed(
        \App\Domain\Posts\VideoEmbed::PROVIDER_PEERTUBE,
        (string) \Illuminate\Support\Uri::of($video['embedUrl'])->withQuery(['autoplay' => 1]),
        $post->uri,
    );
@endphp

<div data-peertube-player>
    <div class="ob-post__video">
        <a class="ob-post__video-launch" href="{{ $post->uri }}" @if ($embed) data-peertube-play @else target="_blank" rel="noopener noreferrer" @endif>
            @if ($video['posterUrl'] !== null)
                <img src="{{ $video['posterUrl'] }}" alt="" loading="lazy" referrerpolicy="no-referrer">
            @endif
            <span>{{ __($embed ? 'openbook.posts.play_remote_video' : 'openbook.posts.open_on_peertube') }}</span>
        </a>
    </div>
    @if ($embed)
        <template data-peertube-frame>
            @include('posts._video_embed', ['embed' => $embed, 'sandboxRemotePlayer' => true])
        </template>
        <a href="{{ $post->uri }}" target="_blank" rel="noopener noreferrer">{{ __('openbook.posts.open_on_peertube') }}</a>
    @endif
</div>
