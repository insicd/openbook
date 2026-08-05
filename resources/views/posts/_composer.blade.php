@include('composer.form', [
    'mode' => 'post',
    'formId' => $formId ?? 'ob-composer',
    'bodyId' => $bodyId ?? 'composer-body',
    'prefix' => $prefix ?? 'composer',
    'action' => route('posts.store'),
    'quotedPost' => $quotedPost ?? null,
    'composerCommunities' => $composerCommunities ?? collect(),
    'selectedCommunityId' => $selectedCommunityId ?? null,
    'addressedGroupActor' => $addressedGroupActor ?? null,
    'inModal' => $inModal ?? false,
    'composerUi' => $composerUi ?? null,
])
