<div class="ob-message-quote">
    @include('posts._card', [
        'post' => $quotedPost,
        'embed' => true,
        'embedDepth' => 1,
        'linkToPost' => true,
        'truncateBody' => true,
    ])
</div>
