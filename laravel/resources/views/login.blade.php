@extends('layouts.cmt')

@section('title', 'Login — CMT Dashboard')

@section('content')
    <h2>Login</h2>

    @if (isset($error))
        <p class="error">{{ $error }}</p>
    @endif

    {{-- Field names match the Python form contract: username, password. --}}
    <form method="post" action="/login">
        @csrf
        <label for="username">Username</label>
        <input id="username" name="username" type="text" autofocus autocomplete="username">

        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password">

        <p><button type="submit" class="btn">Log in</button></p>
    </form>
@endsection
