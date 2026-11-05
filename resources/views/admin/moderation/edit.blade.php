@extends('layouts.admin')
@section('title', __('openbook.admin.nav.moderation').' - '.config('app.name'))
@section('content')
    <h1>{{ __('openbook.admin.nav.moderation') }}</h1>
    <x-admin-tabs :label="__('openbook.admin.nav.moderation')" :tabs="$tabs" />
    @include('admin.moderation.'.$activeTab)
@endsection
