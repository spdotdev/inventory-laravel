@extends('inventory::web.layout')
@section('title', $location->name.' — '.__('Inventory'))
@section('content')
<h1>{{ $location->name }} <span class="muted" style="font-size:14px">({{ __(ucfirst($location->type->value)) }})</span></h1>
<p class="sub"><a href="{{ route('inventory.web.households.show', $household) }}">← {{ $household->name }}</a></p>

{{-- Web parity Task 2: reorder. `order` covers non-system shelves only —
     the Unsorted shelf is never draggable and always renders last (matches
     Reorderer::shelves / the API's is_system exclusion). Each card binds
     `:style="'order:' + ..."` on a flex container so a move is a purely
     visual, instant reorder (optimistic swap) with no row re-rendered. --}}
@php $shelfIds = $shelves->where('is_system', false)->pluck('id')->values()->all(); @endphp
<div @can('restructure', $household)
     x-data="inventoryReorder({
        url: {{ Illuminate\Support\Js::from(route('inventory.web.shelves.reorder', [$household, $location])) }},
        ids: {{ Illuminate\Support\Js::from($shelfIds) }},
        errorMessage: {{ Illuminate\Support\Js::from(__("Shelf order didn't save — check your connection.")) }},
     })"
     style="display:flex;flex-direction:column"
     @endcan
