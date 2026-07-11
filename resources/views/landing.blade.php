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
