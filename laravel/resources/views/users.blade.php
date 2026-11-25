@extends('layouts.cmt')

@section('title', 'Users — CMT Dashboard')

@section('content')
    <h2>Users</h2>

    @if ($error)
        <p class="error">{{ $error }}</p>
    @endif

    <table>
        <thead>
            <tr>
                <th>Username</th><th>Display name</th><th>Role</th>
                <th>Customer company</th><th>Created</th><th></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($users as $u)
                <tr>
                    <td>{{ $u['username'] }}</td>
                    <td>{{ $u['display_name'] }}</td>
                    <td>{{ $u['role'] }}</td>
                    <td>{{ $u['customer_company'] ?? '' }}</td>
                    <td>{{ $u['created_at'] }}</td>
                    <td>
                        <a class="btn" href="/users/{{ $u['id'] }}/edit">Edit</a>
                        @if ($cmtUser && $cmtUser->level() >= App\Support\CmtAuth::ROLE_LEVEL['superadmin'])
                            <form class="inline" method="post" action="/users/{{ $u['id'] }}/delete">
                                @csrf
                                <button type="submit" class="btn btn-danger">Delete</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h3>{{ $editing ? 'Edit user: '.$editing['username'] : 'New user' }}</h3>

    @if ($editing)
        <form method="post" action="/users/{{ $editing['id'] }}/edit">
    @else
        <form method="post" action="/users/new">
    @endif
        @csrf
        @if ($editing)
            <label>Display name</label>
            <input name="display_name" value="{{ $editing['display_name'] }}">
        @else
            <label>Username</label>
            <input name="username" required>
            <label>Display name</label>
            <input name="display_name">
        @endif

        <label>Role</label>
        <select name="role">
            @foreach (['superadmin', 'admin', 'management', 'staff', 'customer'] as $r)
                <option value="{{ $r }}" @selected(($editing['role'] ?? 'staff') === $r)>{{ $r }}</option>
            @endforeach
        </select>

        <label>Password {{ $editing ? '(leave blank to keep)' : '' }}</label>
        <input name="password" type="password" {{ $editing ? '' : 'required' }}>

        <label>Customer company (customer role only)</label>
        <input name="customer_company" value="{{ $editing['customer_company'] ?? '' }}">

        <p>
            <button type="submit" class="btn">{{ $editing ? 'Save' : 'Create' }}</button>
            @if ($editing)
                <a class="btn" href="/users">Cancel</a>
            @endif
        </p>
    </form>
@endsection