>
@foreach ($shelves as $shelf)
  @php $i = $loop->index; @endphp
  <div class="card"
       @can('restructure', $household) :style="'order:' + {{ $shelf->is_system ? 99999 : "order.indexOf({$shelf->id})" }}" @endcan
  >
    <div class="row" style="margin-bottom:12px">
      <h2 class="grow" style="font-size:16px;color:var(--text-heading)">{{ $shelf->name }}</h2>
      @unless ($shelf->is_system)
        @can('restructure', $household)
          <div class="row" style="gap:4px" x-cloak>
            <button type="button" class="btn-quiet" x-show="order.indexOf({{ $shelf->id }}) > 0"
                    @click="move({{ $shelf->id }}, -1)" aria-label="{{ __('Move up') }}">&uarr;</button>
            <button type="button" class="btn-quiet" x-show="order.indexOf({{ $shelf->id }}) < order.length - 1"
                    @click="move({{ $shelf->id }}, 1)" aria-label="{{ __('Move down') }}">&darr;</button>
          </div>
          {{-- Non-JS fallback — same shape as household.blade.php's location
               rows: a spoofed-PATCH form per direction carrying the
               pre-swapped full ids[] list, hidden by <noscript> once Alpine
               renders the buttons above. --}}
          @php $shelfIndex = array_search($shelf->id, $shelfIds, true); @endphp
          <noscript>
            @if ($shelfIndex !== false && $shelfIndex > 0)
              @php $swapped = $shelfIds; [$swapped[$shelfIndex - 1], $swapped[$shelfIndex]] = [$swapped[$shelfIndex], $swapped[$shelfIndex - 1]]; @endphp
              <form method="POST" action="{{ route('inventory.web.shelves.reorder', [$household, $location]) }}" style="display:inline">
                @csrf @method('PATCH')
                @foreach ($swapped as $id)<input type="hidden" name="ids[]" value="{{ $id }}">@endforeach
                <button type="submit" class="btn-quiet" aria-label="{{ __('Move up') }}">&uarr;</button>
              </form>
            @endif
            @if ($shelfIndex !== false && $shelfIndex < count($shelfIds) - 1)
              @php $swapped = $shelfIds; [$swapped[$shelfIndex], $swapped[$shelfIndex + 1]] = [$swapped[$shelfIndex + 1], $swapped[$shelfIndex]]; @endphp
              <form method="POST" action="{{ route('inventory.web.shelves.reorder', [$household, $location]) }}" style="display:inline">
                @csrf @method('PATCH')
                @foreach ($swapped as $id)<input type="hidden" name="ids[]" value="{{ $id }}">@endforeach
                <button type="submit" class="btn-quiet" aria-label="{{ __('Move down') }}">&darr;</button>
              </form>
            @endif
          </noscript>
        @endcan
        @if ($shelf->products->isNotEmpty())
          {{-- T3: full delete-strategy dialog — move_products (target: any
               other non-system shelf in the household) joins the previous
               unsort/delete choices. Safest option (keep here, unsorted)
               stays the default per Android's DeleteStrategyDialog semantics
               — never default to destruction. --}}
          @php
            $shelfMoveTargets = $allShelves->where('id', '!=', $shelf->id)
              ->map(fn ($s) => ['value' => $s->id, 'label' => $s->location->name.' — '.$s->name])
              ->values()->all();
          @endphp
          <div x-cloak>
            @include('inventory::web.partials.delete-strategy-dialog', [
              'dialogTriggerLabel' => __('Delete shelf'),
              'actionUrl' => route('inventory.web.shelves.destroy', [$household, $location, $shelf]),
              'summary' => trans_choice(':count product on this shelf|:count products on this shelf', $shelf->products->count(), ['count' => $shelf->products->count()]),
              'options' => [
                ['value' => 'unsort_products', 'label' => __('Keep products here (move to Unsorted)')],
                ['value' => 'move_products', 'label' => __('Move products to another shelf'), 'needsTarget' => true],
                ['value' => 'delete_products', 'label' => __('Delete the products too')],
              ],
              'targetFieldName' => 'target_shelf_id',
              'targets' => $shelfMoveTargets,
            ])
          </div>
          {{-- Non-JS fallback: the previous unsort/delete subset (no
               move_products — it needs the JS-driven target picker above).
               Hidden by <noscript> whenever Alpine renders the dialog. --}}
          <noscript>
            <form class="inline" method="POST"
                  action="{{ route('inventory.web.shelves.destroy', [$household, $location, $shelf]) }}"
                  onsubmit="return confirm({{ Illuminate\Support\Js::from(__('Delete shelf :name?', ['name' => $shelf->name])) }})">
              @csrf @method('DELETE')
              <select name="strategy" style="width:auto;margin-bottom:0">
                <option value="unsort_products" selected>{{ __('Keep products here (move to Unsorted)') }}</option>
                <option value="delete_products">{{ __('Delete the products too') }}</option>
              </select>
              <button type="submit" class="btn-danger">{{ __('Delete shelf') }}</button>
            </form>
          </noscript>
        @else
          <form class="inline" method="POST"
                action="{{ route('inventory.web.shelves.destroy', [$household, $location, $shelf]) }}"
                onsubmit="return confirm({{ Illuminate\Support\Js::from(__('Delete shelf :name?', ['name' => $shelf->name])) }})">
            @csrf @method('DELETE')
            <button type="submit" class="btn-danger">{{ __('Delete shelf') }}</button>
          </form>
        @endif
      @endunless
    </div>

    @if ($shelf->products->isNotEmpty())
      <table>
        <tr><th>{{ __('Product') }}</th><th style="width:180px">{{ __('Quantity') }}</th><th style="width:180px"></th></tr>
        @foreach ($shelf->products as $product)
          <tr>
            <td>
              {{ $product->name }}
              @if ($product->is_mandatory && $product->quantity === 0)
                <span style="color:var(--danger-text)"> · {{ __('missing') }}</span>
              @elseif ($product->low_stock_threshold !== null && $product->quantity <= $product->low_stock_threshold)
                <span style="color:var(--warning-text)"> · {{ __('running low') }}</span>
              @endif
              @if ($product->code)<div class="muted mono" style="font-size:11px">{{ $product->code }}</div>@endif
            </td>
            <td>
              <div class="row">
                <form class="inline" method="POST" action="{{ route('inventory.web.products.remove', [$household, $shelf, $product]) }}">
                  @csrf<button type="submit" class="btn-quiet">−</button>
                </form>
                <span class="mono" style="min-width:32px;text-align:center">{{ $product->quantity }}</span>
                <form class="inline" method="POST" action="{{ route('inventory.web.products.add', [$household, $shelf, $product]) }}">
                  @csrf<button type="submit" class="btn-quiet">+</button>
                </form>
              </div>
            </td>
            <td style="text-align:right">
              <a class="btn btn-quiet" href="{{ route('inventory.web.products.edit', [$household, $shelf, $product]) }}">{{ __('Edit') }}</a>
              <form class="inline" method="POST"
                    action="{{ route('inventory.web.products.destroy', [$household, $shelf, $product]) }}"
                    onsubmit="return confirm({{ Illuminate\Support\Js::from(__('Delete :name?', ['name' => $product->name])) }})">
                @csrf @method('DELETE')
                <button type="submit" class="btn-danger">{{ __('Delete') }}</button>
              </form>
            </td>
          </tr>
        @endforeach
      </table>
    @else
      <p class="muted">{{ __('No products on this shelf yet.') }}</p>
    @endif

    <form method="POST" action="{{ route('inventory.web.products.store', [$household, $shelf]) }}" class="row" style="margin-top:14px">
      @csrf
      <input class="grow" type="text" name="name" placeholder="{{ __('Add a product…') }}" required style="margin-bottom:0">
      <button type="submit">{{ __('Add') }}</button>
    </form>
  </div>
