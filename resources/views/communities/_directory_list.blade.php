@foreach ($actors as $actor)
    @include('communities._directory_item', ['actor' => $actor, 'statusMap' => $statusMap])
@endforeach
