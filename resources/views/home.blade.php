@extends('layouts.public')

@section('content')
    {{-- Urutan section berasal dari config/homepage.php dan sudah di-resolve
         HomeController lewat allowlist section_views. --}}
    @foreach ($sections as $sectionView)
        @include($sectionView)
    @endforeach
@endsection
