@extends('inventory::web.layout')
@section('title', $household->name.' — '.__('Inventory'))
@section('content')
<h1>{{ $household->name }}</h1>
<p class="sub"><a href="{{ route('inventory.web.households') }}">← {{ __('All households') }}</a></p>

{{-- M7 (GAP-4): the page grew to seven equal-weight cards; light section
     headers + a set-apart danger zone give it back an information hierarchy
     without leaving the thin-Blade approach. --}}
<h2 class="section" id="storage">{{ __('Storage') }}</h2>
<div class="card">
  <form method="GET" action="{{ route('inventory.web.search', $household) }}" class="row">
    <input class="grow" type="text" name="q" placeholder="{{ __('Search products in this household…') }}" style="margin-bottom:0">
    <button type="submit">{{ __('Search') }}</button>
  </form>
</div>

<div class="card" id="locations"
     @can('restructure', $household)
     x-data="inventoryReorder({
        url: {{ Illuminate\Support\Js::from(route('inventory.web.locations.reorder', $household)) }},
        ids: {{ Illuminate\Support\Js::from($locations->pluck('id')->values()) }},
        errorMessage: {{ Illuminate\Support\Js::from(__("Location order didn't save — check your connection.")) }},
     })"
     @endcan
>
  <h2 style="font-size:16px;color:#b8d8f0;margin-bottom:14px">{{ __('Storage locations') }}</h2>
  {{-- Alpine reorder: `order` is a reactive copy of the id list; each row's
       visual position is driven by CSS `order` (index in that array), so
       moving a row is an instant, purely-visual optimistic swap before the
       background PATCH confirms it (spec: optimistic swap + visible revert
       on failure). No row is re-rendered/removed from the DOM, so nothing
       else about the row (links, counts) needs to be duplicated in JS. --}}
  @php $locationIds = $locations->pluck('id')->values()->all(); @endphp
  <div @can('restructure', $household) style="display:flex;flex-direction:column" @endcan>
  @forelse ($locations as $location)
    @php $i = $loop->index; @endphp
    <div class="row" style="padding:8px 0;border-bottom:1px solid rgba(125,211,252,.08)"
         @can('restructure', $household) :style="'order:' + order.indexOf({{ $location->id }})" @endcan
    >
      <div class="grow">
        <a href="{{ route('inventory.web.locations.show', [$household, $location]) }}">{{ $location->name }}</a>
        <span class="muted">({{ __(ucfirst($location->type->value)) }}, {{ trans_choice(':count shelf|:count shelves', $location->shelves_count, ['count' => $location->shelves_count]) }})</span>
      </div>
      @can('restructure', $household)
        <div class="row" style="gap:4px" x-cloak>
          <button type="button" class="btn-quiet" x-show="order.indexOf({{ $location->id }}) > 0"
                  @click="move({{ $location->id }}, -1)" aria-label="{{ __('Move up') }}">&uarr;</button>
          <button type="button" class="btn-quiet" x-show="order.indexOf({{ $location->id }}) < order.length - 1"
                  @click="move({{ $location->id }}, 1)" aria-label="{{ __('Move down') }}">&darr;</button>
        </div>
        {{-- Non-JS fallback: a plain spoofed-PATCH form per direction, whose
             hidden ids[] already encode the swapped order (computed here in
             PHP) — same endpoint and payload shape the Alpine path sends, no
             separate direction/id contract for the controller to support.
             <noscript> keeps these invisible (and non-interactive) whenever
             Alpine successfully loads and renders the buttons above. --}}
        <noscript>
          @if ($i > 0)
            @php $swapped = $locationIds; [$swapped[$i - 1], $swapped[$i]] = [$swapped[$i], $swapped[$i - 1]]; @endphp
            <form method="POST" action="{{ route('inventory.web.locations.reorder', $household) }}" style="display:inline">
              @csrf @method('PATCH')
              @foreach ($swapped as $id)<input type="hidden" name="ids[]" value="{{ $id }}">@endforeach
              <button type="submit" class="btn-quiet" aria-label="{{ __('Move up') }}">&uarr;</button>
            </form>
          @endif
          @if ($i < count($locationIds) - 1)
            @php $swapped = $locationIds; [$swapped[$i], $swapped[$i + 1]] = [$swapped[$i + 1], $swapped[$i]]; @endphp
            <form method="POST" action="{{ route('inventory.web.locations.reorder', $household) }}" style="display:inline">
              @csrf @method('PATCH')
              @foreach ($swapped as $id)<input type="hidden" name="ids[]" value="{{ $id }}">@endforeach
              <button type="submit" class="btn-quiet" aria-label="{{ __('Move down') }}">&darr;</button>
            </form>
          @endif
        </noscript>
      @endcan
      <a class="btn btn-quiet" href="{{ route('inventory.web.locations.show', [$household, $location]) }}">{{ __('Open') }}</a>
      {{-- T3: delete-location dialog, new on this page (previously only
           location.blade.php offered it). Same partial, target list is every
           OTHER location in this household (already loaded, no extra query). --}}
      @can('restructure', $household)
        @php
          $householdHasContents = $location->shelves_count > 0 || $location->products_count > 0;
          $householdMoveTargets = $locations->where('id', '!=', $location->id)
            ->map(fn ($l) => ['value' => $l->id, 'label' => $l->name])->values()->all();
        @endphp
        @if ($householdHasContents)
          <div x-cloak>
            @include('inventory::web.partials.delete-strategy-dialog', [
              'dialogTriggerLabel' => __('Delete'),
              'actionUrl' => route('inventory.web.locations.destroy', [$household, $location]),
              'summary' => trans_choice(':count shelf|:count shelves', $location->shelves_count, ['count' => $location->shelves_count]).', '.trans_choice(':count product|:count products', $location->products_count, ['count' => $location->products_count]),
              'options' => [
                ['value' => 'move_contents', 'label' => __('Move shelves to another location'), 'needsTarget' => true],
                ['value' => 'delete_contents', 'label' => __('Delete everything with it')],
              ],
              'targetFieldName' => 'target_location_id',
              'targets' => $householdMoveTargets,
            ])
          </div>
          <noscript>
            <form class="inline" method="POST" action="{{ route('inventory.web.locations.destroy', [$household, $location]) }}"
                  onsubmit="return confirm({{ Illuminate\Support\Js::from(__('Delete :name?', ['name' => $location->name])) }})">
              @csrf @method('DELETE')
              <input type="hidden" name="strategy" value="delete_contents">
              <button type="submit" class="btn-danger">{{ __('Delete') }}</button>
            </form>
          </noscript>
        @else
          <form class="inline" method="POST" action="{{ route('inventory.web.locations.destroy', [$household, $location]) }}"
                onsubmit="return confirm({{ Illuminate\Support\Js::from(__('Delete :name?', ['name' => $location->name])) }})">
            @csrf @method('DELETE')
            <button type="submit" class="btn-danger">{{ __('Delete') }}</button>
          </form>
        @endif
      @endcan
    </div>
  @empty
    <p class="muted">{{ __('No locations yet — add your first one below, e.g. Fridge or Pantry.') }}</p>
  @endforelse
  </div>

  <form method="POST" action="{{ route('inventory.web.locations.store', $household) }}" style="margin-top:14px">
    @csrf
    <div class="row">
      <input class="grow" type="text" name="name" placeholder="{{ __('e.g. Fridge') }}" required style="margin-bottom:0">
      <select name="type" required style="width:140px;margin-bottom:0">
        @foreach (\Spdotdev\Inventory\Enums\StorageType::cases() as $type)
          <option value="{{ $type->value }}">{{ __(ucfirst($type->value)) }}</option>
        @endforeach
      </select>
      <button type="submit">{{ __('Add location') }}</button>
    </div>
    @error('name') <p class="field-error">{{ $message }}</p> @enderror
    @error('type') <p class="field-error">{{ $message }}</p> @enderror
  </form>
