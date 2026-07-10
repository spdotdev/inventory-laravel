@extends('inventory::web.layout')
@section('title', $household->name.' — Inventory')
@section('content')
<h1>{{ $household->name }}</h1>
<p class="sub"><a href="{{ route('inventory.web.households') }}">← All households</a></p>

<div class="card">
  <h2 style="font-size:16px;color:#b8d8f0;margin-bottom:10px">Invite someone</h2>
  <p class="muted" style="margin-bottom:10px">Share the code or the link — anyone with it can join.</p>
  <p class="mono" style="font-size:22px;color:#7dd3fc;letter-spacing:2px;margin-bottom:8px">{{ $household->join_code }}</p>
  <p class="muted" style="word-break:break-all">{{ $inviteLink }}</p>
</div>

<div class="card">
  <h2 style="font-size:16px;color:#b8d8f0;margin-bottom:14px">Members</h2>
  <table>
    <tr><th>Name</th><th>Email</th></tr>
    @foreach ($members as $member)
      <tr><td>{{ $member->name }}</td><td class="muted">{{ $member->email }}</td></tr>
    @endforeach
  </table>
</div>

<div class="card">
  <h2 style="font-size:16px;color:#b8d8f0;margin-bottom:14px">Storage locations</h2>
  @forelse ($locations as $location)
    <div class="row" style="padding:8px 0;border-bottom:1px solid rgba(125,211,252,.08)">
      <div class="grow">{{ $location->name }} <span class="muted">({{ $location->type }}, {{ $location->shelves_count }} {{ Str::plural('shelf', $location->shelves_count) }})</span></div>
    </div>
  @empty
    <p class="muted">No locations yet — add them in the app for now.</p>
  @endforelse
</div>

<form method="POST" action="{{ route('inventory.web.households.leave', $household) }}"
      onsubmit="return confirm('Leave {{ $household->name }}?')">
  @csrf
  @method('DELETE')
  <button type="submit" class="btn-danger">Leave household</button>
</form>
@endsection
