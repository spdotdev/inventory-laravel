# Landing Page Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the "coming soon" placeholder at `/` with a hybrid marketing-first landing page (hero + CSS phone mockups + feature grid + how-it-works + honest private-preview band), localized EN + NL, funneling into the shipped web UI.

**Architecture:** One self-contained Blade view (`inventory::landing`) with all CSS inline, two package-internal Blade partials for the CSS phone mockups, package lang files (`inventory::landing.*` keys) loaded by the service provider, and per-request locale negotiation in `LandingController` from `Accept-Language`. No JavaScript, no images, no route changes, no new dependencies.

**Tech Stack:** Laravel 13 package (`spdotdev/inventory`), Blade, PHPUnit via orchestra/testbench, Pint, Larastan.

**Spec:** `docs/superpowers/specs/2026-07-11-landing-page-redesign-design.md` (approved 2026-07-11).

## Global Constraints

- Repo: `/home/dev/inventory/inventory-laravel`. All paths below are relative to it.
- **No JavaScript** on the landing page; all CSS inline in the view (repo convention — see `resources/views/web/layout.blade.php`).
- **No download link and no launch date** for the Android app anywhere on the page — the preview band says "coming to Google Play" only.
- Localization: **EN + NL only**, keys under `inventory::landing.*`; the web app UI (`resources/views/web/*`) stays English — locale is set only in `LandingController`.
- Mockup partials are decorative (`aria-hidden="true"` on their containers) and their in-mockup text stays **English** (stylized screenshots, not localized copy).
- CTAs target the existing named routes `inventory.web.register.show` and `inventory.web.login.show` (verified in `routes/web.php:28-29`).
- Quality gates must stay green: `composer style` (Pint), `composer stan` (Larastan max), `composer test`. Local PHP lacks `pdo_sqlite`/`pdo_mysql` — DB-touching suites run on CI, but the landing tests below use no DB and **do run locally**.
- `view('inventory::…')` calls in controllers need the existing `@phpstan-ignore argument.type` comment pattern (runtime-registered namespace; see `LandingController`).
- The badge pulse animation must sit inside `@media (prefers-reduced-motion: no-preference)`.
- Commit after every task. Do NOT tag a release or bump sd-admin's lock — deployment is a separate, user-approved step.

---

### Task 1: Package translations (EN + NL) + provider loading

**Files:**
- Create: `lang/en/landing.php`
- Create: `lang/nl/landing.php`
- Modify: `src/InventoryServiceProvider.php` (one line in `boot()`, next to `loadViewsFrom` at line ~96)
- Test: `tests/Feature/LandingPageTest.php` (new)

**Interfaces:**
- Consumes: nothing.
- Produces: translation namespace `inventory::landing.<key>` (all keys listed below) — Tasks 3 and 4 render these; the exact EN/NL hero strings (`hero_title_top`) are asserted by later tests.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/LandingPageTest.php`:

```php
<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Support\Facades\Lang;
use Spdotdev\Inventory\Tests\TestCase;

class LandingPageTest extends TestCase
{
    public function test_landing_strings_resolve_in_english_and_dutch(): void
    {
        $this->assertSame('Know what you have,', Lang::get('inventory::landing.hero_title_top', [], 'en'));
        $this->assertSame('Weet wat je in huis hebt,', Lang::get('inventory::landing.hero_title_top', [], 'nl'));
    }

