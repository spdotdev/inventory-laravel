@extends('inventory::web.layout')
@section('title', 'Create account — Inventory')
@section('content')
<h1>Create an account</h1>
<p class="sub">One account for the app and the web.</p>
<div class="card" style="max-width:420px">
  <form method="POST" action="{{ route('inventory.web.register') }}">
    @csrf
    <label for="name">Name</label>
    <input type="text" id="name" name="name" value="{{ old('name') }}" required autofocus>
    <label for="email">Email</label>
    <input type="email" id="email" name="email" value="{{ old('email') }}" required>
    <label for="password">Password</label>
    <input type="password" id="password" name="password" required>
    <label for="password_confirmation">Repeat password</label>
    <input type="password" id="password_confirmation" name="password_confirmation" required>
    <button type="submit" style="width:100%">Create account</button>
  </form>
  <p class="muted" style="margin-top:16px">
    Already have an account? <a href="{{ route('inventory.web.login.show') }}">Sign in</a>
  </p>
</div>
@endsection
