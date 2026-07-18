{{--
  Web parity T3: shared Alpine delete-strategy dialog, matching Android's
  DeleteStrategyDialog semantics — a safest-available default (never a
  destructive default), a radio choice between the strategies that actually
  apply, and a target select that only appears for whichever strategy needs
  one AND only when a target genuinely exists ($targets non-empty). This is
  the JS-enhanced control; each call site keeps the pre-existing plain
  select+confirm subset inside a <noscript> block right next to it (Task 1/2
  convention), invisible whenever Alpine successfully renders this dialog.

  Required variables:
    $dialogTriggerLabel string  trigger button label (also the dialog title)
    $actionUrl           string  form action (DELETE via @method spoof)
    $summary              string  plain-words "what will be affected" line;
                                    '' hides it entirely
    $options               array<array{value:string,label:string,needsTarget?:bool}>
                                   first entry is the safest default
    $targetFieldName       string|null  'target_shelf_id' | 'target_location_id'
    $targets                array<array{value:int,label:string}>  empty => the
                                    move option (if any) is dropped from the list
--}}
@php
  $inv_visibleOptions = collect($options)->reject(fn ($o) => ($o['needsTarget'] ?? false) && $targets === [])->values();
  $inv_defaultStrategy = $inv_visibleOptions->first()['value'] ?? null;
  $inv_moveOptions = collect($options)->filter(fn ($o) => $o['needsTarget'] ?? false)->values();
@endphp
<div x-data="{ open: false, strategy: {{ Illuminate\Support\Js::from($inv_defaultStrategy) }} }" style="display:inline">
  <button type="button" class="btn-danger" @click="open = true">{{ $dialogTriggerLabel }}</button>

  <div class="inv-dialog-backdrop" x-show="open" x-cloak @keydown.escape.window="open = false">
    <div class="card inv-dialog" @click.outside="open = false">
      <h2 style="font-size:16px;color:var(--text-heading);margin-bottom:10px">{{ $dialogTriggerLabel }}</h2>
      @if ($summary !== '')
        <p class="muted" style="margin-bottom:16px">{{ $summary }}</p>
      @endif
      <form method="POST" action="{{ $actionUrl }}">
        @csrf @method('DELETE')
        @foreach ($inv_visibleOptions as $option)
          <label class="inv-dialog-option">
            <input type="radio" name="strategy" value="{{ $option['value'] }}" x-model="strategy">
            <span>{{ $option['label'] }}</span>
          </label>
        @endforeach
        @if ($targetFieldName !== null && $targets !== [])
          @foreach ($inv_moveOptions as $moveOption)
            <div x-show="strategy === {{ Illuminate\Support\Js::from($moveOption['value']) }}" x-cloak>
              <select name="{{ $targetFieldName }}" style="width:100%">
                @foreach ($targets as $target)
                  <option value="{{ $target['value'] }}">{{ $target['label'] }}</option>
                @endforeach
              </select>
            </div>
          @endforeach
        @endif
        <div class="row" style="margin-top:6px">
          <button type="button" class="btn-quiet" @click="open = false">{{ __('Cancel') }}</button>
          <button type="submit" class="btn-danger grow">{{ $dialogTriggerLabel }}</button>
        </div>
      </form>
    </div>
  </div>
</div>
