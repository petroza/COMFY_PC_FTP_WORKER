<?php
// ============================================================
//  PZ COMFY VIDEO REMOTE — bezpečná čistá instalace
//  Spusť na čistém FTP jednou. Opakované veřejné otevření nic nemaže.
// ============================================================
require_once __DIR__ . '/config.php';
pz_security_headers();

$success = [];
$errors  = [];
$warns   = [];
function ok($m){ global $success; $success[] = $m; }
function er($m){ global $errors; $errors[] = $m; }
function wa($m){ global $warns; $warns[] = $m; }
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function install_has_users(): bool {
    if (!is_file(DB_PATH)) return false;
    try {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $exists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='comfy_users'")->fetchColumn();
        if (!$exists) return false;
        return ((int)$pdo->query("SELECT COUNT(*) FROM comfy_users")->fetchColumn()) > 0;
    } catch (Throwable $e) { return false; }
}

$alreadyInstalled = install_has_users();
$recheck = isset($_GET['recheck']) && $_GET['recheck'] === '1';
if ($alreadyInstalled && !$recheck) {
    http_response_code(410);
    ?><!doctype html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Instalace vypnutá</title><style>body{margin:0;background:#0d0d0d;color:#ddd;font-family:Consolas,monospace;padding:32px;line-height:1.55}.box{max-width:820px;border:1px solid #2a2a2a;background:#141414;border-radius:10px;padding:22px}h1{color:#00e676;margin-top:0}.wa{color:#ffd740}.ok{color:#00e676}a{color:#40c4ff}code{background:#222;padding:2px 5px;border-radius:4px}</style></head><body><div class="box"><h1>Instalace už proběhla</h1><p class="ok">Bezpečnostní režim: opakované otevření <code>install.php</code> nic nemaže ani neresetuje.</p><p class="wa">Doporučení: po ověření aplikace smaž <code>install.php</code> z FTP.</p><p><a href="app.php">Otevřít aplikaci</a> · <a href="install.php?recheck=1">Pouze znovu zkontrolovat složky a schéma</a></p></div></body></html><?php
    exit;
}

if (!extension_loaded('pdo')) er('PDO není dostupné.'); else ok('PDO: OK');
if (!extension_loaded('pdo_sqlite')) er('PDO SQLite není dostupné.'); else ok('PDO SQLite: OK');
if (!extension_loaded('fileinfo')) wa('PHP fileinfo není dostupné — MIME kontrola uploadů bude slabší.'); else ok('fileinfo: OK');

foreach ([UPLOAD_DIR, OUTPUT_DIR, TMP_DIR, WORKFLOW_DIR, __DIR__ . '/cache', __DIR__ . '/tmp_worker', __DIR__ . '/project_workflows'] as $dir) {
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) er('Nelze vytvořit složku: ' . $dir);
    }
    if (is_dir($dir) && is_writable($dir)) ok('Složka zapisovatelná: ' . basename($dir));
    else er('Složka není zapisovatelná: ' . $dir);
}

function install_import_projects(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS comfy_projects(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL,description TEXT,workflow_file TEXT,input_type TEXT NOT NULL DEFAULT 'image',settings_json TEXT,active INTEGER NOT NULL DEFAULT 1,sort_order INTEGER NOT NULL DEFAULT 0,created_at TEXT NOT NULL DEFAULT(datetime('now')),updated_at TEXT NOT NULL DEFAULT(datetime('now')))");
    $jsonPath = __DIR__ . '/project_workflows/projects_ltx23_update.json';
    $items = [];
    if (is_file($jsonPath)) {
        $decoded = json_decode((string)file_get_contents($jsonPath), true);
        if (is_array($decoded)) $items = $decoded;
    }
    if (!$items) {
        $items = [
            ['id'=>'ltx23_i2v','name'=>'LTX 2.3 nový model i2v / 1 PICT','description'=>'LTX 2.3 image-to-video workflow pro jednu vstupní fotku.','file'=>'workflows/ltx23_i2v_template.json','sort_order'=>10,'mapping'=>['mode'=>'1pict']],
            ['id'=>'ltx23_flf2v','name'=>'LTX 2.3 první + poslední frejm / 2 PICT','description'=>'LTX 2.3 FLF2V workflow pro první a poslední frejm / dvě vstupní fotky.','file'=>'workflows/ltx23_flf2v_template.json','sort_order'=>20,'mapping'=>['mode'=>'2pict']],
        ];
    }
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $file = trim((string)($item['file'] ?? $item['workflow'] ?? ''));
        if ($file === '' || !is_file(__DIR__ . '/' . ltrim($file, '/'))) continue;
        $pid = (string)($item['id'] ?? '');
        $mode = strtolower((string)($item['mapping']['mode'] ?? ''));
        $is2 = $pid === 'ltx23_flf2v' || $mode === '2pict' || str_contains(strtolower($file), 'flf2v');
        $name = trim((string)($item['name'] ?? ($is2 ? 'LTX 2.3 první + poslední frejm / 2 PICT' : 'LTX 2.3 nový model i2v / 1 PICT')));
        $desc = trim((string)($item['description'] ?? ($is2 ? 'LTX 2.3 FLF2V workflow pro první a poslední frejm / dvě vstupní fotky.' : 'LTX 2.3 image-to-video workflow pro jednu vstupní fotku.')));
        $sort = (int)($item['sort_order'] ?? ($is2 ? 20 : 10));
        $settings = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $st = $pdo->prepare("SELECT id FROM comfy_projects WHERE workflow_file=? LIMIT 1");
        $st->execute([$file]);
        $existingId = (int)($st->fetchColumn() ?: 0);
        if ($existingId) {
            $st = $pdo->prepare("UPDATE comfy_projects SET name=?, description=?, workflow_file=?, input_type='image', settings_json=?, active=1, sort_order=?, updated_at=datetime('now') WHERE id=?");
            $st->execute([$name, $desc, $file, $settings, $sort, $existingId]);
        } else {
            $st = $pdo->prepare("INSERT INTO comfy_projects(name,description,workflow_file,input_type,settings_json,active,sort_order) VALUES(?,?,?,?,?,?,?)");
            $st->execute([$name, $desc, $file, 'image', $settings, 1, $sort]);
        }
    }
}

