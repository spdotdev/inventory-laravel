# Vendored JS (web parity Task 1)

Self-hosted per the design doc (`docs/superpowers/specs/2026-07-18-web-parity-design.md`):
no CDN, published to the host app via the existing `inventory-assets` publish tag
(`public/` → `public_path('vendor/inventory')`), same mechanism as the landing page
assets.

- `alpine.min.js` — Alpine.js **3.15.12** (`cdn.min.js` build from
  `https://cdn.jsdelivr.net/npm/alpinejs@3/dist/cdn.min.js`, resolved via
  jsdelivr's `alpinejs@3` tag on 2026-07-18).
  sha256: `57b37d7cae9a27d965fdae4adcc844245dfdc407e655aee85dcfff3a08036a3f`
- `web-feedback.js` — package-authored shared feedback layer (savebar + toasts
  + fetch wrapper); see its header comment for the contract.

Both are loaded with `defer` from `resources/views/web/layout.blade.php` via
`asset('vendor/inventory/js/...')`. Loading is unconditional plumbing only —
no page's non-JS behavior changes because of it.
