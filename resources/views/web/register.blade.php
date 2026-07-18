@extends('inventory::web.layout')
@section('title', __('Create account').' — '.__('Inventory'))
@section('content')
<h1>{{ __('Create an account') }}</h1>
<p class="sub">{{ __('One account for the app and the web.') }}</p>
<div class="card" style="max-width:420px">
  <form method="POST" action="{{ route('inventory.web.register') }}">
    @csrf
    <label for="name">{{ __('Name') }}</label>
    <input type="text" id="name" name="name" value="{{ old('name') }}" required autofocus>
    <label for="email">{{ __('Email') }}</label>
    <input type="email" id="email" name="email" value="{{ old('email') }}" required>
    <label for="password">{{ __('Password') }}</label>
    <input type="password" id="password" name="password" required>
    <label for="password_confirmation">{{ __('Repeat password') }}</label>
    <input type="password" id="password_confirmation" name="password_confirmation" required>
    <button type="submit" style="width:100%">{{ __('Create account') }}</button>
  </form>
  @include('inventory::web.partials.google-signin')
  <p class="muted" style="margin-top:16px">
    {{ __('Already have an account?') }} <a href="{{ route('inventory.web.login.show') }}">{{ __('Sign in') }}</a>
  </p>
</div>
@endsection
