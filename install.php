<?php
// ============================================================
//  PZ COMFY VIDEO REMOTE — Instalace
//  Spusť jednou, pak SMAŽ install.php
// ============================================================
require_once __DIR__ . '/config.php';
pz_security_headers();

$success = [];
$errors  = [];
$warns   = [];

function ok($m){ global $success; $success[] = $m; }
function er($m){ global $errors; $errors[] = $m; }
function wa($m){ global $warns; $warns[] = $m; }

if (!extension_loaded('pdo')) er('PDO není dostupné.'); else ok('PDO: OK');
if (!extension_loaded('pdo_sqlite')) er('PDO SQLite není dostupné.'); else ok('PDO SQLite: OK');
if (!extension_loaded('fileinfo')) wa('PHP fileinfo není dostupné — MIME kontrola uploadů bude slabší.'); else ok('fileinfo: OK');

foreach ([UPLOAD_DIR, OUTPUT_DIR, TMP_DIR, WORKFLOW_DIR] as $dir) {
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) er('Nelze vytvořit složku: ' . $dir);
    }
    if (is_dir($dir) && is_writable($dir)) ok('Složka zapisovatelná: ' . basename($dir));
    else er('Složka není zapisovatelná: ' . $dir);
}

try {
    if (empty($errors)) {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA busy_timeout=5000');

        $pdo->exec("CREATE TABLE IF NOT EXISTS comfy_jobs (
            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
            prompt              TEXT NOT NULL,
            negative_prompt     TEXT,
            preset              TEXT,
            input_image         TEXT NOT NULL,
            input_original_name TEXT,
            output_video        TEXT,
            output_files        TEXT,
            settings_json       TEXT,
            comfy_prompt_id     TEXT,
            status              TEXT NOT NULL DEFAULT 'pending',
            progress            INTEGER NOT NULL DEFAULT 0,
            current_node        TEXT,
            error               TEXT,
            worker_id           TEXT,
            target_worker       TEXT,
            user_id             INTEGER,
            username            TEXT,
            ip                  TEXT,
            created_at          TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at          TEXT NOT NULL DEFAULT (datetime('now')),
            started_at          TEXT,
            finished_at         TEXT,
            duration_seconds    REAL
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS comfy_events (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            job_id     INTEGER NOT NULL,
            type       TEXT NOT NULL DEFAULT 'info',
            message    TEXT,
            data_json  TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
            ip         TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS comfy_users(id INTEGER PRIMARY KEY AUTOINCREMENT,username TEXT NOT NULL UNIQUE,password_hash TEXT NOT NULL,role TEXT NOT NULL DEFAULT 'user',active INTEGER NOT NULL DEFAULT 1,created_at TEXT NOT NULL DEFAULT(datetime('now')),last_login TEXT)");
        $st = $pdo->prepare("SELECT id FROM comfy_users WHERE username=?");
        $st->execute([LOGIN_USERNAME]);
        $uid = $st->fetchColumn();
        if ($uid) {
            $pdo->prepare("UPDATE comfy_users SET password_hash=?,role='admin',active=1 WHERE id=?")->execute([LOGIN_PASSWORD_HASH, $uid]);
        } else {
            $pdo->prepare("INSERT INTO comfy_users(username,password_hash,role,active) VALUES(?,?, 'admin', 1)")->execute([LOGIN_USERNAME, LOGIN_PASSWORD_HASH]);
        }
        $pdo->prepare("DELETE FROM comfy_users WHERE username<>?")->execute([LOGIN_USERNAME]);
        ok('Admin účet připraven: ' . LOGIN_USERNAME);

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comfy_jobs_status ON comfy_jobs(status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comfy_jobs_created ON comfy_jobs(created_at)');
        try { $pdo->exec("ALTER TABLE comfy_jobs ADD COLUMN target_worker TEXT"); } catch(Throwable $e) {}
        try { $pdo->exec("ALTER TABLE comfy_jobs ADD COLUMN user_id INTEGER"); } catch(Throwable $e) {}
        try { $pdo->exec("ALTER TABLE comfy_jobs ADD COLUMN username TEXT"); } catch(Throwable $e) {}
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comfy_jobs_user ON comfy_jobs(user_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comfy_jobs_user_status ON comfy_jobs(user_id,status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comfy_events_job ON comfy_events(job_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_rate_ip ON rate_limits(ip)');
        ok('Databáze vytvořena: ' . DB_PATH);
    }
} catch (Throwable $e) {
    er('Chyba databáze: ' . $e->getMessage());
}

if (file_exists(__DIR__ . '/.htaccess')) ok('.htaccess: OK'); else er('.htaccess chybí.');
if (!file_exists(WORKFLOW_DIR . '/ltx23_i2v_template.json')) wa('Chybí workflows/ltx23_i2v_template.json');

$allOk = empty($errors);
?>
<!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PZ Comfy — instalace</title>
<style>
body{margin:0;background:#0d0d0d;color:#ddd;font-family:Consolas,monospace;padding:32px;line-height:1.55}.box{max-width:820px;border:1px solid #2a2a2a;background:#141414;border-radius:10px;padding:22px}h1{color:#00e676;margin-top:0}.ok{color:#00e676}.er{color:#ff5252}.wa{color:#ffd740}a{color:#40c4ff}code{background:#222;padding:2px 5px;border-radius:4px}.next{margin-top:22px;padding:16px;border:1px solid #00e676;border-radius:8px}</style>
</head>
<body>
<div class="box">
<h1>⬡ PZ COMFY VIDEO REMOTE — instalace</h1>
<?php foreach($success as $m): ?><p class="ok">✓ <?=htmlspecialchars($m)?></p><?php endforeach; ?>
<?php foreach($warns as $m): ?><p class="wa">⚠ <?=htmlspecialchars($m)?></p><?php endforeach; ?>
<?php foreach($errors as $m): ?><p class="er">✗ <?=htmlspecialchars($m)?></p><?php endforeach; ?>
<?php if($allOk): ?>
<div class="next">
<p class="ok">Instalace hotová.</p>
<p>Teď otevři <a href="app.php">app.php</a> a přihlas se uživatelským jménem <code><?=htmlspecialchars(LOGIN_USERNAME)?></code>.</p>
<p class="er">Důležité: po ověření smaž z FTP soubor <code>install.php</code>.</p>
<pre>Lokálně:
1) Spusť ComfyUI na http://127.0.0.1:8000
   Pokud používáš port 8188, změň COMFY_BASE v .bat souboru.
2) Doma spusť: start_comfy_worker_HOME.bat
3) V práci spusť: start_comfy_worker_PRACE.bat
4) Workflow se bere chráněně přes API: api.php?action=default_workflow</pre>
</div>
<?php endif; ?>
</div>
</body>
</html>
