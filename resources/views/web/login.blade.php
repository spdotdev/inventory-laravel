@extends('inventory::web.layout')
@section('title', 'Sign in — Inventory')
@section('content')
<h1>Sign in</h1>
<p class="sub">Manage your households and inventory from the browser.</p>
<div class="card" style="max-width:420px">
  <form method="POST" action="{{ route('inventory.web.login') }}">
    @csrf
    <label for="email">Email</label>
    <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus>
    <label for="password">Password</label>
    <input type="password" id="password" name="password" required>
    <button type="submit" style="width:100%">Sign in</button>
  </form>
  @include('inventory::web.partials.google-signin')
  <p class="muted" style="margin-top:16px">
    New here? <a href="{{ route('inventory.web.register.show') }}">Create an account</a>
  </p>
</div>
@endsection