    public function test_en_and_nl_landing_locales_are_in_lockstep(): void
    {
        $en = require __DIR__.'/../../lang/en/landing.php';
        $nl = require __DIR__.'/../../lang/nl/landing.php';

        $this->assertSame(array_keys($en), array_keys($nl));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit --filter LandingPageTest`
Expected: FAIL — `test_landing_strings_resolve_in_english_and_dutch` gets the raw key `inventory::landing.hero_title_top` back (namespace not registered), and the lockstep test errors on the missing files.

- [ ] **Step 3: Create the lang files**

Create `lang/en/landing.php`:

```php
<?php

return [
    'meta_title' => 'Inventory — shared household inventory',
    'meta_description' => 'Know what you have, wherever you keep it. A shared, always-in-sync inventory for your household — freezer, fridge, pantry and beyond.',
    'nav_sign_in' => 'Sign in',
    'nav_create_account' => 'Create account',
    'badge_preview' => 'Android app in private preview',
    'hero_title_top' => 'Know what you have,',
    'hero_title_accent' => 'wherever you keep it.',
    'hero_lead' => 'One shared inventory for your household — every freezer, fridge and pantry, always in sync on every device.',
    'cta_create' => 'Create a free account',
    'cta_sign_in' => 'Sign in',
    'features_title' => 'Everything a household inventory needs',
    'feature_households_title' => 'Shared households',
    'feature_households_body' => 'Invite your family with a code, link or QR — everyone works from the same inventory.',
    'feature_tree_title' => 'Your storage, your layout',
    'feature_tree_body' => 'Model your real storage: locations hold shelves, shelves hold products — and search finds anything instantly.',
    'feature_barcode_title' => 'Barcode scanning',
    'feature_barcode_body' => 'Point the camera at a barcode to add stock in seconds — no typing at the freezer.',
    'feature_lowstock_title' => 'Running-low warnings',
    'feature_lowstock_body' => 'Set a threshold per product and see at a glance what needs restocking before it runs out.',
    'feature_live_title' => 'Live on every device',
    'feature_live_body' => 'Change something on one phone and watch it appear on the others — no refresh needed.',
    'how_title' => 'How it works',
    'how_step1_title' => 'Create your household',
    'how_step1_body' => 'Sign up on the web and create a household in seconds.',
    'how_step2_title' => 'Lay out your storage',
    'how_step2_body' => 'Add locations and shelves, then fill them with what you actually have.',
    'how_step3_title' => 'Invite the family',
    'how_step3_body' => 'Share the join code or QR — changes appear live for everyone.',
    'preview_title' => 'The Android app is in private preview',
    'preview_body' => 'Coming to Google Play. The web app works everywhere today.',
    'footer_before' => 'A ',
    'footer_after' => ' project',
];
```

Create `lang/nl/landing.php`:

```php
<?php

return [
    'meta_title' => 'Inventory — gedeelde huishoudvoorraad',
    'meta_description' => 'Weet wat je in huis hebt, waar je het ook bewaart. Eén gedeelde, altijd actuele voorraad voor je huishouden — vriezer, koelkast, voorraadkast en meer.',
    'nav_sign_in' => 'Inloggen',
    'nav_create_account' => 'Account aanmaken',
    'badge_preview' => 'Android-app in besloten preview',
    'hero_title_top' => 'Weet wat je in huis hebt,',
    'hero_title_accent' => 'waar je het ook bewaart.',
    'hero_lead' => 'Eén gedeelde voorraad voor je huishouden — elke vriezer, koelkast en voorraadkast, altijd synchroon op elk apparaat.',
    'cta_create' => 'Maak een gratis account',
    'cta_sign_in' => 'Inloggen',
    'features_title' => 'Alles wat een huishoudvoorraad nodig heeft',
    'feature_households_title' => 'Gedeelde huishoudens',
    'feature_households_body' => 'Nodig je gezin uit met een code, link of QR — iedereen werkt in dezelfde voorraad.',
    'feature_tree_title' => 'Jouw opslag, jouw indeling',
    'feature_tree_body' => 'Modelleer je echte opslag: locaties bevatten planken, planken bevatten producten — en zoeken vindt alles direct.',
    'feature_barcode_title' => 'Barcodes scannen',
    'feature_barcode_body' => 'Richt de camera op een barcode om in seconden voorraad toe te voegen — geen getyp bij de vriezer.',
    'feature_lowstock_title' => 'Bijna-op waarschuwingen',
    'feature_lowstock_body' => 'Stel per product een drempel in en zie in één oogopslag wat aangevuld moet worden.',
    'feature_live_title' => 'Live op elk apparaat',
    'feature_live_body' => 'Wijzig iets op de ene telefoon en zie het direct op de andere verschijnen — zonder verversen.',
    'how_title' => 'Zo werkt het',
    'how_step1_title' => 'Maak je huishouden aan',
    'how_step1_body' => 'Meld je aan op het web en maak in seconden een huishouden.',
    'how_step2_title' => 'Richt je opslag in',
    'how_step2_body' => 'Voeg locaties en planken toe en vul ze met wat je echt in huis hebt.',
    'how_step3_title' => 'Nodig het gezin uit',
    'how_step3_body' => 'Deel de code of QR — wijzigingen verschijnen live voor iedereen.',
    'preview_title' => 'De Android-app is in besloten preview',
    'preview_body' => 'Binnenkort in Google Play. De web-app werkt vandaag al overal.',
    'footer_before' => 'Een project van ',
    'footer_after' => '',
];
```

- [ ] **Step 4: Register the lang path in the provider**

In `src/InventoryServiceProvider.php`, directly after the `loadViewsFrom` line (~line 96):

```php
$this->loadTranslationsFrom(__DIR__.'/../lang', 'inventory');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php vendor/bin/phpunit --filter LandingPageTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add lang/ src/InventoryServiceProvider.php tests/Feature/LandingPageTest.php
git commit -m "feat: EN+NL landing page translations loaded under the inventory:: namespace"
```

---

### Task 2: CSS phone mockup partials

**Files:**
- Create: `resources/views/landing/_mock-dashboard.blade.php`
- Create: `resources/views/landing/_mock-location.blade.php`
- Test: `tests/Feature/LandingPageTest.php` (add one test)

**Interfaces:**
- Consumes: the `inventory::` view namespace (already registered).
- Produces: views `inventory::landing._mock-dashboard` and `inventory::landing._mock-location`, included by Task 3's page. Markup only — all `.phone`/`.m-*` CSS classes are styled by Task 3's inline stylesheet.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/LandingPageTest.php`:

```php
    public function test_mockup_partials_render(): void
    {
        $dashboard = view('inventory::landing._mock-dashboard')->render();
        $location = view('inventory::landing._mock-location')->render();

        $this->assertStringContainsString('Running low', $dashboard);
        $this->assertStringContainsString('Top shelf', $location);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit --filter test_mockup_partials_render`
Expected: FAIL — `InvalidArgumentException: View [landing._mock-dashboard] not found`.

- [ ] **Step 3: Create the partials**

Create `resources/views/landing/_mock-dashboard.blade.php` (markup only; styled by the landing page's inline CSS):

```blade
<div class="phone">
  <div class="phone-screen">
    <div class="m-title">Dashboard</div>
    <div class="m-stats">
      <div class="m-stat"><span class="m-num">128</span><span class="m-lbl">products</span></div>
      <div class="m-stat"><span class="m-num">4</span><span class="m-lbl">storages</span></div>
    </div>
    <div class="m-card">
      <div class="m-h">Storages</div>
      <div class="m-bar"><span>Freezer</span><i style="width:72%"></i><b>62</b></div>
      <div class="m-bar"><span>Pantry</span><i style="width:48%"></i><b>41</b></div>
      <div class="m-bar"><span>Fridge</span><i style="width:30%"></i><b>25</b></div>
    </div>
    <div class="m-card">
      <div class="m-h">Running low</div>
      <div class="m-row"><em class="m-dot"></em>Milk<b>1</b></div>
      <div class="m-row"><em class="m-dot"></em>Coffee beans<b>2</b></div>
      <div class="m-row"><em class="m-dot m-dot-bad"></em>Butter<b>0</b></div>
    </div>
  </div>
</div>
```

Create `resources/views/landing/_mock-location.blade.php`:

```blade
<div class="phone phone-sm">
  <div class="phone-screen">
    <div class="m-title">Freezer</div>
    <div class="m-tabs"><span class="m-tab-on">Top shelf</span><span>Drawer</span><span>Door</span></div>
    <div class="m-card">
      <div class="m-row">Peas<span class="m-spacer"></span><span class="m-step">−</span><b>3</b><span class="m-step">+</span></div>
      <div class="m-row">Spinach<em class="m-chip">running low</em><b>1</b></div>
      <div class="m-row">Bread<em class="m-chip m-chip-bad">missing</em><b>0</b></div>
    </div>
    <div class="m-card">
      <div class="m-h">Scan a barcode</div>
      <div class="m-scan"><i></i></div>
    </div>
  </div>
</div>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit --filter test_mockup_partials_render`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/views/landing/ tests/Feature/LandingPageTest.php
git commit -m "feat: CSS phone mockup partials for the landing page (dashboard + location)"
```

---

### Task 3: Landing page rewrite (one-pager)

**Files:**
- Modify: `resources/views/landing.blade.php` (full rewrite)
- Modify: `tests/Feature/SkeletonTest.php:11-17` (update the placeholder assertion)
- Test: `tests/Feature/LandingPageTest.php` (add one test)

**Interfaces:**
- Consumes: `inventory::landing.*` translation keys (Task 1); `inventory::landing._mock-dashboard` / `._mock-location` partials (Task 2); routes `inventory.web.register.show`, `inventory.web.login.show`.
- Produces: the rendered page Task 4's locale tests assert against (EN hero text `Know what you have,`).

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/LandingPageTest.php`:

```php
    public function test_landing_renders_the_marketing_page_in_english_by_default(): void
    {
        $this->get('http://inventory.test/')
            ->assertOk()
            ->assertSee('Know what you have,')
            ->assertSee('Create a free account')
            ->assertSee('private preview')
            ->assertDontSee('Check back soon');
    }
```

And update `tests/Feature/SkeletonTest.php` — the old placeholder assertion:

```php
    public function test_landing_page_renders_on_the_configured_host(): void
    {
        $this->get('http://inventory.test/')
            ->assertOk()
            ->assertSee('Inventory')
            ->assertSee('private preview');
    }
```

- [ ] **Step 2: Run tests to verify the new one fails**

Run: `php vendor/bin/phpunit --filter "LandingPageTest|SkeletonTest"`
Expected: `test_landing_renders_the_marketing_page_in_english_by_default` FAILS (page still shows "Check back soon"); the updated SkeletonTest assertion FAILS ("private preview" absent).

- [ ] **Step 3: Rewrite the landing view**

Replace `resources/views/landing.blade.php` entirely with:

```blade
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ __('inventory::landing.meta_title') }}</title>
<meta name="description" content="{{ __('inventory::landing.meta_description') }}">
<meta name="robots" content="index, follow">
<meta property="og:title" content="{{ __('inventory::landing.meta_title') }}">
<meta property="og:description" content="{{ __('inventory::landing.meta_description') }}">
<meta property="og:type" content="website">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  :root{
    --bg-0:#0a141d; --bg-1:#0f1f2b; --fg:#eaf6ff; --muted:#7fa8c4;
    --accent:#7dd3fc; --on-accent:#06283b; --card:rgba(125,211,252,.08);
    --card-border:rgba(125,211,252,.18); --glow:rgba(125,211,252,.22);
    --warn:#fbbf24; --bad:#f87171;
  }
  @media (prefers-color-scheme: light){
    :root{
      --bg-0:#dbeafe; --bg-1:#eef6ff; --fg:#0d2436; --muted:#4d7a9c;
      --accent:#1ea7e6; --on-accent:#ffffff;
      --card:rgba(255,255,255,.72); --card-border:rgba(63,125,166,.18);
      --glow:rgba(30,167,230,.18); --warn:#b45309; --bad:#dc2626;
    }
  }
  html{scroll-behavior:smooth}
  body{
    font-family:'Plus Jakarta Sans',system-ui,-apple-system,sans-serif;
    color:var(--fg);-webkit-font-smoothing:antialiased;
    background:
      radial-gradient(1200px 600px at 50% -10%, var(--glow), transparent 60%),
      linear-gradient(160deg, var(--bg-1), var(--bg-0));
    background-attachment:fixed;
  }
  a{color:var(--accent);text-decoration:none}
  .btn{
    display:inline-block;padding:14px 26px;background:var(--accent);color:var(--on-accent);
    font-size:15px;font-weight:700;border-radius:14px;border:none;cursor:pointer;
  }
  .btn:hover{filter:brightness(1.08)}
  .btn-quiet{background:transparent;color:var(--accent);border:1px solid var(--card-border)}
  .btn-quiet:hover{background:var(--card);filter:none}
  .btn-small{padding:10px 18px;font-size:13.5px;border-radius:11px}

  header.top{
    display:flex;align-items:center;justify-content:space-between;
    max-width:1080px;margin:0 auto;padding:20px 28px;
  }
  .brand{font-weight:800;font-size:19px;color:var(--accent);letter-spacing:-.01em}
  header.top nav{display:flex;gap:20px;align-items:center;font-size:14.5px;font-weight:600}

  main{max-width:1080px;margin:0 auto;padding:0 28px 72px}
  section{margin-top:72px}

  .hero{display:flex;gap:56px;align-items:center;margin-top:40px}
  .hero-copy{flex:1;min-width:0}
  .badge{
    display:inline-flex;align-items:center;gap:7px;
    font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;
    color:var(--accent);background:rgba(125,211,252,.10);
    border:1px solid var(--card-border);border-radius:999px;padding:7px 14px;margin-bottom:22px;
  }
  .badge .dot{width:7px;height:7px;border-radius:999px;background:var(--accent)}
  @media (prefers-reduced-motion: no-preference){
    .badge .dot{box-shadow:0 0 0 0 var(--glow);animation:pulse 2s infinite}
    @keyframes pulse{0%{box-shadow:0 0 0 0 var(--glow)}70%{box-shadow:0 0 0 9px transparent}100%{box-shadow:0 0 0 0 transparent}}
  }
  h1{font-size:clamp(34px,5vw,52px);font-weight:800;letter-spacing:-.03em;line-height:1.06}
  .tag{color:var(--accent)}
  .lead{color:var(--muted);font-size:18px;line-height:1.65;margin-top:18px;max-width:480px}
  .cta-row{display:flex;gap:14px;margin-top:30px;flex-wrap:wrap}
  .hero-visual{flex:0 0 auto}

  h2{font-size:clamp(24px,3vw,32px);font-weight:800;letter-spacing:-.02em;margin-bottom:28px}
  .grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
  .fcard{
    background:var(--card);border:1px solid var(--card-border);border-radius:22px;padding:26px;
    backdrop-filter:blur(20px) saturate(140%);-webkit-backdrop-filter:blur(20px) saturate(140%);
  }
  .ficon{font-size:26px;margin-bottom:14px}
  .fcard h3{font-size:16.5px;font-weight:700;margin-bottom:8px}
  .fcard p{color:var(--muted);font-size:14.5px;line-height:1.6}

  .how{display:flex;gap:56px;align-items:center}
  .how-copy{flex:1;min-width:0}
  .steps{list-style:none}
  .steps li{display:flex;gap:18px;margin-bottom:24px}
  .stepno{
    flex:0 0 38px;height:38px;border-radius:12px;display:flex;align-items:center;justify-content:center;
    background:var(--card);border:1px solid var(--card-border);color:var(--accent);
    font-weight:800;font-size:16px;
  }
  .steps h3{font-size:16.5px;font-weight:700;margin-bottom:5px}
  .steps p{color:var(--muted);font-size:14.5px;line-height:1.6}
  .how-visual{flex:0 0 auto}

  .preview{
    text-align:center;background:var(--card);border:1px solid var(--card-border);
    border-radius:28px;padding:52px 32px;
    backdrop-filter:blur(20px) saturate(140%);-webkit-backdrop-filter:blur(20px) saturate(140%);
  }
  .preview h2{margin-bottom:10px}
  .preview p{color:var(--muted);font-size:16px;margin-bottom:26px}

  footer.foot{
    max-width:1080px;margin:0 auto;padding:28px;text-align:center;
    color:var(--muted);font-size:13px;letter-spacing:.02em;
  }
  footer.foot a{color:var(--muted);border-bottom:1px solid var(--card-border)}

  /* ---- CSS phone mockups (markup in landing/_mock-*.blade.php) ---- */
  .phone{
    width:270px;border-radius:36px;padding:12px;
    background:linear-gradient(160deg, rgba(125,211,252,.25), rgba(125,211,252,.06));
    border:1px solid var(--card-border);box-shadow:0 30px 80px rgba(0,0,0,.35);
  }
  .phone-sm{width:250px}
  .phone-screen{
    border-radius:26px;padding:18px 14px;min-height:430px;
    background:linear-gradient(170deg, var(--bg-1), var(--bg-0));
    border:1px solid var(--card-border);
  }
  .phone-sm .phone-screen{min-height:390px}
  .m-title{font-weight:800;font-size:15px;margin-bottom:14px;color:var(--fg)}
  .m-stats{display:flex;gap:10px;margin-bottom:12px}
  .m-stat{
    flex:1;background:var(--card);border:1px solid var(--card-border);border-radius:14px;
    padding:12px;display:flex;flex-direction:column;gap:2px;
  }
  .m-num{font-weight:800;font-size:19px;color:var(--accent)}
  .m-lbl{font-size:11px;color:var(--muted)}
  .m-card{
    background:var(--card);border:1px solid var(--card-border);border-radius:14px;
    padding:12px;margin-bottom:12px;
  }
  .m-h{font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--muted);margin-bottom:10px}
  .m-bar{display:flex;align-items:center;gap:8px;font-size:12px;margin-bottom:8px;color:var(--fg)}
  .m-bar span{flex:0 0 56px}
  .m-bar i{height:7px;border-radius:999px;background:var(--accent);opacity:.85}
  .m-bar b{margin-left:auto;font-size:11.5px;color:var(--muted)}
  .m-row{display:flex;align-items:center;gap:8px;font-size:12.5px;margin-bottom:9px;color:var(--fg)}
  .m-row b{margin-left:auto;font-weight:700;color:var(--fg)}
  .m-row:last-child,.m-bar:last-child{margin-bottom:0}
  .m-dot{width:8px;height:8px;border-radius:999px;background:var(--warn)}
  .m-dot-bad{background:var(--bad)}
  .m-spacer{flex:1}
  .m-step{
    width:22px;height:22px;border-radius:8px;display:flex;align-items:center;justify-content:center;
    background:var(--card);border:1px solid var(--card-border);color:var(--accent);font-weight:700;font-size:13px;
  }
  .m-row .m-step + b{margin-left:0}
  .m-chip{
    font-style:normal;font-size:10px;font-weight:700;padding:3px 8px;border-radius:999px;
    color:var(--warn);border:1px solid currentColor;margin-left:auto;
  }
  .m-chip + b{margin-left:8px}
  .m-chip-bad{color:var(--bad)}
  .m-tabs{display:flex;gap:6px;margin-bottom:12px;font-size:11.5px;color:var(--muted)}
  .m-tabs span{padding:6px 11px;border-radius:999px;border:1px solid transparent}
  .m-tab-on{color:var(--on-accent);background:var(--accent);font-weight:700}
  .m-scan{
    height:64px;border-radius:10px;border:1px dashed var(--card-border);
    display:flex;align-items:center;justify-content:center;
  }
  .m-scan i{display:block;width:70%;height:2px;background:var(--bad);border-radius:999px;opacity:.8}

  @media (max-width: 860px){
    .hero,.how{flex-direction:column;gap:36px}
    .how{flex-direction:column-reverse}
    .grid{grid-template-columns:repeat(2,1fr)}
    section{margin-top:56px}
  }
  @media (max-width: 560px){
    .grid{grid-template-columns:1fr}
    header.top nav .btn-small{display:none}
  }
</style>
</head>
<body>
<header class="top">
  <span class="brand">Inventory</span>
  <nav>
    <a href="{{ route('inventory.web.login.show') }}">{{ __('inventory::landing.nav_sign_in') }}</a>
    <a class="btn btn-small" href="{{ route('inventory.web.register.show') }}">{{ __('inventory::landing.nav_create_account') }}</a>
  </nav>
</header>

<main>
  <section class="hero">
    <div class="hero-copy">
      <div class="badge"><span class="dot"></span> {{ __('inventory::landing.badge_preview') }}</div>
      <h1>{{ __('inventory::landing.hero_title_top') }}<br><span class="tag">{{ __('inventory::landing.hero_title_accent') }}</span></h1>
      <p class="lead">{{ __('inventory::landing.hero_lead') }}</p>
      <div class="cta-row">
        <a class="btn" href="{{ route('inventory.web.register.show') }}">{{ __('inventory::landing.cta_create') }}</a>
        <a class="btn btn-quiet" href="{{ route('inventory.web.login.show') }}">{{ __('inventory::landing.cta_sign_in') }}</a>
      </div>
    </div>
    <div class="hero-visual" aria-hidden="true">
      @include('inventory::landing._mock-dashboard')
    </div>
  </section>

  <section class="features">
    <h2>{{ __('inventory::landing.features_title') }}</h2>
    <div class="grid">
      @foreach ([['👪', 'households'], ['🗄️', 'tree'], ['📷', 'barcode'], ['📉', 'lowstock'], ['⚡', 'live']] as [$icon, $key])
        <div class="fcard">
          <div class="ficon" aria-hidden="true">{{ $icon }}</div>
          <h3>{{ __('inventory::landing.feature_'.$key.'_title') }}</h3>
          <p>{{ __('inventory::landing.feature_'.$key.'_body') }}</p>
        </div>
      @endforeach
    </div>
  </section>

  <section class="how">
    <div class="how-copy">
      <h2>{{ __('inventory::landing.how_title') }}</h2>
      <ol class="steps">
        @foreach ([1, 2, 3] as $i)
          <li>
            <span class="stepno" aria-hidden="true">{{ $i }}</span>
            <div>
              <h3>{{ __('inventory::landing.how_step'.$i.'_title') }}</h3>
              <p>{{ __('inventory::landing.how_step'.$i.'_body') }}</p>
            </div>
          </li>
        @endforeach
      </ol>
    </div>
    <div class="how-visual" aria-hidden="true">
      @include('inventory::landing._mock-location')
    </div>
  </section>

  <section class="preview">
    <h2>{{ __('inventory::landing.preview_title') }}</h2>
    <p>{{ __('inventory::landing.preview_body') }}</p>
    <a class="btn" href="{{ route('inventory.web.register.show') }}">{{ __('inventory::landing.cta_create') }}</a>
  </section>
</main>

<footer class="foot">
  {{ __('inventory::landing.footer_before') }}<a href="https://scuttle.dev" rel="noopener">Scuttle Development</a>{{ __('inventory::landing.footer_after') }}
</footer>
</body>
</html>
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php vendor/bin/phpunit --filter "LandingPageTest|SkeletonTest"`
Expected: PASS (all — including the two SkeletonTest non-landing tests, untouched).

- [ ] **Step 5: Commit**

```bash
git add resources/views/landing.blade.php tests/Feature/SkeletonTest.php tests/Feature/LandingPageTest.php
git commit -m "feat: marketing-first landing page — hero + mockups + features + honest preview band"
```

---

### Task 4: Locale negotiation in LandingController

**Files:**
- Modify: `src/Http/Controllers/LandingController.php`
- Test: `tests/Feature/LandingPageTest.php` (add two tests)

**Interfaces:**
- Consumes: `Accept-Language` request header; NL strings from Task 1 (`Weet wat je in huis hebt,`).
- Produces: nothing further — terminal behavior.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/LandingPageTest.php`:

```php
    public function test_landing_renders_dutch_for_a_dutch_accept_language(): void
    {
        $this->get('http://inventory.test/', ['Accept-Language' => 'nl-NL,nl;q=0.9,en;q=0.5'])
            ->assertOk()
            ->assertSee('Weet wat je in huis hebt,');
    }

    public function test_landing_falls_back_to_english_for_an_unsupported_locale(): void
    {
        $this->get('http://inventory.test/', ['Accept-Language' => 'de-DE,de;q=0.9'])
            ->assertOk()
            ->assertSee('Know what you have,');
    }
```

- [ ] **Step 2: Run tests to verify the Dutch one fails**

Run: `php vendor/bin/phpunit --filter LandingPageTest`
Expected: the Dutch test FAILS (page renders English); the fallback test passes trivially.

- [ ] **Step 3: Implement locale negotiation**

Replace `src/Http/Controllers/LandingController.php` with:

```php
<?php

namespace Spdotdev\Inventory\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\View\View;

class LandingController
{
    public function index(Request $request): View
    {
        // Landing page only: the marketing copy is EN + NL, negotiated from the
        // browser. The web app UI deliberately stays English, so the locale is
        // set here per-request rather than in middleware.
        App::setLocale($request->getPreferredLanguage(['en', 'nl']) ?? 'en');

        // @phpstan-ignore argument.type (the inventory:: namespace is registered at runtime via loadViewsFrom, so it is not resolvable during package-only static analysis)
        return view('inventory::landing');
    }
}
```

- [ ] **Step 4: Run the full landing suite**

Run: `php vendor/bin/phpunit --filter "LandingPageTest|SkeletonTest"`
Expected: PASS (all).

- [ ] **Step 5: Commit**

```bash
git add src/Http/Controllers/LandingController.php tests/Feature/LandingPageTest.php
git commit -m "feat: negotiate landing page locale (en/nl) from Accept-Language"
```

---

### Task 5: Quality gates + docs + push

**Files:**
- Modify: `ROADMAP.md` (the "Redesign the landing page" item under REMAINING)

**Interfaces:**
- Consumes: everything above.
- Produces: green gates, updated docs, pushed branch. (No release tag — deploy is a separate, user-approved step.)

- [ ] **Step 1: Run all three quality gates**

```bash
composer style && composer stan && composer test
```

Expected: Pint PASS, Larastan 0 errors, PHPUnit — landing/skeleton suites pass locally; if DB-backed suites error locally on the missing pdo driver, that is pre-existing (they run on CI) — the push's CI run is the real gate.

- [ ] **Step 2: Fix anything the gates flag**

Typical: Pint reformatting of the new PHP files (`composer style` → run `php vendor/bin/pint lang/ src/ tests/` to auto-fix, then re-run the gate); a Larastan complaint about `getPreferredLanguage` nullability — keep the `?? 'en'`.

- [ ] **Step 3: Update ROADMAP.md**

Change the item under `### REMAINING`:

```markdown
- [x] **Redesign the landing page** — shipped 2026-07-11 (spec:
  `docs/superpowers/specs/2026-07-11-landing-page-redesign-design.md`). Hybrid
  marketing-first one-pager: hero + CSS phone mockups (no images/JS), feature grid,
  how-it-works, honest "private preview / coming to Google Play" band (no download
  link), CTAs into the web UI, EN + NL via `inventory::landing.*` +
  `Accept-Language` negotiation (landing only). Reaches prod with the next
  package tag + sd-admin lock bump (user-approved).
```

- [ ] **Step 4: Commit and push**

```bash
git add ROADMAP.md
git commit -m "docs: landing page redesign shipped (pending next release tag)"
git push
```

Expected: CI (quality + mysql jobs) green on GitHub. Do NOT tag or bump sd-admin — deployment is the user's call.

---

## Self-review notes

- **Spec coverage:** header/hero/features/how/preview/footer → Task 3; CSS mockups → Task 2; EN+NL lang + provider → Task 1; locale negotiation + fallback tests → Task 4; testing section (EN render, NL render, fallback) → Tasks 3–4; reduced-motion + light/dark + no-JS + indexable/og → Task 3 stylesheet/head; "routes/API/deploy untouched" → no task touches them.
- **Consistency check:** partial view names (`inventory::landing._mock-dashboard`/`._mock-location`) match between Tasks 2 and 3; `hero_title_top` EN/NL strings match between Tasks 1, 3 and 4's assertions; route names match `routes/web.php:28-29`.
- Old `landing.blade.php` strings ("Coming to Android", "Check back soon") are asserted **gone** via `assertDontSee` + the updated SkeletonTest.
