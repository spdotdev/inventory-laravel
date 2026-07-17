@extends('inventory::web.layout')
@section('title', $household->name.' — Inventory')
@section('content')
<h1>{{ $household->name }}</h1>
<p class="sub"><a href="{{ route('inventory.web.households') }}">← All households</a></p>

<div class="card">
  <form method="GET" action="{{ route('inventory.web.search', $household) }}" class="row">
    <input class="grow" type="text" name="q" placeholder="Search products in this household…" style="margin-bottom:0">
    <button type="submit">Search</button>
  </form>
</div>

<div class="card">
  <h2 style="font-size:16px;color:#b8d8f0;margin-bottom:10px">Invite someone</h2>
  <p class="muted" style="margin-bottom:10px">Share the code or the link — anyone with it can join.</p>
  <p class="mono" style="font-size:22px;color:#7dd3fc;letter-spacing:2px;margin-bottom:8px">{{ $household->join_code }}</p>
  <p class="muted" style="word-break:break-all;margin-bottom:14px">{{ $inviteLink }}</p>
  {{-- Server-rendered QR (white tile so dark-mode scanners keep contrast) --}}
  <div style="background:#fff;border-radius:12px;padding:10px;width:fit-content">{!! $inviteQrSvg !!}</div>
</div>

<div class="card">
  <h2 style="font-size:16px;color:#b8d8f0;margin-bottom:14px">Members</h2>
  <table>
    <tr><th>Name</th><th>Email</th><th>Role</th>@can('manageMembers', $household)<th></th>@endcan</tr>
    @foreach ($members as $member)
      <tr>
        <td>{{ $member->name }}</td>
        <td class="muted">{{ $member->email }}</td>
        <td class="mono">{{ ucfirst($member->pivot->role) }}</td>
        @can('manageMembers', $household)
          <td>
            @if ($member->pivot->role !== 'owner')
              <form method="POST" action="{{ route('inventory.web.members.update', [$household, $member]) }}" class="row" style="margin-bottom:0">
                @csrf @method('PUT')
                <input type="hidden" name="role" value="{{ $member->pivot->role === 'admin' ? 'member' : 'admin' }}">
                <button type="submit" class="btn-quiet">{{ $member->pivot->role === 'admin' ? 'Demote' : 'Promote' }}</button>
              </form>
              <form method="POST" action="{{ route('inventory.web.members.remove', [$household, $member]) }}"
                    onsubmit="return confirm({{ Illuminate\Support\Js::from('Remove '.$member->name.'?') }})" style="margin-bottom:0">
                @csrf @method('DELETE')
                <button type="submit" class="btn-danger">Remove</button>
              </form>
            @endif
          </td>
        @endcan
      </tr>
    @endforeach
  </table>
</div>

@can('transferOwnership', $household)
<div class="card">
  <h2 style="font-size:16px;color:#b8d8f0;margin-bottom:10px">Transfer ownership</h2>
  <p class="muted" style="margin-bottom:14px">Make another member the owner. You'll become an admin.</p>
  <form method="POST" action="{{ route('inventory.web.households.transfer-ownership', $household) }}" class="row">
    @csrf
    <select name="user_id" required style="width:220px;margin-bottom:0">
      @foreach ($members as $member)
        @if ($member->pivot->role !== 'owner')
          <option value="{{ $member->id }}">{{ $member->name }}</option>
        @endif
      @endforeach
    </select>
    <button type="submit">Transfer</button>
  </form>
</div>
@endcan

<div class="card">
  <h2 style="font-size:16px;color:#b8d8f0;margin-bottom:14px">Storage locations</h2>
  @forelse ($locations as $location)
    <div class="row" style="padding:8px 0;border-bottom:1px solid rgba(125,211,252,.08)">
      <div class="grow">
        <a href="{{ route('inventory.web.locations.show', [$household, $location]) }}">{{ $location->name }}</a>
        <span class="muted">({{ $location->type }}, {{ $location->shelves_count }} {{ Str::plural('shelf', $location->shelves_count) }})</span>
      </div>
      <a class="btn btn-quiet" href="{{ route('inventory.web.locations.show', [$household, $location]) }}">Open</a>
    </div>
  @empty
    <p class="muted">No locations yet.</p>
  @endforelse

  <form method="POST" action="{{ route('inventory.web.locations.store', $household) }}" class="row" style="margin-top:14px">
    @csrf
    <input class="grow" type="text" name="name" placeholder="e.g. Fridge" required style="margin-bottom:0">
    <select name="type" required style="width:140px;margin-bottom:0">
      @foreach (\Spdotdev\Inventory\Enums\StorageType::cases() as $type)
        <option value="{{ $type->value }}">{{ ucfirst($type->value) }}</option>
      @endforeach
    </select>
    <button type="submit">Add location</button>
  </form>
</div>

<div class="card">
  <h2 style="font-size:16px;color:#b8d8f0;margin-bottom:10px">Appearance</h2>
  <p class="muted" style="margin-bottom:14px">Pick a colour and icon for this household in the apps — “Default” derives one automatically.</p>
  <form method="POST" action="{{ route('inventory.web.households.update', $household) }}" class="row">
    @csrf @method('PUT')
    <select name="color" style="width:150px;margin-bottom:0">
      <option value="">Default colour</option>
      @foreach (\Spdotdev\Inventory\Enums\HouseholdColor::cases() as $color)
        <option value="{{ $color->value }}" @selected($household->color === $color->value)>{{ ucfirst($color->value) }}</option>
      @endforeach
    </select>
    <select name="icon" style="width:150px;margin-bottom:0">
      <option value="">Default icon</option>
      @foreach (\Spdotdev\Inventory\Enums\HouseholdIcon::cases() as $icon)
        <option value="{{ $icon->value }}" @selected($household->icon === $icon->value)>{{ ucfirst($icon->value) }}</option>
      @endforeach
    </select>
    <button type="submit">Save</button>
  </form>
</div>

<div class="card">
  <h2 style="font-size:16px;color:#b8d8f0;margin-bottom:10px">Your data</h2>
  <p class="muted" style="margin-bottom:14px">Download everything in this household — locations, shelves, products and members — as a JSON file.</p>
  <a class="btn btn-quiet" href="{{ route('inventory.web.households.export', $household) }}">Download export</a>
</div>

<form method="POST" action="{{ route('inventory.web.households.leave', $household) }}"
      onsubmit="return confirm({{ Illuminate\Support\Js::from('Leave '.$household->name.'?') }})">
  @csrf
  @method('DELETE')
  <button type="submit" class="btn-danger">Leave household</button>
</form>

@include('inventory::web.partials.live-updates', ['household' => $household])
@endsection
