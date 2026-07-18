@extends('inventory::web.layout')
@section('title', __('Edit :name', ['name' => $product->name]).' — '.__('Inventory'))
@section('content')
<h1>{{ __('Edit :name', ['name' => $product->name]) }}</h1>
<p class="sub"><a href="{{ route('inventory.web.locations.show', [$household, $shelf->location_id]) }}">← {{ __('Back to :shelf', ['shelf' => $shelf->name]) }}</a></p>

<div class="card" style="max-width:520px">
  @if ($product->image_url)
    {{-- Display-only: web has no upload flow (GAP-6 M6), but a photo already
         set by the app should still be visible here for parity. --}}
    <img src="{{ $product->image_url }}" alt="{{ $product->name }}" style="max-width:100%;border-radius:12px;margin-bottom:18px">
  @endif

  <form method="POST" action="{{ route('inventory.web.products.update', [$household, $shelf, $product]) }}">
    @csrf @method('PUT')

    <label for="name">{{ __('Name') }}</label>
    <input type="text" id="name" name="name" value="{{ old('name', $product->name) }}" required>
    @error('name') <p class="field-error">{{ $message }}</p> @enderror

    <label for="description">{{ __('Description') }}</label>
    <input type="text" id="description" name="description" value="{{ old('description', $product->description) }}">
    @error('description') <p class="field-error">{{ $message }}</p> @enderror

    <label for="code">{{ __('Code / barcode') }}</label>
    <input type="text" id="code" name="code" class="mono" value="{{ old('code', $product->code) }}">
    @error('code') <p class="field-error">{{ $message }}</p> @enderror

    <label for="low_stock_threshold">{{ __('Low-stock warning at (empty = off)') }}</label>
    <input type="number" id="low_stock_threshold" name="low_stock_threshold" min="1"
           value="{{ old('low_stock_threshold', $product->low_stock_threshold) }}">
    @error('low_stock_threshold') <p class="field-error">{{ $message }}</p> @enderror

    <label style="display:flex;gap:10px;align-items:center;margin-bottom:20px">
      <input type="checkbox" name="is_mandatory" value="1" style="width:auto;margin:0"
             @checked(old('is_mandatory', $product->is_mandatory))>
      {{ __('Should always be stocked (missing when at 0)') }}
    </label>

    <button type="submit" style="width:100%">{{ __('Save') }}</button>
  </form>

  {{-- GAP-6 M6: cross-hint the app-only capabilities this page can't offer
       (photo upload, barcode scanning) — same hidden-unless-configured
       pattern as the layout's footer promo. --}}
  @if (config('inventory.android_app_url'))
    <p class="muted" style="margin-top:16px">
      {!! __('Photos and barcode scanning are available in :link.', ['link' => '<a href="'.e(config('inventory.android_app_url')).'">'.__('the Android app').'</a>']) !!}
    </p>
  @endif
</div>
@endsection