@endforeach
</div>

<div class="card">
  <h2 style="font-size:16px;color:var(--text-heading);margin-bottom:14px">{{ __('Add a shelf') }}</h2>
  <form method="POST" action="{{ route('inventory.web.shelves.store', [$household, $location]) }}" class="row">
    @csrf
    <input class="grow" type="text" name="name" placeholder="{{ __('e.g. Top shelf') }}" required style="margin-bottom:0">
    <button type="submit">{{ __('Add shelf') }}</button>
  </form>
</div>

@php
  // T3: move_contents (target: another location in this household) joins
  // delete_contents. Unsort is deliberately still absent here — "unsorted"
  // means off-shelf but still IN this location, and the location is the
  // thing being deleted (see LocationDeleteStrategy's docblock).
  $shelfCount = $shelves->where('is_system', false)->count();
  $productCount = $shelves->sum(fn ($s) => $s->products->count());
  $hasContents = $shelfCount > 0 || $productCount > 0;
  $locationMoveTargets = $otherLocations->map(fn ($l) => ['value' => $l->id, 'label' => $l->name])->values()->all();
@endphp
@if ($hasContents)
  <div x-cloak>
    @include('inventory::web.partials.delete-strategy-dialog', [
      'dialogTriggerLabel' => __('Delete location'),
      'actionUrl' => route('inventory.web.locations.destroy', [$household, $location]),
      'summary' => trans_choice(':count shelf|:count shelves', $shelfCount, ['count' => $shelfCount]).', '.trans_choice(':count product|:count products', $productCount, ['count' => $productCount]),
      'options' => [
        ['value' => 'move_contents', 'label' => __('Move shelves to another location'), 'needsTarget' => true],
        ['value' => 'delete_contents', 'label' => __('Delete everything with it')],
      ],
      'targetFieldName' => 'target_location_id',
      'targets' => $locationMoveTargets,
    ])
  </div>
  <noscript>
    <form method="POST" action="{{ route('inventory.web.locations.destroy', [$household, $location]) }}"
          onsubmit="return confirm({{ Illuminate\Support\Js::from(__('Delete :name with', ['name' => $location->name])) }} + ' ' + {{ Illuminate\Support\Js::from(trans_choice(':count shelf|:count shelves', $shelfCount, ['count' => $shelfCount])) }} + ' ' + {{ Illuminate\Support\Js::from(__('and')) }} + ' ' + {{ Illuminate\Support\Js::from(trans_choice(':count product|:count products', $productCount, ['count' => $productCount])) }} + '?')">
      @csrf @method('DELETE')
      <input type="hidden" name="strategy" value="delete_contents">
      <button type="submit" class="btn-danger">{{ __('Delete location') }}</button>
    </form>
  </noscript>
@else
  <form method="POST" action="{{ route('inventory.web.locations.destroy', [$household, $location]) }}"
        onsubmit="return confirm({{ Illuminate\Support\Js::from(__('Delete :name?', ['name' => $location->name])) }})">
    @csrf @method('DELETE')
    <button type="submit" class="btn-danger">{{ __('Delete location') }}</button>
  </form>
@endif

@include('inventory::web.partials.live-updates', ['household' => $household])
@endsection
