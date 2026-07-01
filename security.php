<?php
require_once __DIR__ . '/config.php';
pz_security_headers();
pz_start_secure_session();

if (empty($_SESSION['authenticated']) || empty($_SESSION['is_admin'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Přístup jen pro administrátora.";
    exit;
}

$msg = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!pz_verify_csrf()) {
        $msg = 'Neplatný bezpečnostní token formuláře.';
    } else {
        $act = (string)($_POST['action'] ?? '');
        if ($act === 'revoke_all') {
            $n = pz_revoke_all_worker_tokens();
            $msg = "Zrušené aktivní worker tokeny: " . $n;
        } elseif ($act === 'revoke_one') {
            $id = (int)($_POST['id'] ?? 0);
            $msg = pz_revoke_worker_token($id) ? "Token #$id byl zrušen." : "Token se nepodařilo zrušit.";
        }
    }
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function sec_scheme(): string {
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $p = strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']);
        if ($p === 'https' || $p === 'http') return $p;
    }
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
}
function sec_base_url(): string {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/comfy/security.php');
    $dir = rtrim(dirname($script), '/');
    return sec_scheme() . '://' . $host . ($dir === '' || $dir === '.' ? '' : $dir);
}
function http_probe(string $url): array {
    $code = 0; $body = ''; $err = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'PZ-Comfy-Security-Test',
        ]);
        $body = (string)curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true, 'header' => "User-Agent: PZ-Comfy-Security-Test\r\n"]]);
        $body = (string)@file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0]) && preg_match('~\s(\d{3})\s~', $http_response_header[0], $m)) $code = (int)$m[1];
        if ($body === '') $err = 'bez odpovědi nebo zakázáno';
    }
    return ['code' => $code, 'body' => $body, 'err' => $err];
}
function verdict_for_probe(string $path, array $r): array {
    $code = (int)$r['code'];
    $body = (string)$r['body'];
    $bad = false;
    if (in_array($code, [401,403,404,405,410], true)) return ['OK', 'Blokováno HTTP ' . $code];
    if (in_array($path, ['db.sqlite','db.sqlite-wal','db.sqlite-shm'], true) && (str_starts_with($body, 'SQLite format') || strlen($body) > 0)) $bad = true;
    if ($path === 'install.php' && $code === 200 && !str_contains($body, 'Instalace už proběhla')) $bad = true;
    if (str_contains($body, 'LOGIN_PASSWORD_HASH') || str_contains($body, 'WORKER_TOKEN_PEPPER')) $bad = true;
    if ($path === 'uploads/' && $code === 200) $bad = true;
    if ($path === 'outputs/' && $code === 200) $bad = true;
    if ($path === 'README.md' && $code === 200 && strlen($body) > 20) $bad = true;
    if ($bad) return ['RIZIKO', 'Vypadá veřejně dostupné'];
    if ($code === 0) return ['NEOVĚŘENO', $r['err'] ?: 'Server nevrátil HTTP kód'];
    return ['OK', 'Neobsahuje citlivý obsah / HTTP ' . $code];
}
$base = sec_base_url();
$tests = ['config.php', 'db.sqlite', 'db.sqlite-wal', 'db.sqlite-shm', 'install.php', 'uploads/', 'outputs/', 'cache/', 'tmp/', 'README.md'];
$probes = [];
foreach ($tests as $p) {
    $urlPath = implode('/', array_map('rawurlencode', explode('/', trim($p, '/'))));
    if (str_ends_with($p, '/')) $urlPath .= '/';
    $r = http_probe($base . '/' . $urlPath);
    $probes[$p] = [$r, verdict_for_probe($p, $r)];
}
$tokens = pz_list_worker_tokens();
$csrf = pz_csrf_token();
?>
<!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Zabezpečení — PZ COMFY</title>
<style>
body{margin:0;background:#08111f;color:#e8eef8;font-family:Inter,Arial,sans-serif}
.wrap{max-width:1100px;margin:0 auto;padding:22px}
.card{background:#101b2e;border:1px solid rgba(255,255,255,.12);border-radius:18px;padding:18px;margin:14px 0;box-shadow:0 12px 30px rgba(0,0,0,.25)}
h1{font-size:24px;margin:0 0 10px}
h2{font-size:18px;margin:0 0 12px}
a,.btn{color:#fff;background:#2563eb;border:0;border-radius:12px;padding:10px 13px;text-decoration:none;display:inline-block;cursor:pointer}
.btn.danger{background:#b91c1c}.btn.ghost{background:#26364f}
table{width:100%;border-collapse:collapse;font-size:14px}
th,td{border-bottom:1px solid rgba(255,255,255,.1);padding:9px;text-align:left;vertical-align:top}
.ok{color:#38d39f;font-weight:700}.risk{color:#ff6b6b;font-weight:700}.warn{color:#ffd166;font-weight:700}
.muted{color:#91a3bb}.msg{background:#17233a;border-radius:12px;padding:10px;margin:10px 0}
code{background:#09101e;padding:2px 6px;border-radius:6px}
</style>
</head>
<body><div class="wrap">
<h1>Zabezpečení PZ COMFY</h1>
<p><a class="btn ghost" href="app.php">← Zpět do aplikace</a> <a class="btn" href="download_worker.php">Stáhnout nový worker</a></p>
<?php if($msg): ?><div class="msg"><?=h($msg)?></div><?php endif; ?>

<div class="card">
<h2>Rychlý stav</h2>
<table>
<tr><th>Kontrola</th><th>Stav</th><th>Poznámka</th></tr>
<tr><td>HTTPS</td><td class="<?=pz_is_https()?'ok':'risk'?>"><?=pz_is_https()?'OK':'RIZIKO'?></td><td><?=pz_is_https()?'Web běží přes HTTPS.':'Web neběží přes HTTPS nebo proxy neposílá X-Forwarded-Proto.'?></td></tr>
<tr><td>Worker token v URL</td><td class="ok">OK</td><td>API už nepřijímá token přes <code>?token=</code>, jen přes hlavičku <code>X-API-Token</code> nebo Bearer.</td></tr>
<tr><td>Automazání starých výsledků</td><td class="ok">OK</td><td>Hotové/chybové/zrušené joby se mažou po <?=h(defined('AUTO_PURGE_FINISHED_AFTER_HOURS') ? AUTO_PURGE_FINISHED_AFTER_HOURS : 0)?> hodinách.</td></tr>
</table>
</div>

<div class="card">
<h2>Test veřejně dostupných citlivých cest</h2>
<table>
<tr><th>Cesta</th><th>Stav</th><th>HTTP</th><th>Poznámka</th></tr>
<?php foreach($probes as $path=>$row): [$r,$v]=$row; $cls=$v[0]==='OK'?'ok':($v[0]==='RIZIKO'?'risk':'warn'); ?>
<tr><td><code><?=h($path)?></code></td><td class="<?=$cls?>"><?=h($v[0])?></td><td><?=h($r['code'])?></td><td><?=h($v[1])?></td></tr>
<?php endforeach; ?>
</table>
<p class="muted">Když tu uvidíš RIZIKO, hosting ignoruje .htaccess nebo je špatně nastavený webserver.</p>
</div>

<div class="card">
<h2>Worker tokeny</h2>
<form method="post" onsubmit="return confirm('Opravdu zrušit všechny aktivní worker tokeny? Staré workery přestanou fungovat.');">
<input type="hidden" name="csrf_token" value="<?=h($csrf)?>">
<input type="hidden" name="action" value="revoke_all">
<button class="btn danger">Zrušit všechny worker tokeny</button>
</form>
<table>
<tr><th>ID</th><th>Název</th><th>Aktivní</th><th>Vytvořen</th><th>Poslední kontakt</th><th>IP</th><th>Expirace</th><th>Akce</th></tr>
<?php foreach($tokens as $t): ?>
<tr>
<td><?=h($t['id'])?></td>
<td><?=h($t['label'])?></td>
<td class="<?=(int)$t['active']===1?'ok':'warn'?>"><?=(int)$t['active']===1?'ano':'ne'?></td>
<td><?=h($t['created_at'])?></td>
<td><?=h($t['last_seen'] ?: '-')?></td>
<td><?=h($t['last_ip'] ?: '-')?></td>
<td><?=h($t['expires_at'] ?: '-')?></td>
<td>
<?php if((int)$t['active']===1): ?>
<form method="post" style="display:inline" onsubmit="return confirm('Zrušit tento worker token?');">
<input type="hidden" name="csrf_token" value="<?=h($csrf)?>">
<input type="hidden" name="action" value="revoke_one">
<input type="hidden" name="id" value="<?=h($t['id'])?>">
<button class="btn danger">Zrušit</button>
</form>
<?php else: ?><span class="muted">zrušeno</span><?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
<?php if(!$tokens): ?><tr><td colspan="8" class="muted">Zatím není vytvořený žádný worker token. Stáhni nový worker ZIP.</td></tr><?php endif; ?>
</table>
</div>
</div></body></html>
