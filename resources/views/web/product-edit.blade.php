@extends('inventory::web.layout')
@section('title', __('Edit :name', ['name' => $product->name]).' — '.__('Inventory'))
@section('content')
<h1>{{ __('Edit :name', ['name' => $product->name]) }}</h1>
<p class="sub"><a href="{{ route('inventory.web.locations.show', [$household, $shelf->location_id]) }}">← {{ __('Back to :shelf', ['shelf' => $shelf->name]) }}</a></p>

<div class="card" style="max-width:520px">
  {{-- Current stock + steppers (audit #12): the same add/remove endpoints as
       the location page; they redirect back here. --}}
  <div class="row" style="margin-bottom:20px">
    <span class="grow" style="font-size:14px;color:var(--text-heading);font-weight:600">{{ __('In stock') }}</span>
    <form class="inline" method="POST" action="{{ route('inventory.web.products.remove', [$household, $shelf, $product]) }}">
      @csrf<button type="submit" class="btn-quiet" aria-label="{{ __('Remove one') }}">−</button>
    </form>
    <span class="mono" style="min-width:32px;text-align:center">{{ $product->quantity }}</span>
    <form class="inline" method="POST" action="{{ route('inventory.web.products.add', [$household, $shelf, $product]) }}">
      @csrf<button type="submit" class="btn-quiet" aria-label="{{ __('Add one') }}">+</button>
    </form>
  </div>

  @if ($product->image_url)
    <img src="{{ $product->image_url }}" alt="{{ $product->name }}" style="max-width:100%;border-radius:12px;margin-bottom:18px">
  @endif

  {{-- Web parity T5: photo upload, mirroring the API's image endpoint. Plain
       multipart form post — no Alpine needed for a single file field. --}}
  <form method="POST" action="{{ route('inventory.web.products.image', [$household, $shelf, $product]) }}" enctype="multipart/form-data" style="margin-bottom:20px">
    @csrf
    <label for="image">{{ __('Photo') }}</label>
    <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp">
    @error('image') <p class="field-error">{{ $message }}</p> @enderror
    <button type="submit" style="width:100%;margin-top:8px">{{ __('Upload photo') }}</button>
  </form>

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

    <label style="display:flex;gap:10px;align-items:center;margin-bottom:12px">
      <input type="checkbox" name="is_mandatory" value="1" style="width:auto;margin:0"
             @checked(old('is_mandatory', $product->is_mandatory))>
      {{ __('Should always be stocked (missing when at 0)') }}
    </label>

    {{-- Starred parity with the app (audit #11) — is_starred was API/Android
         only; the update() normalizes it like is_mandatory. --}}
    <label style="display:flex;gap:10px;align-items:center;margin-bottom:20px">
      <input type="checkbox" name="is_starred" value="1" style="width:auto;margin:0"
             @checked(old('is_starred', $product->is_starred))>
      {{ __('Starred') }}
    </label>

    <button type="submit" style="width:100%">{{ __('Save') }}</button>
  </form>

  {{-- GAP-8: move to another shelf (web twin of the API's move endpoint;
       member-level — filing stock is not restructuring). Hidden when the
       household has no other shelf to move to. --}}
  @if ($moveTargets->isNotEmpty())
    <form method="POST" action="{{ route('inventory.web.products.move', [$household, $shelf, $product]) }}" style="margin-top:20px">
      @csrf
      <label class="muted" style="display:block;margin-bottom:6px">{{ __('Move to another shelf') }}</label>
      <div class="row">
        <select name="shelf_id" required class="grow" style="margin-bottom:0">
          @foreach ($moveTargets as $target)
            <option value="{{ $target->id }}">{{ $target->location->name }} — {{ $target->name }}</option>
          @endforeach
        </select>
        <button type="submit" class="btn-quiet">{{ __('Move') }}</button>
      </div>
      @error('shelf_id') <p class="field-error">{{ $message }}</p> @enderror
    </form>
  @endif

  {{-- GAP-6 M6: cross-hint the remaining app-only capability this page can't
       offer (barcode scanning) — photo upload moved to web in T5, so the
       hint no longer mentions it. Same hidden-unless-configured pattern as
       the layout's footer promo. --}}
  @if (config('inventory.android_app_url'))
    <p class="muted" style="margin-top:16px">
      {!! __('Barcode scanning is available in :link.', ['link' => '<a href="'.e(config('inventory.android_app_url')).'">'.__('the Android app').'</a>']) !!}
    </p>
  @endif
</div>
@endsection
