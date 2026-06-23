<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Inventory — coming soon</title>
<meta name="description" content="A shared, always-current inventory for your household. Android app coming soon.">
<meta name="robots" content="index, follow">
<meta property="og:title" content="Inventory — coming soon">
<meta property="og:description" content="Know what you have, wherever you keep it. Android app coming soon.">
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
  }
  @media (prefers-color-scheme: light){
    :root{
      --bg-0:#dbeafe; --bg-1:#eef6ff; --fg:#0d2436; --muted:#4d7a9c;
      --accent:#1ea7e6; --on-accent:#ffffff;
      --card:rgba(255,255,255,.72); --card-border:rgba(63,125,166,.18);
      --glow:rgba(30,167,230,.18);
    }
  }
  html,body{height:100%}
  body{
    font-family:'Plus Jakarta Sans',system-ui,-apple-system,sans-serif;
    color:var(--fg);
    background:
      radial-gradient(1200px 600px at 50% -10%, var(--glow), transparent 60%),
      linear-gradient(160deg, var(--bg-1), var(--bg-0));
    min-height:100dvh;display:flex;align-items:center;justify-content:center;
    padding:32px;-webkit-font-smoothing:antialiased;
  }
  .card{
    position:relative;width:100%;max-width:560px;text-align:center;
    background:var(--card);border:1px solid var(--card-border);
    border-radius:28px;padding:48px 36px 40px;
    backdrop-filter:blur(20px) saturate(140%);
    -webkit-backdrop-filter:blur(20px) saturate(140%);
    box-shadow:0 30px 80px rgba(0,0,0,.35);
  }
  .mark{
    width:64px;height:64px;border-radius:20px;margin:0 auto 22px;
    display:flex;align-items:center;justify-content:center;
    background:linear-gradient(145deg, var(--accent), color-mix(in srgb, var(--accent) 65%, #ffffff));
    color:var(--on-accent);font-size:30px;
    box-shadow:0 10px 30px var(--glow);
  }
  .badge{
    display:inline-flex;align-items:center;gap:7px;
    font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;
    color:var(--accent);background:rgba(125,211,252,.10);
    border:1px solid var(--card-border);border-radius:999px;padding:7px 14px;margin-bottom:20px;
  }
  .badge .dot{width:7px;height:7px;border-radius:999px;background:var(--accent);
    box-shadow:0 0 0 0 var(--glow);animation:pulse 2s infinite}
  @keyframes pulse{0%{box-shadow:0 0 0 0 var(--glow)}70%{box-shadow:0 0 0 9px transparent}100%{box-shadow:0 0 0 0 transparent}}
  h1{font-size:40px;font-weight:800;letter-spacing:-.03em;line-height:1.05}
  .tag{color:var(--accent)}
  .lead{color:var(--muted);font-size:17px;line-height:1.6;margin:16px auto 0;max-width:420px}
  .soon{
    margin-top:30px;display:inline-flex;align-items:center;gap:10px;
    font-weight:700;font-size:14px;color:var(--fg);
    background:rgba(125,211,252,.12);border:1px solid var(--card-border);
    border-radius:999px;padding:12px 20px;
  }
  .foot{margin-top:34px;color:var(--muted);font-size:12.5px;letter-spacing:.02em}
  .foot a{color:var(--muted);text-decoration:none;border-bottom:1px solid var(--card-border)}
</style>
</head>
<body>
  <main class="card">
    <div class="mark" aria-hidden="true">❄️</div>
    <div class="badge"><span class="dot"></span> Coming to Android</div>
    <h1><span class="tag">Inventory</span><br>is on its way</h1>
    <p class="lead">Know what you have, wherever you keep it — a simple, shared inventory for your household.</p>
    <div class="soon">🚧 We're building it. Check back soon.</div>
    <p class="foot">A <a href="https://scuttle.dev" rel="noopener">Scuttle Development</a> project</p>
  </main>
</body>
</html>