try {
    if (empty($errors)) {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA synchronous=NORMAL');
        $pdo->exec('PRAGMA busy_timeout=10000');

        $pdo->exec("CREATE TABLE IF NOT EXISTS comfy_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            prompt TEXT NOT NULL,
            negative_prompt TEXT,
            preset TEXT,
            input_image TEXT NOT NULL,
            input_original_name TEXT,
            output_video TEXT,
            output_files TEXT,
            settings_json TEXT,
            comfy_prompt_id TEXT,
            status TEXT NOT NULL DEFAULT 'pending',
            progress INTEGER NOT NULL DEFAULT 0,
            current_node TEXT,
            error TEXT,
            worker_id TEXT,
            target_worker TEXT,
            user_id INTEGER,
            username TEXT,
            ip TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now')),
            started_at TEXT,
            finished_at TEXT,
            duration_seconds REAL,
            project_id INTEGER
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS comfy_events (id INTEGER PRIMARY KEY AUTOINCREMENT, job_id INTEGER NOT NULL, type TEXT NOT NULL DEFAULT 'info', message TEXT, data_json TEXT, created_at TEXT NOT NULL DEFAULT (datetime('now')))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (ip TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT (datetime('now')))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS comfy_users(id INTEGER PRIMARY KEY AUTOINCREMENT,username TEXT NOT NULL UNIQUE,password_hash TEXT NOT NULL,role TEXT NOT NULL DEFAULT 'user',active INTEGER NOT NULL DEFAULT 1,created_at TEXT NOT NULL DEFAULT(datetime('now')),last_login TEXT)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS comfy_worker_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, token_hash TEXT NOT NULL UNIQUE, label TEXT, active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL DEFAULT(datetime('now')), created_by TEXT, last_seen TEXT, last_ip TEXT, last_user_agent TEXT, revoked_at TEXT, expires_at TEXT)");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comfy_jobs_status ON comfy_jobs(status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comfy_jobs_created ON comfy_jobs(created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comfy_events_job ON comfy_events(job_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_rate_ip ON rate_limits(ip)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comfy_jobs_user ON comfy_jobs(user_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comfy_jobs_user_status ON comfy_jobs(user_id,status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_worker_tokens_active ON comfy_worker_tokens(active, expires_at)');
        install_import_projects($pdo);
        ok('Databáze a projekty připravené: ' . DB_PATH);

        $users = (int)$pdo->query("SELECT COUNT(*) FROM comfy_users")->fetchColumn();
        if ($users <= 0) {
            $pdo->prepare("INSERT INTO comfy_users(username,password_hash,role,active) VALUES(?,?, 'admin', 1)")->execute([LOGIN_USERNAME, LOGIN_PASSWORD_HASH]);
            ok('Admin účet vytvořen: ' . LOGIN_USERNAME);
        } else {
            wa('Uživatelé už existují — hesla ani účty jsem neměnil.');
        }
    }
} catch (Throwable $e) {
    er('Chyba databáze: ' . $e->getMessage());
}

if (file_exists(__DIR__ . '/.htaccess')) ok('.htaccess: OK'); else er('.htaccess chybí.');
if (!file_exists(WORKFLOW_DIR . '/ltx23_i2v_template.json')) wa('Chybí workflows/ltx23_i2v_template.json');
if (!file_exists(WORKFLOW_DIR . '/ltx23_flf2v_template.json')) wa('Chybí workflows/ltx23_flf2v_template.json');

$allOk = empty($errors);
?><!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PZ Comfy — instalace</title>
<style>
body{margin:0;background:#0d0d0d;color:#ddd;font-family:Consolas,monospace;padding:32px;line-height:1.55}.box{max-width:860px;border:1px solid #2a2a2a;background:#141414;border-radius:10px;padding:22px}h1{color:#00e676;margin-top:0}.ok{color:#00e676}.er{color:#ff5252}.wa{color:#ffd740}a{color:#40c4ff}code{background:#222;padding:2px 5px;border-radius:4px}.next{margin-top:22px;padding:16px;border:1px solid #00e676;border-radius:8px}</style>
</head>
<body><div class="box">
<h1>⬡ PZ COMFY VIDEO REMOTE — čistá instalace</h1>
<?php foreach($success as $m): ?><p class="ok">✓ <?=h($m)?></p><?php endforeach; ?>
<?php foreach($warns as $m): ?><p class="wa">⚠ <?=h($m)?></p><?php endforeach; ?>
<?php foreach($errors as $m): ?><p class="er">✗ <?=h($m)?></p><?php endforeach; ?>
<?php if($allOk): ?>
<div class="next">
<p class="ok">Instalace je připravená.</p>
<p>Otevři <a href="app.php">app.php</a> a přihlas se uživatelským jménem <code><?=h(LOGIN_USERNAME)?></code>.</p>
<p class="er">Po ověření smaž z FTP soubor <code>install.php</code>. Když ho někdo otevře znovu, už nic nemaže, ale lepší je ho odstranit.</p>
</div>
<?php endif; ?>
</div></body></html>
