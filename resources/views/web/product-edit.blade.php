@extends('inventory::web.layout')
@section('title', 'Edit '.$product->name.' — Inventory')
@section('content')
<h1>Edit {{ $product->name }}</h1>
<p class="sub"><a href="{{ route('inventory.web.locations.show', [$household, $shelf->location_id]) }}">← Back to {{ $shelf->name }}</a></p>

<div class="card" style="max-width:520px">
  <form method="POST" action="{{ route('inventory.web.products.update', [$household, $shelf, $product]) }}">
    @csrf @method('PUT')

    <label for="name">Name</label>
    <input type="text" id="name" name="name" value="{{ old('name', $product->name) }}" required>

    <label for="description">Description</label>
    <input type="text" id="description" name="description" value="{{ old('description', $product->description) }}">

    <label for="code">Code / barcode</label>
    <input type="text" id="code" name="code" class="mono" value="{{ old('code', $product->code) }}">

    <label for="low_stock_threshold">Low-stock warning at (empty = off)</label>
    <input type="number" id="low_stock_threshold" name="low_stock_threshold" min="1"
           value="{{ old('low_stock_threshold', $product->low_stock_threshold) }}">

    <label style="display:flex;gap:10px;align-items:center;margin-bottom:20px">
      <input type="checkbox" name="is_mandatory" value="1" style="width:auto;margin:0"
             @checked(old('is_mandatory', $product->is_mandatory))>
      Should always be stocked (missing when at 0)
    </label>

    <button type="submit" style="width:100%">Save</button>
  </form>
</div>
@endsection