</div>

<h2 class="section" id="members-section">{{ __('Members & access') }}</h2>
<div class="card">
  <h2 style="font-size:16px;color:#b8d8f0;margin-bottom:10px">{{ __('Invite someone') }}</h2>
  <p class="muted" style="margin-bottom:10px">{{ __('Share the code or the link — anyone with it can join.') }}</p>
  <p class="mono" style="font-size:22px;color:#7dd3fc;letter-spacing:2px;margin-bottom:8px">{{ $household->join_code }}</p>
  <p class="muted" style="word-break:break-all;margin-bottom:14px">{{ $inviteLink }}</p>
  {{-- Server-rendered QR (white tile so dark-mode scanners keep contrast) --}}
  <div style="background:#fff;border-radius:12px;padding:10px;width:fit-content">{!! $inviteQrSvg !!}</div>
</div>

<div class="card" id="members">
  <h2 style="font-size:16px;color:#b8d8f0;margin-bottom:14px">{{ __('Members') }}</h2>
  <div class="table-scroll">
  <table>
    <tr><th>{{ __('Name') }}</th><th>{{ __('Email') }}</th><th>{{ __('Role') }}</th>@can('manageMembers', $household)<th></th>@endcan</tr>
    @foreach ($members as $member)
      <tr>
        <td>{{ $member->name }}@if ($member->id === auth('inventory')->id()) <span class="muted">({{ __('you') }})</span>@endif</td>
        <td class="muted">{{ $member->email }}</td>
        <td class="mono">{{ __(ucfirst($member->pivot->role)) }}</td>
        @can('manageMembers', $household)
          <td>
            {{-- No actions on the owner's row (untouchable except via transfer)
                 or on your own row — self role-changes are surprising; another
                 admin or a transfer handles those. --}}
            @if ($member->pivot->role !== 'owner' && $member->id !== auth('inventory')->id())
              <form method="POST" action="{{ route('inventory.web.members.update', [$household, $member]) }}" class="row" style="margin-bottom:0">
                @csrf @method('PUT')
                <input type="hidden" name="role" value="{{ $member->pivot->role === 'admin' ? 'member' : 'admin' }}">
                <button type="submit" class="btn-quiet">{{ $member->pivot->role === 'admin' ? __('Demote') : __('Promote') }}</button>
              </form>
              <form method="POST" action="{{ route('inventory.web.members.remove', [$household, $member]) }}"
                    onsubmit="return confirm({{ Illuminate\Support\Js::from(__('Remove :name?', ['name' => $member->name])) }})" style="margin-bottom:0">
                @csrf @method('DELETE')
                <button type="submit" class="btn-danger">{{ __('Remove') }}</button>
              </form>
            @endif
          </td>
        @endcan
      </tr>
    @endforeach
  </table>
  </div>
