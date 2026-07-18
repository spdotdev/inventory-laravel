@extends('inventory::web.layout')
@section('title', __('Sign in').' — '.__('Inventory'))
@section('content')
<h1>{{ __('Sign in') }}</h1>
<p class="sub">{{ __('Manage your households and inventory from the browser.') }}</p>
<div class="card" style="max-width:420px">
  <form method="POST" action="{{ route('inventory.web.login') }}">
    @csrf
    <label for="email">{{ __('Email') }}</label>
    <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email">
    <label for="password">{{ __('Password') }}</label>
    <input type="password" id="password" name="password" required autocomplete="current-password">
    <button type="submit" style="width:100%">{{ __('Sign in') }}</button>
  </form>
  <p class="muted" style="margin-top:12px">
    <a href="{{ route('inventory.web.forgot-password.show') }}">{{ __('Forgot password?') }}</a>
  </p>
  @include('inventory::web.partials.google-signin')
  <p class="muted" style="margin-top:16px">
    {{ __('New here?') }} <a href="{{ route('inventory.web.register.show') }}">{{ __('Create an account') }}</a>
  </p>
</div>
@endsection
