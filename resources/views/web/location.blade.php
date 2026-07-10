@extends('inventory::web.layout')
@section('title', $location->name.' — Inventory')
@section('content')
<h1>{{ $location->name }} <span class="muted" style="font-size:14px">({{ $location->type }})</span></h1>
<p class="sub"><a href="{{ route('inventory.web.households.show', $household) }}">← {{ $household->name }}</a></p>

@foreach ($shelves as $shelf)
  <div class="card">
    <div class="row" style="margin-bottom:12px">
      <h2 class="grow" style="font-size:16px;color:#b8d8f0">{{ $shelf->name }}</h2>
      <form class="inline" method="POST"
            action="{{ route('inventory.web.shelves.destroy', [$household, $location, $shelf]) }}"
            onsubmit="return confirm('Delete shelf {{ $shelf->name }} and all its products?')">
        @csrf @method('DELETE')
        <button type="submit" class="btn-danger">Delete shelf</button>
      </form>
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
                    onsubmit="return confirm('Delete {{ $product->name }}?')">
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

<form method="POST" action="{{ route('inventory.web.locations.destroy', [$household, $location]) }}"
      onsubmit="return confirm('Delete {{ $location->name }} with all shelves and products?')">
  @csrf @method('DELETE')
  <button type="submit" class="btn-danger">Delete location</button>
</form>
@endsection
