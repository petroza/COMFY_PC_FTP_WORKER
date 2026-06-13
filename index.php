<?php
if (is_file(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
    if (function_exists('pz_security_headers')) {
        pz_security_headers(false);
    }
}
?>
<!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ComfyW LTX 2.3</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Space+Grotesk:wght@500;600;700&family=JetBrains+Mono:wght@500;600&display=swap');
:root{--grad:linear-gradient(115deg,#2f7bff,#38bdf8);--bd:#272c44;--bd2:#363d5e;--mut:#9097b6}
*{box-sizing:border-box}
body{margin:0;color:#eef0fb;font-family:'Inter',system-ui,-apple-system,Segoe UI,Arial,sans-serif;min-height:100dvh;display:grid;place-items:center;padding:24px;
  background:radial-gradient(950px 560px at 88% -12%,rgba(47,123,255,.22),transparent 56%),radial-gradient(780px 500px at 0% -6%,rgba(56,189,248,.15),transparent 55%),#0a0c16;background-attachment:fixed}
.box{position:relative;width:100%;max-width:920px;border:1px solid var(--bd2);border-radius:28px;padding:36px;overflow:hidden;
  background:rgba(18,21,38,.6);backdrop-filter:saturate(1.15) blur(18px);-webkit-backdrop-filter:saturate(1.15) blur(18px);
  box-shadow:0 30px 90px rgba(0,0,0,.5),inset 0 1px 0 rgba(255,255,255,.06)}
.box::before{content:"";position:absolute;inset:0 0 auto 0;height:3px;background:var(--grad)}
.top{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:28px}
.brand{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:46px;letter-spacing:-.03em;line-height:.95;
  background:var(--grad);-webkit-background-clip:text;background-clip:text;color:transparent}
.tag{font-family:'JetBrains Mono',monospace;color:#38bdf8;font-size:11px;letter-spacing:.22em;text-transform:uppercase;white-space:nowrap;
  border:1px solid var(--bd2);background:rgba(56,189,248,.08);border-radius:999px;padding:6px 12px}
.sub{color:var(--mut);line-height:1.6;max-width:650px;margin:0 0 26px;font-size:14px}
.cards{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px}
.card{position:relative;border:1px solid var(--bd);background:rgba(255,255,255,.04);border-radius:20px;padding:22px;min-height:160px;
  display:flex;flex-direction:column;justify-content:space-between;transition:.2s;overflow:hidden}
.card:hover{transform:translateY(-4px);border-color:var(--bd2);background:rgba(255,255,255,.07)}
.card b{font-size:17px;font-weight:700;letter-spacing:-.01em}
.card span{display:block;color:var(--mut);font-size:13px;line-height:1.5;margin-top:8px}
.btn{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;border-radius:13px;padding:12px 15px;font-weight:700;
  background:var(--grad);color:#fff;margin-top:16px;box-shadow:0 8px 22px rgba(47,123,255,.4)}
.btn:hover{filter:brightness(1.08)}
.ghost{background:rgba(255,255,255,.06);color:#eef0fb;border:1px solid var(--bd2);box-shadow:none}
.ghost:hover{background:rgba(255,255,255,.11)}
.warn{color:var(--mut);font-size:12px;margin-top:20px;line-height:1.6}
.warn code{color:#c8ddff;background:rgba(47,123,255,.12);border:1px solid var(--bd2);border-radius:7px;padding:2px 7px;font-family:'JetBrains Mono',monospace}
@media(max-width:820px){.cards{grid-template-columns:1fr}.brand{font-size:36px}.box{padding:26px}.top{display:block}.tag{display:inline-block;margin-top:12px}}
</style>
</head>
<body>
<main class="box">
  <div class="top">
    <div class="brand">ComfyW<br>LTX 2.3</div>
    <div class="tag">FULL CLEAN FTP</div>
  </div>
  <p class="sub">Kompletní čistá FTP verze pro ovládání ComfyUI. Obsahuje LTX 2.3 režim 1 PICT, 2 PICT první/poslední frejm, nový worker a reálnější stav renderu.</p>
  <section class="cards">
    <div class="card"><div><b>Otevřít aplikaci</b><span>Hlavní webové rozhraní ComfyW.</span></div><a class="btn" href="app.php">Otevřít</a></div>
    <div class="card"><div><b>Stáhnout worker</b><span>Po přihlášení stáhneš nový worker ZIP pro lokální ComfyUI.</span></div><a class="btn ghost" href="download_worker.php">Download</a></div>
    <div class="card"><div><b>Admin</b><span>Správa projektů, uživatelů a workerů.</span></div><a class="btn ghost" href="admin.php">Admin</a></div>
  </section>
  <div class="warn">Čistá databáze už má nové LTX projekty připravené. Soubor <code>UPDATE_LTX23_FTP.php</code> je jen nouzový a můžeš ho po nahrání smazat.</div>
</main>
</body>
</html>
