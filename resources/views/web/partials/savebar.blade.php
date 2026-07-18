{{-- Web parity Task 1: thin "saving" progress-bar idiom, shown/hidden by
     web-feedback.js while an optimistic Alpine mutation is in flight. Renders
     unconditionally (cheap markup) so non-JS pages are unaffected; it simply
     never becomes visible without JS toggling the `is-active` class. --}}
<div id="inv-savebar" class="inv-savebar" aria-hidden="true" aria-label="{{ __('Saving…') }}">
  <div class="inv-savebar-fill"></div>
</div>
