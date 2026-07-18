@extends('inventory::web.layout')
@section('title', __('Forgot password').' — '.__('Inventory'))
@section('content')
<h1>{{ __('Forgot password') }}</h1>
<p class="sub">{{ __("Enter your email and we'll send you a link to reset your password.") }}</p>
<div class="card" style="max-width:420px">
  <form method="POST" action="{{ route('inventory.web.forgot-password.send') }}">
    @csrf
    <label for="email">{{ __('Email') }}</label>
    <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email">
    <button type="submit" style="width:100%">{{ __('Send reset link') }}</button>
  </form>
  <p class="muted" style="margin-top:16px">
    <a href="{{ route('inventory.web.login.show') }}">{{ __('Back to sign in') }}</a>
  </p>
</div>
@endsection
