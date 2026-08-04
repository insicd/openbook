@include('composer.form', [
    'mode' => 'post',
    'formId' => 'ob-composer',
    'bodyId' => 'composer-body',
    'prefix' => 'composer',
    'action' => route('posts.store'),
    'quotedPost' => $quotedPost ?? null,
    'composerCommunities' => $composerCommunities ?? collect(),
    'selectedCommunityId' => $selectedCommunityId ?? null,
    'addressedGroupActor' => $addressedGroupActor ?? null,
])
