@extends('inventory::web.layout')
@section('title', __('Search').' — '.$household->name.' — '.__('Inventory'))
@section('content')
<h1>{{ __('Search :name', ['name' => $household->name]) }}</h1>
<p class="sub"><a href="{{ route('inventory.web.households.show', $household) }}">← {{ $household->name }}</a></p>

<div class="card">
  <form method="GET" action="{{ route('inventory.web.search', $household) }}" class="row">
    <input class="grow" type="text" name="q" value="{{ $q }}" placeholder="{{ __('Search products…') }}" autofocus style="margin-bottom:0">
    <button type="submit">{{ __('Search') }}</button>
  </form>
</div>

<div class="card">
  @if ($products->isNotEmpty())
    <table>
      <tr><th>{{ __('Product') }}</th><th>{{ __('Where') }}</th><th style="width:80px;text-align:right">{{ __('Qty') }}</th></tr>
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
      <p class="muted" style="margin-top:12px">{{ __('Showing the first 50 matches — refine the search to narrow down.') }}</p>
    @endif
  @elseif ($q !== '')
    <p class="muted">{{ __('No products match ":query".', ['query' => $q]) }}</p>
  @else
    <p class="muted">{{ __('Type a product name to search this household.') }}</p>
  @endif
</div>
@endsection
