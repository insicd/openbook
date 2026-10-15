@extends('layouts.app')

@section('title', __('openbook.polls.voters_title').' - '.config('app.name'))

@section('content')
    @include('polls._voters')
@endsection
