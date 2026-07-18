@extends('inventory::web.layout')
@section('title', 'Your households — Inventory')
@section('content')
<h1>Your households</h1>
<p class="sub">Create a household, or join one with an invite code.</p>

@forelse ($households as $household)
  <div class="card row">
    <div class="grow">
      <a href="{{ route('inventory.web.households.show', $household) }}" style="font-size:17px;font-weight:600">{{ $household->name }}</a>
      <div class="muted">{{ $household->users_count }} {{ Str::plural('member', $household->users_count) }} · {{ $household->locations_count }} {{ Str::plural('location', $household->locations_count) }}</div>
    </div>
    <a class="btn btn-quiet" href="{{ route('inventory.web.households.show', $household) }}">Open</a>
  </div>
@empty
  <div class="card"><p class="muted">You're not in any household yet.</p></div>
@endforelse

<div class="card">
  <h2 style="font-size:16px;color:#b8d8f0;margin-bottom:14px">Create a household</h2>
  <form method="POST" action="{{ route('inventory.web.households.store') }}">
    @csrf
    <div class="row">
      <input class="grow" type="text" name="name" placeholder="e.g. Home" required style="margin-bottom:0">
      <button type="submit">Create</button>
    </div>
    @error('name') <p class="field-error">{{ $message }}</p> @enderror
  </form>
</div>

<div class="card">
  <h2 style="font-size:16px;color:#b8d8f0;margin-bottom:14px">Join with a code</h2>
  <form method="POST" action="{{ route('inventory.web.households.join') }}">
    @csrf
    <div class="row">
      <input class="grow mono" type="text" name="code" placeholder="ABCD-1234" required style="margin-bottom:0">
      <button type="submit">Join</button>
    </div>
    @error('code') <p class="field-error">{{ $message }}</p> @enderror
  </form>
</div>
@endsection