</div>

<h2 class="section">{{ __('Household') }}</h2>
<div class="card" id="appearance">
  <h2 style="font-size:16px;color:#b8d8f0;margin-bottom:10px">{{ __('Appearance') }}</h2>
  <p class="muted" style="margin-bottom:14px">{{ __('Pick a colour and icon for this household in the apps — “Default” derives one automatically.') }}</p>
  <form method="POST" action="{{ route('inventory.web.households.update', $household) }}" class="row">
    @csrf @method('PUT')
    <select name="color" style="width:150px;margin-bottom:0">
      <option value="">{{ __('Default colour') }}</option>
      @foreach (\Spdotdev\Inventory\Enums\HouseholdColor::cases() as $color)
        <option value="{{ $color->value }}" @selected($household->color === $color->value)>{{ __(ucfirst($color->value)) }}</option>
      @endforeach
    </select>
    <select name="icon" style="width:150px;margin-bottom:0">
      <option value="">{{ __('Default icon') }}</option>
      @foreach (\Spdotdev\Inventory\Enums\HouseholdIcon::cases() as $icon)
        <option value="{{ $icon->value }}" @selected($household->icon === $icon->value)>{{ __(ucfirst($icon->value)) }}</option>
      @endforeach
    </select>
    <button type="submit">{{ __('Save') }}</button>
  </form>
</div>

<div class="card">
  <h2 style="font-size:16px;color:#b8d8f0;margin-bottom:10px">{{ __('Your data') }}</h2>
  <p class="muted" style="margin-bottom:14px">{{ __('Download everything in this household — locations, shelves, products and members — as a JSON file.') }}</p>
  <a class="btn btn-quiet" href="{{ route('inventory.web.households.export', $household) }}">{{ __('Download export') }}</a>
</div>

<h2 class="section" style="color:#f0b8b8">{{ __('Danger zone') }}</h2>
<div class="card" id="danger" style="border-color:rgba(240,120,120,.35)">
@can('transferOwnership', $household)
  <div style="margin-bottom:18px">
  <h2 style="font-size:16px;color:#b8d8f0;margin-bottom:10px">{{ __('Transfer ownership') }}</h2>
  <p class="muted" style="margin-bottom:14px">{{ __("Make another member the owner. You'll become an admin.") }}</p>
  <form method="POST" action="{{ route('inventory.web.households.transfer-ownership', $household) }}" class="row"
        onsubmit="return confirm({{ Illuminate\Support\Js::from(__('Transfer ownership of :name to', ['name' => $household->name])) }} + ' ' + this.user_id.options[this.user_id.selectedIndex].text + {{ Illuminate\Support\Js::from('? '.__('You will become an admin and only they can transfer it back.')) }})">
    @csrf
    <select name="user_id" required style="width:220px;margin-bottom:0">
      @foreach ($members as $member)
        @if ($member->pivot->role !== 'owner')
          <option value="{{ $member->id }}">{{ $member->name }}</option>
        @endif
      @endforeach
    </select>
    <button type="submit">{{ __('Transfer') }}</button>
  </form>
  @error('user_id') <p class="field-error">{{ $message }}</p> @enderror
  </div>
@endcan
  <form method="POST" action="{{ route('inventory.web.households.leave', $household) }}"
      onsubmit="return confirm({{ Illuminate\Support\Js::from(__('Leave :name?', ['name' => $household->name])) }})">
  @csrf
  @method('DELETE')
  <button type="submit" class="btn-danger">{{ __('Leave household') }}</button>
</form>

  @can('delete', $household)
    <form method="POST" action="{{ route('inventory.web.households.destroy', $household) }}" style="margin-top:18px">
      @csrf @method('DELETE')
      {{-- Server-verified typed-name confirmation: deleting a household destroys
           every member's data, so the friction is enforced server-side too.
           Field is named confirm_name (not name) so its error doesn't collide
           with the location-add form's `name` error on the same page — both
           validate a field called "name" server-side otherwise (GAP-4 L4). --}}
      <div class="row">
        <input class="grow" type="text" name="confirm_name" required style="margin-bottom:0"
               placeholder="{{ __('Type “:name” to confirm', ['name' => $household->name]) }}">
        <button type="submit" class="btn-danger">{{ __('Delete household forever') }}</button>
      </div>
      @error('confirm_name') <p class="field-error">{{ $message }}</p> @enderror
    </form>
  @endcan
</div>

@include('inventory::web.partials.live-updates', ['household' => $household])
@endsection
