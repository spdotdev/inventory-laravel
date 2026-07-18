@extends('inventory::web.layout')
@section('title', $location->name.' — Inventory')
@section('content')
<h1>{{ $location->name }} <span class="muted" style="font-size:14px">({{ $location->type }})</span></h1>
<p class="sub"><a href="{{ route('inventory.web.households.show', $household) }}">← {{ $household->name }}</a></p>

@foreach ($shelves as $shelf)
  <div class="card">
    <div class="row" style="margin-bottom:12px">
      <h2 class="grow" style="font-size:16px;color:#b8d8f0">{{ $shelf->name }}</h2>
      @unless ($shelf->is_system)
        <form class="inline" method="POST"
              action="{{ route('inventory.web.shelves.destroy', [$household, $location, $shelf]) }}"
              onsubmit="return confirm({{ Illuminate\Support\Js::from('Delete shelf '.$shelf->name.'?') }})">
          @csrf @method('DELETE')
          @if ($shelf->products->isNotEmpty())
            {{-- H5: move_products is deliberately NOT offered here — it needs a
                 target-shelf picker, disproportionate for a thin-Blade form.
                 Android's LOCKED delete dialog still exposes it. Default is
                 the non-destructive choice (unsort), never delete. --}}
            <select name="strategy" style="width:auto;margin-bottom:0">
              <option value="unsort_products" selected>{{ __('Keep products here (move to Unsorted)') }}</option>
              <option value="delete_products">{{ __('Delete the products too') }}</option>
            </select>
          @endif
          <button type="submit" class="btn-danger">Delete shelf</button>
        </form>
      @endunless
    </div>

    @if ($shelf->products->isNotEmpty())
      <table>
        <tr><th>Product</th><th style="width:180px">Quantity</th><th style="width:180px"></th></tr>
        @foreach ($shelf->products as $product)
          <tr>
            <td>
              {{ $product->name }}
              @if ($product->is_mandatory && $product->quantity === 0)
                <span style="color:#fca5a5"> · missing</span>
              @elseif ($product->low_stock_threshold !== null && $product->quantity <= $product->low_stock_threshold)
                <span style="color:#fbbf24"> · running low</span>
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
              <a class="btn btn-quiet" href="{{ route('inventory.web.products.edit', [$household, $shelf, $product]) }}">Edit</a>
              <form class="inline" method="POST"
                    action="{{ route('inventory.web.products.destroy', [$household, $shelf, $product]) }}"
                    onsubmit="return confirm({{ Illuminate\Support\Js::from('Delete '.$product->name.'?') }})">
                @csrf @method('DELETE')
                <button type="submit" class="btn-danger">Delete</button>
              </form>
            </td>
          </tr>
        @endforeach
      </table>
    @else
      <p class="muted">No products on this shelf yet.</p>
    @endif

    <form method="POST" action="{{ route('inventory.web.products.store', [$household, $shelf]) }}" class="row" style="margin-top:14px">
      @csrf
      <input class="grow" type="text" name="name" placeholder="Add a product…" required style="margin-bottom:0">
      <button type="submit">Add</button>
    </form>
  </div>
@endforeach

<div class="card">
  <h2 style="font-size:16px;color:#b8d8f0;margin-bottom:14px">Add a shelf</h2>
  <form method="POST" action="{{ route('inventory.web.shelves.store', [$household, $location]) }}" class="row">
    @csrf
    <input class="grow" type="text" name="name" placeholder="e.g. Top shelf" required style="margin-bottom:0">
    <button type="submit">Add shelf</button>
  </form>
</div>

@php
  // H5: no strategy PICKER at location level — unsort is shelf-only, and
  // move_contents needs a target-location picker that's out of scope for
  // thin-Blade. delete_contents is the only choice, so instead of a select
  // with one option, the confirm copy states exactly what will be destroyed
  // so this is still an informed action rather than a silent default.
  $shelfCount = $shelves->where('is_system', false)->count();
  $productCount = $shelves->sum(fn ($s) => $s->products->count());
@endphp
<form method="POST" action="{{ route('inventory.web.locations.destroy', [$household, $location]) }}"
      onsubmit="return confirm({{ Illuminate\Support\Js::from('Delete '.$location->name.' with '.$shelfCount.' '.Str::plural('shelf', $shelfCount).' and '.$productCount.' '.Str::plural('product', $productCount).'?') }})">
  @csrf @method('DELETE')
  <input type="hidden" name="strategy" value="delete_contents">
  <button type="submit" class="btn-danger">Delete location</button>
</form>

@include('inventory::web.partials.live-updates', ['household' => $household])
@endsection
