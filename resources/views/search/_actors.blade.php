@if ($actors->isNotEmpty())
    <div class="ob-card">
        <h2 class="ob-side-widget__title">{{ $title }}</h2>
        @foreach ($actors as $person)
            @php
                $personName = $person->displayNameForText();
            @endphp
            <div class="ob-suggestion">
                <a href="{{ $person->profileUrl() }}" class="ob-mini-profile__link">
                    <x-avatar :actor="$person" style="width:40px;height:40px" />
                    <div>
                        <div class="ob-post__author">{{ $personName }}</div>
                        <div class="ob-post__handle">{{ '@'.$person->handle() }}</div>
                        @if ($person->isLocal() && $person->isPerson() && $person->user?->profile?->bio)
                            <div class="ob-field__help">{{ \App\Domain\Posts\PostBodyRenderer::render(\Illuminate\Support\Str::limit($person->user->profile->bio, 120)) }}</div>
                        @endif
                    </div>
                </a>
            </div>
        @endforeach
    </div>
@endif
