@extends('inventory::web.layout')
@section('title', __('Your households').' — '.__('Inventory'))
@section('content')
<h1>{{ __('Your households') }}</h1>
<p class="sub">{{ __('Create a household, or join one with an invite code.') }}</p>

@forelse ($households as $household)
  <div class="card row">
    <div class="grow">
      <a href="{{ route('inventory.web.households.show', $household) }}" style="font-size:17px;font-weight:600">{{ $household->name }}</a>
      <div class="muted">{{ trans_choice(':count member|:count members', $household->users_count, ['count' => $household->users_count]) }} · {{ trans_choice(':count location|:count locations', $household->locations_count, ['count' => $household->locations_count]) }}</div>
    </div>
    <a class="btn btn-quiet" href="{{ route('inventory.web.households.show', $household) }}">{{ __('Open') }}</a>
  </div>
@empty
  <div class="card"><p class="muted">{{ __("You're not in any household yet.") }}</p></div>
@endforelse

<div class="card">
  <h2 style="font-size:16px;color:var(--text-heading);margin-bottom:14px">{{ __('Create a household') }}</h2>
  <form method="POST" action="{{ route('inventory.web.households.store') }}">
    @csrf
    <div class="row">
      <input class="grow" type="text" name="name" placeholder="{{ __('e.g. Home') }}" required style="margin-bottom:0">
      <button type="submit">{{ __('Create') }}</button>
    </div>
    @error('name') <p class="field-error">{{ $message }}</p> @enderror
  </form>
</div>

<div class="card">
  <h2 style="font-size:16px;color:var(--text-heading);margin-bottom:14px">{{ __('Join with a code') }}</h2>
  <form method="POST" action="{{ route('inventory.web.households.join') }}">
    @csrf
    <div class="row">
      <input class="grow mono" type="text" name="code" placeholder="ABCD-1234" required style="margin-bottom:0">
      <button type="submit">{{ __('Join') }}</button>
    </div>
    @error('code') <p class="field-error">{{ $message }}</p> @enderror
  </form>
</div>
@endsection
