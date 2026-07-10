@extends('inventory::web.layout')
@section('title', 'Search — '.$household->name.' — Inventory')
@section('content')
<h1>Search {{ $household->name }}</h1>
<p class="sub"><a href="{{ route('inventory.web.households.show', $household) }}">← {{ $household->name }}</a></p>

<div class="card">
  <form method="GET" action="{{ route('inventory.web.search', $household) }}" class="row">
    <input class="grow" type="text" name="q" value="{{ $q }}" placeholder="Search products…" autofocus style="margin-bottom:0">
    <button type="submit">Search</button>
  </form>
</div>

<div class="card">
  @if ($products->isNotEmpty())
    <table>
      <tr><th>Product</th><th>Where</th><th style="width:80px;text-align:right">Qty</th></tr>
      @foreach ($products as $product)
        <tr>
          <td>
            {{ $product->name }}
            @if ($product->code)<div class="muted mono" style="font-size:11px">{{ $product->code }}</div>@endif
          </td>
          <td>
            <a href="{{ route('inventory.web.locations.show', [$household, $product->shelf->location]) }}">{{ $product->shelf->location->name }}</a>
            <span class="muted">› {{ $product->shelf->name }}</span>
          </td>
          <td class="mono" style="text-align:right">{{ $product->quantity }}</td>
        </tr>
      @endforeach
    </table>
    @if ($products->count() === 50)
      <p class="muted" style="margin-top:12px">Showing the first 50 matches — refine the search to narrow down.</p>
    @endif
  @elseif ($q !== '')
    <p class="muted">No products match “{{ $q }}”.</p>
  @else
    <p class="muted">Type a product name to search this household.</p>
  @endif
</div>
@endsection
