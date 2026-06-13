<?php
// ============================================================
//  PZ COMFY VIDEO REMOTE — REST API
//  /comfy/api.php?action=<action>
// ============================================================
require_once __DIR__ . '/config.php';
const EXPECTED_WORKER_VERSION = '2026-06-10-v7-polish-diagnostics';

ini_set('display_errors', '0');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

pz_security_headers();
pz_start_secure_session();

function json_out(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(string $message, int $code = 400, array $extra = []): never {
    json_out(array_merge(['success' => false, 'error' => $message], $extra), $code);
}
function db(): PDO {
    static $pdo = null;
    if (!$pdo) {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA synchronous=NORMAL');
        $pdo->exec('PRAGMA busy_timeout=10000');
        ensure_schema($pdo);
    }
    return $pdo;
}
function ensure_schema(PDO $pdo): void {
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
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        job_id INTEGER NOT NULL,
        type TEXT NOT NULL DEFAULT 'info',
        message TEXT,
        data_json TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (ip TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT (datetime('now')))");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comfy_jobs_status ON comfy_jobs(status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comfy_jobs_created ON comfy_jobs(created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comfy_events_job ON comfy_events(job_id)');
    try { $pdo->exec("ALTER TABLE comfy_jobs ADD COLUMN target_worker TEXT"); } catch(Throwable $e) {}
    try { $pdo->exec("ALTER TABLE comfy_jobs ADD COLUMN user_id INTEGER"); } catch(Throwable $e) {}
    try { $pdo->exec("ALTER TABLE comfy_jobs ADD COLUMN username TEXT"); } catch(Throwable $e) {}
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comfy_jobs_user ON comfy_jobs(user_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comfy_jobs_user_status ON comfy_jobs(user_id,status)');

    $pdo->exec("CREATE TABLE IF NOT EXISTS comfy_projects(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL,description TEXT,workflow_file TEXT,input_type TEXT NOT NULL DEFAULT 'image',settings_json TEXT,active INTEGER NOT NULL DEFAULT 1,sort_order INTEGER NOT NULL DEFAULT 0,created_at TEXT NOT NULL DEFAULT(datetime('now')),updated_at TEXT NOT NULL DEFAULT(datetime('now')))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS comfy_users(id INTEGER PRIMARY KEY AUTOINCREMENT,username TEXT NOT NULL UNIQUE,password_hash TEXT NOT NULL,role TEXT NOT NULL DEFAULT 'user',active INTEGER NOT NULL DEFAULT 1,created_at TEXT NOT NULL DEFAULT(datetime('now')),last_login TEXT)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_projects_active ON comfy_projects(active)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_users_username ON comfy_users(username)");
    try{$pdo->exec("ALTER TABLE comfy_jobs ADD COLUMN project_id INTEGER");}catch(Throwable $e){}
    ensure_ltx_default_projects($pdo);

}
function ensure_ltx_default_projects(PDO $pdo): void {
    // V update balíku nepřepisujeme db.sqlite, takže stará databáze nemusí znát 2 PICT projekt.
    // Tohle při běžném načtení API automaticky doplní/aktualizuje LTX 2.3 projekty bez mazání fronty.
    try {
        $jsonPath = __DIR__ . '/project_workflows/projects_ltx23_update.json';
        $items = [];
        if (is_file($jsonPath)) {
            $decoded = json_decode((string)file_get_contents($jsonPath), true);
            if (is_array($decoded)) $items = $decoded;
        }
        if (!$items) {
            $items = [
                [
                    'id' => 'ltx23_i2v',
                    'name' => 'LTX 2.3 nový model i2v / 1 PICT',
                    'description' => 'LTX 2.3 image-to-video workflow pro jednu vstupní fotku.',
                    'file' => 'workflows/ltx23_i2v_template.json',
                    'sort_order' => 10,
                    'mapping' => ['mode' => '1pict'],
                ],
                [
                    'id' => 'ltx23_flf2v',
                    'name' => 'LTX 2.3 první + poslední frejm / 2 PICT',
                    'description' => 'LTX 2.3 FLF2V workflow pro první a poslední frejm / dvě vstupní fotky.',
                    'file' => 'workflows/ltx23_flf2v_template.json',
                    'sort_order' => 20,
                    'mapping' => ['mode' => '2pict'],
                ],
            ];
        }
        foreach ($items as $idx => $item) {
            if (!is_array($item)) continue;
            $file = trim((string)($item['file'] ?? $item['workflow'] ?? ''));
            if ($file === '') continue;
            if (!is_file(__DIR__ . '/' . ltrim($file, '/'))) continue;

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

            if (!$existingId && $pid !== '') {
                $like = '%"id":"' . str_replace(['%', '_'], ['\\%', '\\_'], $pid) . '"%';
                $st = $pdo->prepare("SELECT id FROM comfy_projects WHERE settings_json LIKE ? ESCAPE '\\' LIMIT 1");
                $st->execute([$like]);
                $existingId = (int)($st->fetchColumn() ?: 0);
            }

            if ($existingId) {
                $st = $pdo->prepare("UPDATE comfy_projects SET name=?, description=?, workflow_file=?, input_type='image', settings_json=?, active=1, sort_order=?, updated_at=datetime('now') WHERE id=?");
                $st->execute([$name, $desc, $file, $settings, $sort, $existingId]);
            } else {
                $st = $pdo->prepare("INSERT INTO comfy_projects(name,description,workflow_file,input_type,settings_json,active,sort_order) VALUES(?,?,?,?,?,?,?)");
                $st->execute([$name, $desc, $file, 'image', $settings, 1, $sort]);
            }
        }
    } catch (Throwable $e) {
        // Import projektů nesmí shodit aplikaci. Diagnostika případný problém ukáže zvlášť.
    }
}

function is_logged(): bool { return !empty($_SESSION['authenticated']); }
function token_ok(): bool {
    // Worker token se bere jen z HTTP hlavičky. Nikdy z URL.
    $h = trim((string)($_SERVER['HTTP_X_API_TOKEN'] ?? ''));
    if ($h === '') {
        $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        if (preg_match('~^Bearer\s+(.+)$~i', $auth, $m)) $h = trim($m[1]);
    }
    return pz_verify_worker_token($h);
}
function require_session(): void { if (!is_logged()) fail('Nepřihlášeno.', 401, ['auth_expired' => true]); }
function require_csrf(): void { if (!pz_verify_csrf()) fail('Neplatný bezpečnostní token formuláře.', 403, ['auth_expired' => !is_logged()]); }
function require_token(): void { if (!token_ok()) fail('Neplatný worker token.', 401); }
function require_any(): void { if (!is_logged() && !token_ok()) fail('Nepřihlášeno.', 401, ['auth_expired' => true]); }
function is_admin_session(): bool { return is_logged() && (!empty($_SESSION['is_admin']) || (($_SESSION['role'] ?? '') === 'admin')); }
function current_user_id(): ?int {
    if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] === '' || $_SESSION['user_id'] === null) return null;
    $id = (int)$_SESSION['user_id'];
    return $id > 0 ? $id : null;
}
function current_username(): string { return trim((string)($_SESSION['username'] ?? '')); }
function worker_token_only(): bool { return token_ok() && !is_logged(); }
function master_admin_session(): bool { return is_admin_session() && current_user_id() === null && current_username() === ''; }
function global_job_access(): bool {
    // Worker vidí vše kvůli frontě. Admin účet vidí vše v aplikaci i adminu.
    return worker_token_only() || is_admin_session();
}
function can_access_job(array $job): bool {
    if (global_job_access()) return true;
    $uid = current_user_id();
    if (!$uid) return false;
    if (isset($job['user_id']) && (int)$job['user_id'] === $uid) return true;
    $username = current_username();
    return $username !== '' && empty($job['user_id']) && isset($job['username']) && hash_equals($username, (string)$job['username']);
}
function scoped_job_where(string $base = '', array $params = []): array {
    $base = trim($base);
    if (global_job_access()) return [$base !== '' ? $base : '1=1', $params];
    $uid = current_user_id();
    if (!$uid) return [$base !== '' ? "($base) AND 0=1" : '0=1', $params];
    $username = current_username();
    if ($username !== '') {
        $scope = '(user_id=? OR (user_id IS NULL AND username=?))';
        $scopeParams = [$uid, $username];
    } else {
        $scope = 'user_id=?';
        $scopeParams = [$uid];
    }
    if ($base !== '') return ["($base) AND $scope", array_merge($params, $scopeParams)];
    return [$scope, $scopeParams];
}
function queue_counts(): array {
    $counts = ['pending'=>0,'processing'=>0,'queued'=>0,'generating'=>0,'uploading'=>0,'downloading'=>0,'done_today'=>0,'active_total'=>0,'finished_total'=>0];
    try {
        $rows = db()->query("SELECT status, COUNT(*) AS c FROM comfy_jobs GROUP BY status")->fetchAll();
        foreach ($rows as $r) {
            $status = (string)($r['status'] ?? '');
            $count = (int)($r['c'] ?? 0);
            if (array_key_exists($status, $counts)) $counts[$status] = $count;
            if (in_array($status, ['pending','processing','queued','generating','uploading','downloading'], true)) $counts['active_total'] += $count;
            if (in_array($status, ['done','error','cancelled'], true)) $counts['finished_total'] += $count;
        }
        $counts['done_today'] = (int)db()->query("SELECT COUNT(*) FROM comfy_jobs WHERE status='done' AND created_at >= datetime('now','start of day')")->fetchColumn();
    } catch (Throwable $e) {}
    return $counts;
}
function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? 'unknown'; }
function base_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme . '://' . $host . ($dir ? $dir : '');
}
function public_url(?string $rel): ?string {
    if (!$rel) return null;
    return base_url() . '/' . ltrim($rel, '/');
}
function safe_join(string $base, string $rel): string {
    $rel = str_replace('\\', '/', trim($rel));
    if ($rel === '' || str_starts_with($rel, '/') || preg_match('~^[A-Za-z]:~', $rel) || preg_match('~(^|/)\.\.(/|$)~', $rel)) {
        fail('Neplatná cesta k souboru.', 400);
    }
    $base = rtrim($base, DIRECTORY_SEPARATOR);
    $full = $base . DIRECTORY_SEPARATOR . ltrim($rel, DIRECTORY_SEPARATOR);
    $baseReal = realpath($base);
    $dirReal = realpath(dirname($full));
    if ($baseReal && $dirReal && strncmp($dirReal, $baseReal, strlen($baseReal)) !== 0) {
        fail('Neplatná cesta mimo aplikaci.', 400);
    }
    return $full;
}
function add_event(int $jobId, string $type, string $message, $data = null): void {
    try {
        db()->prepare("INSERT INTO comfy_events(job_id,type,message,data_json) VALUES(?,?,?,?)")
            ->execute([$jobId, $type, $message, $data === null ? null : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    } catch (Throwable $e) {}
}
function clean_text(string $s, int $max): string {
    $s = trim($s);
    if (mb_strlen($s) > $max) $s = mb_substr($s, 0, $max);
    return $s;
}

function http_get_text(string $url, int $timeout = 12): ?string {
    // Jednoduchý HTTP GET pro překladač. Funguje s cURL, případně fallback na file_get_contents.
    try {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => min(6, $timeout),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT => 'PZ-Comfy-Remote/1.0',
            ]);
            $out = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($out !== false && $code >= 200 && $code < 300) return (string)$out;
            return null;
        }
        $ctx = stream_context_create([
            'http' => ['timeout' => $timeout, 'header' => "User-Agent: PZ-Comfy-Remote/1.0\r\n"],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $out = @file_get_contents($url, false, $ctx);
        return $out === false ? null : (string)$out;
    } catch (Throwable $e) {
        return null;
    }
}
function translate_text_online(string $text, string $source = 'cs', string $target = 'en'): array {
    $text = trim($text);
    if ($text === '') return ['success' => true, 'translated' => '', 'provider' => 'none'];
    $source = preg_replace('~[^a-z\-]~i', '', $source ?: 'auto') ?: 'auto';
    $target = preg_replace('~[^a-z\-]~i', '', $target ?: 'en') ?: 'en';
    $providersTried = [];

    // 1) Google GTX
    $url = 'https://translate.googleapis.com/translate_a/single?client=gtx'
        . '&sl=' . rawurlencode($source)
        . '&tl=' . rawurlencode($target)
        . '&dt=t&q=' . rawurlencode($text);
    $providersTried[] = 'google_gtx';
    $json = http_get_text($url, TRANSLATE_TIMEOUT_SECONDS);
    if ($json) {
        $data = json_decode($json, true);
        if (is_array($data) && isset($data[0]) && is_array($data[0])) {
            $translated = '';
            foreach ($data[0] as $part) {
                if (isset($part[0])) $translated .= (string)$part[0];
            }
            $translated = trim($translated);
            if ($translated !== '') {
                return ['success' => true, 'translated' => $translated, 'provider' => 'google_gtx', 'providers_tried' => $providersTried];
            }
        }
    }

    // 2) Google Chrome endpoint
    $url2 = 'https://clients5.google.com/translate_a/t?client=dict-chrome-ex'
        . '&sl=' . rawurlencode($source)
        . '&tl=' . rawurlencode($target)
        . '&q=' . rawurlencode($text);
    $providersTried[] = 'google_clients5';
    $json2 = http_get_text($url2, TRANSLATE_TIMEOUT_SECONDS);
    if ($json2) {
        $data2 = json_decode($json2, true);
        if (is_array($data2)) {
            $translated = trim((string)($data2['sentences'][0]['trans'] ?? $data2[0] ?? ''));
            if ($translated !== '') {
                return ['success' => true, 'translated' => $translated, 'provider' => 'google_clients5', 'providers_tried' => $providersTried];
            }
        }
    }

    // 3) MyMemory
    $url3 = 'https://api.mymemory.translated.net/get?q=' . rawurlencode($text)
        . '&langpair=' . rawurlencode($source . '|' . $target);
    $providersTried[] = 'mymemory';
    $json3 = http_get_text($url3, TRANSLATE_TIMEOUT_SECONDS);
    if ($json3) {
        $data3 = json_decode($json3, true);
        $translated = trim((string)($data3['responseData']['translatedText'] ?? ''));
        if ($translated !== '') {
            return ['success' => true, 'translated' => $translated, 'provider' => 'mymemory', 'providers_tried' => $providersTried];
        }
    }

    return ['success' => false, 'translated' => '', 'provider' => 'none', 'providers_tried' => $providersTried, 'error' => 'Selhal Google GTX, Google fallback i MyMemory.'];
}
function build_comfy_prompt(string $prompt, string $preset, string $cameraMotion): string {
    // Pozn.: kdysi vracel slepenec "prompt, style preset: X, camera motion: Y" pro
    // server-side překlad. To bylo špatně — presety jsou EN a překládat se nemají.
    // Worker (worker_comfy.py) si finální prompt skládá sám z prompt + camera_motion
    // + style + tech_quality. Sem proto vracíme jen samotný uživatelský prompt.
    return trim($prompt);
}

function camera_preset_text(string $preset): string {
    $map = [
        'Decentní nájezd dopředu' => 'the camera pushes in only slightly toward the subject in a restrained and minimal slow dolly forward, the framing tightens just a touch over the duration, smooth, stabilized and continuous',
        'Pomalý nájezd dopředu' => 'the camera slowly pushes in toward the subject in a smooth dolly forward, gradually tightening the framing, stabilized and continuous',
        'Pomalý odjezd dozadu' => 'the camera slowly pulls back from the subject in a smooth dolly out, gradually revealing more of the surrounding environment, stabilized and continuous',
        'Obíhání kolem objektu' => 'the camera circles slowly around the subject in a smooth orbital motion, the subject stays centered in frame, steady continuous parallax',
        'Půlkruhový oblouk' => 'the camera arcs around the subject in a controlled half-circle, smooth and stabilized, gradually revealing the subject from a new angle',
        'Stoupání kamery (dron nahoru)' => 'the camera rises upward in a smooth aerial drone movement, gradually revealing the wider landscape below, stabilized and continuous',
        'Klesání kamery (pohled dolů)' => 'the camera descends slowly from a high overhead view looking straight down at the scene, smooth aerial motion, stabilized',
        'Jeřáb nahoru' => 'the camera cranes upward in a slow controlled vertical rise, the subject remains in frame, smooth and continuous',
        'Jeřáb dolů' => 'the camera cranes downward in a slow controlled vertical descent, smooth and stabilized, gradually framing the subject from a lower angle',
        'Pomalý posun do strany' => 'the camera tracks slowly to the side in a smooth horizontal dolly parallel to the subject, stabilized and continuous',
        'Statická kamera (stativ)' => 'the camera holds completely still on a locked-off tripod, no camera movement, only the subject and the environment evolve over time',
        'Jemný posun (drobný drift)' => 'the camera drifts with very subtle, almost imperceptible motion, minimal parallax, breathing-like and stabilized',
        'Z ruky (dokumentární)' => 'the camera follows in a natural handheld documentary style, slight organic motion, observational and credible, lightly stabilized but not locked',
    ];
    return $map[$preset] ?? '';
}

function job_file_url(int $jobId, string $kind): string {
    // Bez tokenu v URL. Web používá session cookie, worker posílá X-API-Token hlavičku.
    return base_url() . '/api.php?action=job_file&id=' . $jobId . '&kind=' . rawurlencode($kind);
}
function job_row_to_public(array $j): array {
    $id = (int)($j['id'] ?? 0);
    $j['settings'] = $j['settings_json'] ? (json_decode($j['settings_json'], true) ?: []) : [];
    $j['output_files_list'] = $j['output_files'] ? (json_decode($j['output_files'], true) ?: []) : [];
    $j['input_url'] = !empty($j['input_image']) && $id ? job_file_url($id, 'input') : null;
    $j['input2_url'] = (!empty($j['settings']['input_image_2']) && $id) ? job_file_url($id, 'input2') : null;
    $j['output_url'] = !empty($j['output_video']) && $id ? job_file_url($id, 'output') : null;
    unset($j['settings_json']);
    if (!global_job_access()) {
        unset($j['ip'], $j['user_id'], $j['username']);
    }
    return $j;
}
function send_job_file(array $job, string $kind): never {
    if (!can_access_job($job)) fail('Nemáte oprávnění k tomuto souboru.', 403);
    $settingsForFile = !empty($job['settings_json']) ? (json_decode((string)$job['settings_json'], true) ?: []) : [];
    if ($kind === 'input') $rel = (string)($job['input_image'] ?? '');
    else if ($kind === 'input2') $rel = (string)($settingsForFile['input_image_2'] ?? '');
    else $rel = (string)($job['output_video'] ?? '');
    if ($rel === '') fail('Soubor není k dispozici.', 404);
    $path = safe_join(__DIR__, $rel);
    if (!is_file($path)) fail('Soubor neexistuje.', 404);
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mimeMap = ['mp4'=>'video/mp4','mov'=>'video/quicktime','webm'=>'video/webm','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];
    $mime = $mimeMap[$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($path));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    $name = basename($path);
    $disp = $kind === 'output' ? 'attachment' : 'inline';
    if (isset($_GET['inline']) && $_GET['inline'] === '1') $disp = 'inline';
    header('Content-Disposition: ' . $disp . '; filename="' . addslashes($name) . '"');
    readfile($path);
    exit;
}
function check_rate_limit_bulk(int $count = 1): void {
    $pdo = db();
    $ip = client_ip();
    $count = max(1, min(500, $count));
    $pdo->prepare("DELETE FROM rate_limits WHERE created_at < datetime('now','-1 hour')")->execute();
    $st = $pdo->prepare("SELECT COUNT(*) c FROM rate_limits WHERE ip=?");
    $st->execute([$ip]);
    $used = (int)$st->fetchColumn();
    if ($used + $count > RATE_LIMIT_PER_HOUR) {
        fail('Rate limit — moc jobů za hodinu.', 429, ['limit' => RATE_LIMIT_PER_HOUR, 'used' => $used, 'requested' => $count]);
    }
    $ins = $pdo->prepare("INSERT INTO rate_limits(ip) VALUES(?)");
    for ($i = 0; $i < $count; $i++) $ins->execute([$ip]);
}
function check_rate_limit(): void { check_rate_limit_bulk(1); }
function validate_image_upload(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) fail('Upload obrázku selhal.');
    if (($file['size'] ?? 0) <= 0 || $file['size'] > MAX_IMAGE_BYTES) fail('Obrázek je moc velký nebo prázdný.');
    $allowed = unserialize(ALLOWED_IMAGE_MIME);
    $mime = null;
    if (extension_loaded('fileinfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
    }
    if (!$mime) $mime = $file['type'] ?? '';
    if (!isset($allowed[$mime])) fail('Nepovolený typ obrázku: ' . $mime);
    return [$mime, $allowed[$mime]];
}
function validate_image_upload_or_throw(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Upload obrázku selhal.');
    if (($file['size'] ?? 0) <= 0 || $file['size'] > MAX_IMAGE_BYTES) throw new RuntimeException('Obrázek je moc velký nebo prázdný.');
    $allowed = unserialize(ALLOWED_IMAGE_MIME);
    $mime = null;
    if (extension_loaded('fileinfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
    }
    if (!$mime) $mime = $file['type'] ?? '';
    if (!isset($allowed[$mime])) throw new RuntimeException('Nepovolený typ obrázku: ' . $mime);
    return [$mime, $allowed[$mime]];
}
function image_file_from_multi(array $files, int $i): array {
    return [
        'name' => $files['name'][$i] ?? ('image_' . ($i + 1)),
        'type' => $files['type'][$i] ?? '',
        'tmp_name' => $files['tmp_name'][$i] ?? '',
        'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
        'size' => $files['size'][$i] ?? 0,
    ];
}
function prepare_job_settings(array $settings, string $preset, string &$prompt, string &$negative): array {
    $settings['width']  = max(256, min(4096, (int)($settings['width'] ?? 1280)));
    $settings['height'] = max(256, min(4096, (int)($settings['height'] ?? 720)));
    $settings['fps']    = max(1, min(60, (int)($settings['fps'] ?? 25)));
    $settings['duration'] = max(1, min(60, (float)($settings['duration'] ?? 5)));
    $settings['frame_count'] = max(1, min(3600, (int)round($settings['fps'] * $settings['duration'])));
    $settings['steps']  = max(1, min(200, (int)($settings['steps'] ?? 30)));
    $settings['cfg']    = max(0, min(30, (float)($settings['cfg'] ?? 3.5)));
    $settings['motion_strength'] = max(0, min(2, (float)($settings['motion_strength'] ?? 0.75)));
    $settings['prompt_enhance'] = !empty($settings['prompt_enhance']);
    $settings['enhance_tokens'] = max(64, min(512, (int)($settings['enhance_tokens'] ?? 128)));
    $settings['seed']   = isset($settings['seed']) && $settings['seed'] !== '' ? max(1, min(2147483647, (int)$settings['seed'])) : random_int(1, 2147483647);
    $sm = (string)($settings['seed_mode'] ?? 'increment_batch');
    $settings['seed_mode'] = in_array($sm, ['increment_batch','locked','random_each'], true) ? $sm : 'increment_batch';
    $settings['camera_motion'] = clean_text((string)($settings['camera_motion'] ?? ''), 1000);
    if ($settings['camera_motion'] === '') $settings['camera_motion'] = clean_text(camera_preset_text($preset), 1000);
    $settings['style'] = clean_text((string)($settings['style'] ?? ''), 1000);

    $translateEnabled = (bool)($settings['translate_prompt'] ?? (defined('TRANSLATE_PROMPT_DEFAULT') ? TRANSLATE_PROMPT_DEFAULT : true));
    $originalPrompt = clean_text((string)($settings['original_prompt'] ?? $prompt), 6000);
    $originalNegative = clean_text((string)($settings['original_negative_prompt'] ?? $negative), 4000);
    $settings['original_prompt'] = $originalPrompt !== '' ? $originalPrompt : $prompt;
    $settings['original_negative_prompt'] = $originalNegative;
    $settings['translated'] = !empty($settings['translated']);
    $settings['translation_provider'] = isset($settings['translation_provider']) ? clean_text((string)$settings['translation_provider'], 80) : null;
    if ($translateEnabled) {
        $source = defined('TRANSLATE_SOURCE_LANG') ? TRANSLATE_SOURCE_LANG : 'cs';
        $target = defined('TRANSLATE_TARGET_LANG') ? TRANSLATE_TARGET_LANG : 'en';
        $mainForTranslation = build_comfy_prompt($settings['original_prompt'], $preset, $settings['camera_motion']);
        $tr = translate_text_online($mainForTranslation, $source, $target);
        if (!empty($tr['success']) && trim((string)$tr['translated']) !== '') {
            $prompt = clean_text((string)$tr['translated'], 6000);
            $settings['translated'] = true;
            $settings['translation_provider'] = $tr['provider'] ?? 'online';
        }
        if ($settings['original_negative_prompt'] !== '') {
            $negTr = translate_text_online($settings['original_negative_prompt'], $source, $target);
            if (!empty($negTr['success']) && trim((string)$negTr['translated']) !== '') $negative = clean_text((string)$negTr['translated'], 4000);
        }
    }
    return $settings;
}
function delete_file_rel(?string $rel): bool {
    if (!$rel) return false;
    $rel = ltrim($rel, '/');
    if (str_starts_with($rel, 'uploads/')) $path = safe_join(__DIR__, $rel);
    else if (str_starts_with($rel, 'outputs/')) $path = safe_join(__DIR__, $rel);
    else return false;
    if (is_file($path)) return @unlink($path);
    return false;
}
function upload_is_referenced_anywhere(string $rel, ?int $exceptJobId = null): bool {
    $rel = ltrim($rel, '/');
    try {
        $sql = 'SELECT id,input_image,settings_json FROM comfy_jobs WHERE 1=1' . ($exceptJobId !== null ? ' AND id<>?' : '');
        $st = db()->prepare($sql);
        $st->execute($exceptJobId !== null ? [$exceptJobId] : []);
        while ($row = $st->fetch()) {
            if (ltrim((string)($row['input_image'] ?? ''), '/') === $rel) return true;
            $set = !empty($row['settings_json']) ? (json_decode((string)$row['settings_json'], true) ?: []) : [];
            if (ltrim((string)($set['input_image_2'] ?? ''), '/') === $rel) return true;
        }
    } catch (Throwable $e) { return true; }
    return false;
}
function delete_upload_if_unreferenced(?string $rel, ?int $exceptJobId = null): bool {
    if (!$rel) return false;
    $rel = ltrim($rel, '/');
    if (!str_starts_with($rel, 'uploads/')) return false;
    if (upload_is_referenced_anywhere($rel, $exceptJobId)) return false;
    return delete_file_rel($rel);
}
function delete_job_files(array $j): array {
    $deleted = [];
    $settingsForDelete = !empty($j['settings_json']) ? (json_decode((string)$j['settings_json'], true) ?: []) : [];
    if (!empty($j['input_image']) && delete_file_rel($j['input_image'])) $deleted[] = $j['input_image'];
    if (!empty($settingsForDelete['input_image_2']) && delete_file_rel((string)$settingsForDelete['input_image_2'])) $deleted[] = (string)$settingsForDelete['input_image_2'];
    if (!empty($j['output_video']) && delete_file_rel($j['output_video'])) $deleted[] = $j['output_video'];
    $files = !empty($j['output_files']) ? (json_decode((string)$j['output_files'], true) ?: []) : [];
    foreach ($files as $f) {
        $rel = $f['rel'] ?? null;
        if ($rel && delete_file_rel($rel)) $deleted[] = $rel;
    }
    return $deleted;
}
function cleanup_uploads_dir(): int {
    // Smaže z /uploads jen skutečně osiřelé soubory, které už nejsou navázané na žádný job.
    // Díky tomu lze hotový job znovu spustit se stejnou vstupní fotkou i později.
    $referenced = [];
    try {
        $rows = db()->query("SELECT input_image, settings_json FROM comfy_jobs")->fetchAll();
        foreach ($rows as $r) {
            if (!empty($r['input_image'])) $referenced[ltrim((string)$r['input_image'], '/')] = true;
            $set = !empty($r['settings_json']) ? (json_decode((string)$r['settings_json'], true) ?: []) : [];
            if (!empty($set['input_image_2'])) $referenced[ltrim((string)$set['input_image_2'], '/')] = true;
        }
    } catch (Throwable $e) {}
    $deleted = 0;
    if (!is_dir(UPLOAD_DIR)) return 0;
    foreach (scandir(UPLOAD_DIR) ?: [] as $name) {
        if ($name === '.' || $name === '..' || $name === '.htaccess') continue;
        $path = UPLOAD_DIR . DIRECTORY_SEPARATOR . $name;
        if (!is_file($path)) continue;
        $rel = 'uploads/' . $name;
        if (isset($referenced[$rel])) continue;
        // Bezpečnostní brzda: čerstvé soubory mladší než 2 minuty necháme být.
        if (filemtime($path) !== false && (time() - filemtime($path)) < 120) continue;
        if (@unlink($path)) $deleted++;
    }
    return $deleted;
}


function privacy_auto_cleanup(): array {
    // Automaticky smaže staré hotové/chybové/zrušené joby včetně uploadů a výsledků.
    // Tím se na FTP nedrží citlivé fotky/videa déle než je nutné.
    $hours = defined('AUTO_PURGE_FINISHED_AFTER_HOURS') ? (int)AUTO_PURGE_FINISHED_AFTER_HOURS : 0;
    if ($hours <= 0) return ['enabled' => false, 'deleted_jobs' => 0, 'deleted_files' => 0];
    $flag = cache_dir() . '/privacy_cleanup.flag';
    if (is_file($flag) && (time() - (int)@filemtime($flag) < 600)) return ['enabled' => true, 'skipped' => true, 'deleted_jobs' => 0, 'deleted_files' => 0];
    @touch($flag);
    $deletedJobs = 0;
    $deletedFiles = 0;
    try {
        $st = db()->prepare("SELECT * FROM comfy_jobs WHERE status IN ('done','error','cancelled') AND COALESCE(finished_at,updated_at,created_at) < datetime('now', ?)");
        $st->execute(['-' . $hours . ' hours']);
        $rows = $st->fetchAll();
        foreach ($rows as $j) {
            $files = delete_job_files($j);
            $deletedFiles += count($files);
            db()->prepare('DELETE FROM comfy_events WHERE job_id=?')->execute([(int)$j['id']]);
            db()->prepare('DELETE FROM comfy_jobs WHERE id=?')->execute([(int)$j['id']]);
            $deletedJobs++;
        }
        $deletedFiles += cleanup_uploads_dir();
        if ($deletedJobs || $deletedFiles) touch_dashboard_cache();
    } catch (Throwable $e) {}
    return ['enabled' => true, 'deleted_jobs' => $deletedJobs, 'deleted_files' => $deletedFiles];
}

function cache_dir(): string {
    $dir = __DIR__ . '/cache';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) @file_put_contents($ht, "Options -Indexes\n<FilesMatch \".*\">\n    Require all denied\n</FilesMatch>\n");
    return $dir;
}
function write_json_atomic(string $path, array $data): void {
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @rename($tmp, $path);
}
function read_json_file(string $path): ?array {
    if (!is_file($path)) return null;
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}
function cleanup_dashboard_cache(int $maxAgeSeconds = 1800, int $maxFiles = 80): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $dir = cache_dir();
        $now = time();
        $stamp = $dir . '/.dashboard_cleanup_last';
        if (is_file($stamp) && ($now - (int)@filemtime($stamp) < 120)) return;
        @touch($stamp);

        $files = glob($dir . '/dashboard_*.json') ?: [];
        foreach ($files as $file) {
            if (!is_file($file)) continue;
            $mtime = (int)@filemtime($file);
            if ($mtime > 0 && ($now - $mtime) > $maxAgeSeconds) @unlink($file);
        }

        $files = array_values(array_filter(glob($dir . '/dashboard_*.json') ?: [], 'is_file'));
        if (count($files) > $maxFiles) {
            usort($files, fn($a, $b) => ((int)@filemtime($a)) <=> ((int)@filemtime($b)));
            $remove = array_slice($files, 0, count($files) - $maxFiles);
            foreach ($remove as $file) @unlink($file);
        }
    } catch (Throwable $e) {}
}
function dashboard_cache_buster_path(): string { return cache_dir() . '/.dashboard_buster'; }
function touch_dashboard_cache(): void { @touch(dashboard_cache_buster_path()); }
function touch_worker_wake(): void { @file_put_contents(cache_dir() . '/wake_worker.flag', (string)time(), LOCK_EX); }
function cache_scope_key(): string {
    if (worker_token_only()) return 'worker';
    if (master_admin_session()) return 'master';
    $uid = current_user_id();
    if ($uid) return 'user_' . $uid;
    $u = current_username();
    return $u !== '' ? ('name_' . sha1($u)) : 'anon_' . sha1(session_id());
}
function dashboard_payload(string $status = '', int $limit = 200, int $detailId = 0): array {
    worker_watchdog_check(false);
    $status = clean_text($status, 40);
    $limit = max(1, min(500, $limit));
    [$where, $params] = $status !== '' ? scoped_job_where('status=?', [$status]) : scoped_job_where();
    $order = $status !== '' ? 'id ASC' : 'id DESC';
    $st = db()->prepare("SELECT * FROM comfy_jobs WHERE $where ORDER BY $order LIMIT $limit");
    $st->execute($params);
    $jobs = array_map('job_row_to_public', $st->fetchAll());

    $workersFile = cache_dir() . '/stats_workers.json';
    $workers = is_file($workersFile) ? json_decode((string)file_get_contents($workersFile), true) : null;
    $out = [
        'success' => true,
        'jobs' => $jobs,
        'workers' => is_array($workers) ? $workers : [],
        'queue_counts' => queue_counts(),
        'generated_at' => gmdate('Y-m-d\\TH:i:s') . 'Z',
    ];

    if ($detailId > 0) {
        $st = db()->prepare('SELECT * FROM comfy_jobs WHERE id=?');
        $st->execute([$detailId]);
        $job = $st->fetch();
        if ($job && can_access_job($job)) {
            $ev = db()->prepare('SELECT type,message,data_json,created_at FROM comfy_events WHERE job_id=? ORDER BY id DESC LIMIT 80');
            $ev->execute([$detailId]);
            $out['detail'] = ['job' => job_row_to_public($job), 'events' => $ev->fetchAll()];
        } else {
            $out['detail_error'] = $job ? 'Nemáte oprávnění k tomuto jobu.' : 'Job nenalezen.';
        }
    }
    return $out;
}
function dashboard_cache_path(string $status, int $limit, int $detailId): string {
    $key = cache_scope_key() . '|s=' . $status . '|l=' . $limit . '|d=' . $detailId;
    return cache_dir() . '/dashboard_' . sha1($key) . '.json';
}
function dashboard_cached_payload(string $status = '', int $limit = 200, int $detailId = 0, bool $force = false): array {
    worker_watchdog_check(false);
    cleanup_dashboard_cache(1800, 80);
    $limit = max(1, min(500, $limit));
    $path = dashboard_cache_path($status, $limit, $detailId);
    $buster = dashboard_cache_buster_path();
    $ttl = $detailId > 0 ? 6 : 12;
    $fresh = is_file($path) && (time() - (int)@filemtime($path) <= $ttl);
    if ($fresh && is_file($buster) && @filemtime($path) < @filemtime($buster)) $fresh = false;
    if (!$force && $fresh) {
        $cached = read_json_file($path);
        if ($cached) {
            $cached['cached'] = true;
            return $cached;
        }
    }
    $out = dashboard_payload($status, $limit, $detailId);
    $out['cached'] = false;
    write_json_atomic($path, $out);
    return $out;
}


// ─── WORKER RESTART / WATCHDOG ─────────────────────────────
const WORKER_WATCHDOG_SECONDS = 1200; // 20 minut bez update = zaseknutý job
const WORKER_RESTART_REQUEST_TTL = 3600;

function worker_restart_requests_path(): string { return cache_dir() . '/worker_restart_requests.json'; }
function worker_watchdog_lock_path(): string { return cache_dir() . '/worker_watchdog_last.txt'; }
function normalize_worker_target(string $target): string {
    $target = trim($target);
    if ($target === '' || $target === 'any' || $target === '*') return 'any';
    $target = preg_replace('~[^A-Za-z0-9_.\-]+~', '', $target) ?: 'any';
    return mb_substr($target, 0, 100);
}
function worker_matches_target(string $workerId, string $target): bool {
    $workerId = trim($workerId);
    $target = normalize_worker_target($target);
    if ($target === 'any') return true;
    if ($workerId === $target) return true;
    return strncmp($workerId, $target . '-', strlen($target) + 1) === 0;
}
function queue_worker_restart(string $target, string $reason, ?int $jobId = null): array {
    $target = normalize_worker_target($target);
    $path = worker_restart_requests_path();
    $data = read_json_file($path) ?: [];
    $requests = is_array($data['requests'] ?? null) ? $data['requests'] : [];
    $now = time();

    // Staré požadavky zahodíme, aby se po hodinách zbytečně nerecyklovaly.
    foreach ($requests as $k => $r) {
        $ts = (int)($r['created_ts'] ?? 0);
        if ($ts <= 0 || ($now - $ts) > WORKER_RESTART_REQUEST_TTL) unset($requests[$k]);
    }

    $id = bin2hex(random_bytes(6));
    $key = $target . '_' . $id;
    $requests[$key] = [
        'id' => $id,
        'target' => $target,
        'reason' => mb_substr($reason, 0, 500),
        'job_id' => $jobId,
        'created_ts' => $now,
        'created_at' => gmdate('Y-m-d\\TH:i:s') . 'Z',
        'requested_by' => current_username() !== '' ? current_username() : (is_logged() ? 'web' : 'watchdog'),
        'delivered' => new stdClass(),
    ];
    write_json_atomic($path, ['requests' => $requests, 'updated_at' => gmdate('Y-m-d\\TH:i:s') . 'Z']);
    @touch(dashboard_cache_buster_path());
    return $requests[$key];
}
function next_worker_restart_request(string $workerId): ?array {
    $workerId = trim($workerId);
    if ($workerId === '') return null;
    $path = worker_restart_requests_path();
    $data = read_json_file($path) ?: [];
    $requests = is_array($data['requests'] ?? null) ? $data['requests'] : [];
    $now = time();
    $changed = false;
    foreach ($requests as $k => $r) {
        $ts = (int)($r['created_ts'] ?? 0);
        if ($ts <= 0 || ($now - $ts) > WORKER_RESTART_REQUEST_TTL) {
            unset($requests[$k]);
            $changed = true;
            continue;
        }
        $target = (string)($r['target'] ?? 'any');
        if (!worker_matches_target($workerId, $target)) continue;
        $delivered = is_array($r['delivered'] ?? null) ? $r['delivered'] : [];
        if (isset($delivered[$workerId])) continue;
        $delivered[$workerId] = gmdate('Y-m-d\\TH:i:s') . 'Z';
        $requests[$k]['delivered'] = $delivered;
        write_json_atomic($path, ['requests' => $requests, 'updated_at' => gmdate('Y-m-d\\TH:i:s') . 'Z']);
        return $requests[$k];
    }
    if ($changed) write_json_atomic($path, ['requests' => $requests, 'updated_at' => gmdate('Y-m-d\\TH:i:s') . 'Z']);
    return null;
}

// ─── WORKER REMOTE COMMANDS: START COMFYUI ──────────────────
const WORKER_COMMAND_TTL = 3600;
function worker_commands_path(): string { return cache_dir() . '/worker_commands.json'; }
function queue_worker_command(string $target, string $command, string $reason = '', ?int $jobId = null): array {
    $target = normalize_worker_target($target);
    $command = preg_replace('~[^a-z0-9_\-]+~i', '', trim($command)) ?: '';
    if ($command === '') fail('Chybí příkaz pro worker.', 400);
    $path = worker_commands_path();
    $data = read_json_file($path) ?: [];
    $requests = is_array($data['requests'] ?? null) ? $data['requests'] : [];
    $now = time();
    foreach ($requests as $k => $r) {
        $ts = (int)($r['created_ts'] ?? 0);
        if ($ts <= 0 || ($now - $ts) > WORKER_COMMAND_TTL) unset($requests[$k]);
    }
    $id = bin2hex(random_bytes(6));
    $key = $command . '_' . $target . '_' . $id;
    $requests[$key] = [
        'id' => $id,
        'command' => $command,
        'target' => $target,
        'reason' => mb_substr($reason ?: $command, 0, 500),
        'job_id' => $jobId,
        'created_ts' => $now,
        'created_at' => gmdate('Y-m-d\\TH:i:s') . 'Z',
        'requested_by' => current_username() !== '' ? current_username() : (is_logged() ? 'web' : 'system'),
        'delivered' => new stdClass(),
    ];
    write_json_atomic($path, ['requests' => $requests, 'updated_at' => gmdate('Y-m-d\\TH:i:s') . 'Z']);
    @touch(dashboard_cache_buster_path());
    return $requests[$key];
}
function next_worker_command(string $workerId): ?array {
    $workerId = trim($workerId);
    if ($workerId === '') return null;
    $path = worker_commands_path();
    $data = read_json_file($path) ?: [];
    $requests = is_array($data['requests'] ?? null) ? $data['requests'] : [];
    $now = time();
    $changed = false;
    foreach ($requests as $k => $r) {
        $ts = (int)($r['created_ts'] ?? 0);
        if ($ts <= 0 || ($now - $ts) > WORKER_COMMAND_TTL) {
            unset($requests[$k]);
            $changed = true;
            continue;
        }
        $target = (string)($r['target'] ?? 'any');
        if (!worker_matches_target($workerId, $target)) continue;
        $delivered = is_array($r['delivered'] ?? null) ? $r['delivered'] : [];
        if (isset($delivered[$workerId])) continue;
        $delivered[$workerId] = gmdate('Y-m-d\\TH:i:s') . 'Z';
        $requests[$k]['delivered'] = $delivered;
        write_json_atomic($path, ['requests' => $requests, 'updated_at' => gmdate('Y-m-d\\TH:i:s') . 'Z']);
        return $requests[$k];
    }
    if ($changed) write_json_atomic($path, ['requests' => $requests, 'updated_at' => gmdate('Y-m-d\\TH:i:s') . 'Z']);
    return null;
}

function recent_worker_state_for_job(array $job, int $maxAgeSeconds = 180): ?array {
    $workersFile = cache_dir() . '/stats_workers.json';
    if (!is_file($workersFile)) return null;
    $workers = read_json_file($workersFile) ?: [];
    if (!is_array($workers)) return null;
    $jobId = (int)($job['id'] ?? 0);
    $jobWorker = trim((string)($job['worker_id'] ?? ''));
    $jobTarget = trim((string)($job['target_worker'] ?? ''));
    foreach ($workers as $key => $data) {
        if (!is_array($data)) continue;
        $updated = (string)($data['updated_at'] ?? '');
        $ts = $updated !== '' ? strtotime($updated) : 0;
        if (!$ts || time() - $ts > $maxAgeSeconds) continue;
        $w = is_array($data['worker'] ?? null) ? $data['worker'] : [];
        $wid = trim((string)($w['id'] ?? $key));
        $lastJob = (int)($w['last_job'] ?? 0);
        $activeJob = (int)($w['active_job'] ?? 0);
        if ($lastJob !== $jobId && $activeJob !== $jobId) continue;
        if ($jobWorker !== '' && !worker_matches_target($wid, $jobWorker)) continue;
        if ($jobWorker === '' && $jobTarget !== '' && !worker_matches_target($wid, $jobTarget)) continue;
        return $data;
    }
    return null;
}

function worker_watchdog_check(bool $force = false, string $liveWorkerId = '', int $liveJobId = 0): array {
    $lock = worker_watchdog_lock_path();
    $now = time();
    if (!$force && is_file($lock) && ($now - (int)@file_get_contents($lock)) < 45) {
        return ['checked' => false, 'reason' => 'throttled'];
    }
    @file_put_contents($lock, (string)$now, LOCK_EX);

    $st = db()->prepare("SELECT * FROM comfy_jobs WHERE status IN ('processing','queued','generating','uploading','downloading') AND updated_at <= datetime('now', '-' || ? || ' seconds') ORDER BY updated_at ASC LIMIT 5");
    $st->execute([WORKER_WATCHDOG_SECONDS]);
    $rows = $st->fetchAll();
    $affected = [];
    $skipped = [];
    foreach ($rows as $job) {
        $id = (int)$job['id'];
        $worker = trim((string)($job['worker_id'] ?? ''));
        $target = $worker !== '' ? $worker : trim((string)($job['target_worker'] ?? 'any'));
        if ($target === '') $target = 'any';

        // Pojistka proti falešnému zásahu: když se právě hlásí živý worker a říká,
        // že tento job má aktivně rozpracovaný, webový watchdog ho nesmí sestřelit.
        // Lokální watchdog ve workeru pak ještě kontroluje ComfyUI /queue a /history.
        if (!$force && $liveJobId === $id && $liveWorkerId !== '' && worker_matches_target($liveWorkerId, $target)) {
            $skipped[] = ['job_id' => $id, 'reason' => 'live_worker_control'];
            continue;
        }

        // Druhá pojistka: pokud worker v posledních 20 min poslal stats pro stejný job a ComfyUI
        // je online, neshazujeme job jen kvůli dočasně chybějícímu dashboard signálu.
        if (!$force) {
            $recent = recent_worker_state_for_job($job, (int)WORKER_WATCHDOG_SECONDS);
            $comfyOnline = is_array($recent) && !empty($recent['comfy']['online']);
            if ($comfyOnline) {
                $skipped[] = ['job_id' => $id, 'reason' => 'recent_worker_stats_comfy_online'];
                continue;
            }
        }

        $msg = 'Watchdog: job se neposunul déle než 20 minut a worker/ComfyUI nevypadá živě. Worker bude restartován a job označen jako chyba, aby se fronta nezasekla přes noc.';
        $up = db()->prepare("UPDATE comfy_jobs SET status='error', error=?, current_node='watchdog_restart', updated_at=datetime('now'), finished_at=COALESCE(finished_at, datetime('now')), duration_seconds=CASE WHEN started_at IS NOT NULL THEN (julianday('now')-julianday(started_at))*86400 ELSE duration_seconds END WHERE id=? AND status IN ('processing','queued','generating','uploading','downloading')");
        $up->execute([$msg, $id]);
        if ($up->rowCount() > 0) {
            queue_worker_restart($target, 'Auto watchdog 20 min bez update u jobu #' . $id, $id);
            add_event($id, 'watchdog', $msg, ['worker' => $worker, 'target' => $target, 'threshold_seconds' => WORKER_WATCHDOG_SECONDS]);
            $affected[] = ['job_id' => $id, 'worker' => $worker, 'target' => $target];
        }
    }
    if ($affected) touch_dashboard_cache();
    return ['checked' => true, 'threshold_seconds' => WORKER_WATCHDOG_SECONDS, 'affected' => $affected, 'skipped' => $skipped];
}


$action = $_GET['action'] ?? 'jobs';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
privacy_auto_cleanup();

try {
    switch ($action) {
        case 'login':
            if ($method !== 'POST') fail('Method not allowed', 405);
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            $pass = (string)($body['password'] ?? '');
            $uname = trim((string)($body['username'] ?? ''));
            if ($uname === '') fail('Zadejte uživatelské jméno.', 401);
            $pdo = db();
            if ($msg = pz_login_throttle_check($pdo)) fail($msg, 429);
            $st = $pdo->prepare("SELECT * FROM comfy_users WHERE username=? AND active=1");
            $st->execute([$uname]);
            $urow = $st->fetch();
            if ($urow && password_verify($pass, $urow['password_hash'])) {
                pz_login_session($urow);
                $pdo->prepare("UPDATE comfy_users SET last_login=datetime('now') WHERE id=?")->execute([$urow['id']]);
                pz_login_throttle_record($pdo, $uname, true);
                json_out(['success' => true, 'role' => $urow['role'], 'username' => $urow['username'], 'user_id' => (int)$urow['id'], 'csrf' => pz_csrf_token()]);
            }
            if (pz_verify_config_login($uname, $pass)) {
                pz_login_session(['username' => LOGIN_USERNAME, 'role' => 'admin']);
                pz_login_throttle_record($pdo, $uname, true);
                json_out(['success' => true, 'role' => 'admin', 'username' => LOGIN_USERNAME, 'csrf' => pz_csrf_token()]);
            }
            pz_login_throttle_record($pdo, $uname, false);
            fail('Nesprávné jméno nebo heslo.', 401);

        case 'logout':
            pz_logout_session();
            json_out(['success' => true]);

        case 'me':
            json_out(['success' => true, 'authenticated' => is_logged(), 'title' => APP_TITLE, 'role' => $_SESSION['role'] ?? 'user', 'username' => $_SESSION['username'] ?? null, 'user_id' => current_user_id(), 'is_admin' => !empty($_SESSION['is_admin']), 'csrf' => pz_csrf_token()]);

        case 'translate_prompt':
            if ($method !== 'POST') fail('Method not allowed', 405);
            require_session();
            require_csrf();
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            $text = clean_text((string)($body['text'] ?? ''), 6000);
            $source = clean_text((string)($body['source'] ?? (defined('TRANSLATE_SOURCE_LANG') ? TRANSLATE_SOURCE_LANG : 'cs')), 12);
            $target = clean_text((string)($body['target'] ?? (defined('TRANSLATE_TARGET_LANG') ? TRANSLATE_TARGET_LANG : 'en')), 12);
            $result = translate_text_online($text, $source ?: 'cs', $target ?: 'en');
            json_out($result + ['success' => (bool)($result['success'] ?? false)]);

        case 'create_jobs_batch':
            if ($method !== 'POST') fail('Method not allowed', 405);
            require_session();
            require_csrf();
            if (empty($_FILES['images']) || !is_array($_FILES['images']['name'] ?? null)) fail('Chybí vstupní obrázky.');
            $files = $_FILES['images'];
            $fileCount = count($files['name']);
            if ($fileCount < 1) fail('Chybí vstupní obrázky.');
            if ($fileCount > 40) fail('Jedna dávka může mít maximálně 40 obrázků. Frontend je posílá po menších balících.', 413);
            check_rate_limit_bulk($fileCount);

            $promptBase = clean_text((string)($_POST['prompt'] ?? ''), 6000);
            if ($promptBase === '') fail('Prompt je prázdný.');
            $negativeBase = clean_text((string)($_POST['negative_prompt'] ?? ''), 4000);
            $presetBase = clean_text((string)($_POST['preset'] ?? 'Statická kamera (stativ)'), 80);
            $settingsList = json_decode((string)($_POST['settings_jsons'] ?? '[]'), true);
            if (!is_array($settingsList)) $settingsList = [];
            $tw = clean_text((string)($_POST['target_worker'] ?? ''), 100);
            $project_id = (int)($_POST['project_id'] ?? 0) ?: null;
            $target_worker = ($tw === '' || $tw === 'any') ? null : $tw;
            $owner_id = current_user_id();
            $owner_username = current_username() !== '' ? current_username() : (is_admin_session() ? 'admin' : null);

            $pdo = db();
            $created = [];
            $failed = [];
            $insert = $pdo->prepare("INSERT INTO comfy_jobs
                (prompt, negative_prompt, preset, input_image, input_original_name, settings_json, target_worker, project_id, user_id, username, status, progress, ip)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 0, ?)");
            for ($i = 0; $i < $fileCount; $i++) {
                $one = image_file_from_multi($files, $i);
                $fileName = (string)($one['name'] ?? ('image_' . ($i + 1)));
                try {
                    [$mime, $ext] = validate_image_upload_or_throw($one);
                    $prompt = $promptBase;
                    $negative = $negativeBase;
                    $preset = $presetBase;
                    $settings = $settingsList[$i] ?? [];
                    if (!is_array($settings)) $settings = [];
                    $settings = prepare_job_settings($settings, $preset, $prompt, $negative);

                    $idPart = date('Ymd_His') . '_' . bin2hex(random_bytes(5));
                    $filename = 'input_' . $idPart . '.' . $ext;
                    $rel = 'uploads/' . $filename;
                    $dst = safe_join(__DIR__, $rel);
                    if (!move_uploaded_file($one['tmp_name'], $dst)) throw new RuntimeException('Nelze uložit obrázek na server.');
                    $insert->execute([$prompt, $negative, $preset, $rel, $fileName, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $target_worker, $project_id, $owner_id, $owner_username, client_ip()]);
                    $id = (int)$pdo->lastInsertId();
                    add_event($id, 'create', $settings['translated'] ? 'Job vytvořen v dávce + prompt přeložen do EN' : 'Job vytvořen v dávce', ['mime' => $mime, 'settings' => $settings, 'batch_index' => $i]);
                    $created[] = ['id' => $id, 'name' => $fileName];
                } catch (Throwable $e) {
                    $failed[] = ['name' => $fileName, 'error' => $e->getMessage()];
                }
            }
            if ($created) {
                touch_worker_wake();
                touch_dashboard_cache();
            }
            json_out(['success' => count($created) > 0, 'created' => $created, 'ids' => array_map(fn($x) => $x['id'], $created), 'failed' => $failed, 'created_count' => count($created), 'failed_count' => count($failed)]);

        case 'create_job':
            if ($method !== 'POST') fail('Method not allowed', 405);
            require_session();
            require_csrf();
            check_rate_limit();
            if (empty($_FILES['image'])) fail('Chybí vstupní obrázek.');
            [$mime, $ext] = validate_image_upload($_FILES['image']);

            $prompt = clean_text((string)($_POST['prompt'] ?? ''), 6000);
            if ($prompt === '') fail('Prompt je prázdný.');
            $negative = clean_text((string)($_POST['negative_prompt'] ?? ''), 4000);
            $preset = clean_text((string)($_POST['preset'] ?? 'Statická kamera (stativ)'), 80);
            $settingsRaw = (string)($_POST['settings_json'] ?? '{}');
            $settings = json_decode($settingsRaw, true);
            if (!is_array($settings)) $settings = [];

            // rozumné hranice
            $settings['width']  = max(256, min(4096, (int)($settings['width'] ?? 1280)));
            $settings['height'] = max(256, min(4096, (int)($settings['height'] ?? 720)));
            $settings['fps']    = max(1, min(60, (int)($settings['fps'] ?? 25)));
            $settings['duration'] = max(1, min(60, (float)($settings['duration'] ?? 5)));
            $settings['frame_count'] = max(1, min(3600, (int)round($settings['fps'] * $settings['duration'])));
            $settings['steps']  = max(1, min(200, (int)($settings['steps'] ?? 30)));
            $settings['cfg']    = max(0, min(30, (float)($settings['cfg'] ?? 3.5)));
            $settings['motion_strength'] = max(0, min(2, (float)($settings['motion_strength'] ?? 0.75)));
            $settings['prompt_enhance'] = !empty($settings['prompt_enhance']);
            $settings['enhance_tokens'] = max(64, min(512, (int)($settings['enhance_tokens'] ?? 128)));
            $settings['seed']   = isset($settings['seed']) && $settings['seed'] !== '' ? max(1, min(2147483647, (int)$settings['seed'])) : random_int(1, 2147483647);
    $sm = (string)($settings['seed_mode'] ?? 'increment_batch');
    $settings['seed_mode'] = in_array($sm, ['increment_batch','locked','random_each'], true) ? $sm : 'increment_batch';
            $settings['camera_motion'] = clean_text((string)($settings['camera_motion'] ?? ''), 1000);
            if ($settings['camera_motion'] === '') {
                $settings['camera_motion'] = clean_text(camera_preset_text($preset), 1000);
            }
            $settings['style'] = clean_text((string)($settings['style'] ?? ''), 1000);

            // Automatický překlad promptu pro ComfyUI.
            // DŮLEŽITÉ: pokud frontend už poslal přeložený prompt v poli `prompt`,
            // původní jazyk uživatele je v settings_json.original_prompt. Ten nesmíme
            // přepsat angličtinou, jinak u hotového videa zmizí čeština.
            $translateEnabled = (bool)($settings['translate_prompt'] ?? (defined('TRANSLATE_PROMPT_DEFAULT') ? TRANSLATE_PROMPT_DEFAULT : true));
            $originalPrompt = clean_text((string)($settings['original_prompt'] ?? $prompt), 6000);
            $originalNegative = clean_text((string)($settings['original_negative_prompt'] ?? $negative), 4000);
            $settings['original_prompt'] = $originalPrompt !== '' ? $originalPrompt : $prompt;
            $settings['original_negative_prompt'] = $originalNegative;
            $settings['translated'] = !empty($settings['translated']);
            $settings['translation_provider'] = isset($settings['translation_provider'])
                ? clean_text((string)$settings['translation_provider'], 80)
                : null;
            if ($translateEnabled) {
                $source = defined('TRANSLATE_SOURCE_LANG') ? TRANSLATE_SOURCE_LANG : 'cs';
                $target = defined('TRANSLATE_TARGET_LANG') ? TRANSLATE_TARGET_LANG : 'en';

                $mainForTranslation = build_comfy_prompt($settings['original_prompt'], $preset, $settings['camera_motion']);
                $tr = translate_text_online($mainForTranslation, $source, $target);
                if (!empty($tr['success']) && trim((string)$tr['translated']) !== '') {
                    $prompt = clean_text((string)$tr['translated'], 6000);
                    $settings['translated'] = true;
                    $settings['translation_provider'] = $tr['provider'] ?? 'online';
                }

                if ($settings['original_negative_prompt'] !== '') {
                    $negTr = translate_text_online($settings['original_negative_prompt'], $source, $target);
                    if (!empty($negTr['success']) && trim((string)$negTr['translated']) !== '') {
                        $negative = clean_text((string)$negTr['translated'], 4000);
                    }
                }
            }

            $tw = clean_text((string)($_POST['target_worker'] ?? ''), 100);
            $project_id = (int)($_POST['project_id'] ?? 0) ?: null;
            $target_worker = ($tw === '' || $tw === 'any') ? null : $tw;
            $owner_id = current_user_id();
            $owner_username = current_username() !== '' ? current_username() : (is_admin_session() ? 'admin' : null);

            $idPart = date('Ymd_His') . '_' . bin2hex(random_bytes(5));
            $filename = 'input_' . $idPart . '.' . $ext;
            $rel = 'uploads/' . $filename;
            $dst = safe_join(__DIR__, $rel);
            if (!move_uploaded_file($_FILES['image']['tmp_name'], $dst)) fail('Nelze uložit obrázek na server.');

            // Volitelný druhý obrázek pro LTX 2.3 FLF2V / první + poslední frejm.
            if (!empty($_FILES['image2']) && is_uploaded_file((string)($_FILES['image2']['tmp_name'] ?? ''))) {
                [$mime2, $ext2] = validate_image_upload($_FILES['image2']);
                $idPart2 = date('Ymd_His') . '_' . bin2hex(random_bytes(5));
                $filename2 = 'input2_' . $idPart2 . '.' . $ext2;
                $rel2 = 'uploads/' . $filename2;
                $dst2 = safe_join(__DIR__, $rel2);
                if (!move_uploaded_file($_FILES['image2']['tmp_name'], $dst2)) {
                    @unlink($dst);
                    fail('Nelze uložit druhý obrázek na server.');
                }
                $settings['input_image_2'] = $rel2;
                $settings['input_original_name_2'] = clean_text((string)($_FILES['image2']['name'] ?? ''), 240);
                $settings['input_mode'] = '2pict';
            } else {
                $settings['input_mode'] = $settings['input_mode'] ?? '1pict';
            }

            $pdo = db();
            $pdo->prepare("INSERT INTO comfy_jobs
                (prompt, negative_prompt, preset, input_image, input_original_name, settings_json, target_worker, project_id, user_id, username, status, progress, ip)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 0, ?)")
                ->execute([$prompt, $negative, $preset, $rel, $_FILES['image']['name'] ?? '', json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $target_worker, $project_id, $owner_id, $owner_username, client_ip()]);
            $id = (int)$pdo->lastInsertId();
            add_event($id, 'create', $settings['translated'] ? 'Job vytvořen + prompt přeložen do EN' : 'Job vytvořen', ['mime' => $mime, 'settings' => $settings]);
            touch_worker_wake();
            touch_dashboard_cache();
            json_out(['success' => true, 'id' => $id]);

        case 'rerun_job':
            if ($method !== 'POST') fail('Method not allowed', 405);
            require_session();
            require_csrf();
            check_rate_limit();
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            $newSeed = array_key_exists('new_seed', $body) ? !empty($body['new_seed']) : true;
            $sourceId = (int)($body['id'] ?? 0);
            if (!$sourceId) fail('ID chybí.');
            $st = db()->prepare('SELECT * FROM comfy_jobs WHERE id=?');
            $st->execute([$sourceId]);
            $srcJob = $st->fetch();
            if (!$srcJob) fail('Zdrojový job nenalezen.', 404);
            if (!can_access_job($srcJob)) fail('Nemáte oprávnění k tomuto jobu.', 403);
            $srcRel = (string)($srcJob['input_image'] ?? '');
            if ($srcRel === '') fail('Zdrojový obrázek u původního jobu chybí.');
            $srcPath = safe_join(__DIR__, $srcRel);
            if (!is_file($srcPath)) fail('Původní obrázek už na serveru není.');

            $prompt = clean_text((string)($srcJob['prompt'] ?? ''), 6000);
            if ($prompt === '') fail('Původní prompt je prázdný.');
            $negative = clean_text((string)($srcJob['negative_prompt'] ?? ''), 4000);
            $preset = clean_text((string)($srcJob['preset'] ?? 'custom'), 80);
            $settings = $srcJob['settings_json'] ? (json_decode((string)$srcJob['settings_json'], true) ?: []) : [];
            if (!is_array($settings)) $settings = [];
            $settings['width']  = max(256, min(4096, (int)($settings['width'] ?? 1280)));
            $settings['height'] = max(256, min(4096, (int)($settings['height'] ?? 720)));
            $settings['fps']    = max(1, min(60, (int)($settings['fps'] ?? 25)));
            $settings['duration'] = max(1, min(60, (float)($settings['duration'] ?? 5)));
            $settings['frame_count'] = max(1, min(3600, (int)round($settings['fps'] * $settings['duration'])));
            $settings['steps']  = max(1, min(200, (int)($settings['steps'] ?? 30)));
            $settings['cfg']    = max(0, min(30, (float)($settings['cfg'] ?? 3.5)));
            $settings['motion_strength'] = max(0, min(2, (float)($settings['motion_strength'] ?? 0.75)));
            $settings['prompt_enhance'] = !empty($settings['prompt_enhance']);
            $settings['enhance_tokens'] = max(64, min(512, (int)($settings['enhance_tokens'] ?? 128)));
            $settings['camera_motion'] = clean_text((string)($settings['camera_motion'] ?? ''), 1000);
            if ($settings['camera_motion'] === '') $settings['camera_motion'] = clean_text(camera_preset_text($preset), 1000);
            $settings['style'] = clean_text((string)($settings['style'] ?? ''), 1000);
            $settings['seed'] = $newSeed ? random_int(1, 2147483647) : max(1, min(2147483647, (int)($settings['seed'] ?? random_int(1, 2147483647))));
            $settings['seed_mode'] = $newSeed ? 'increment_batch' : (in_array((string)($settings['seed_mode'] ?? 'increment_batch'), ['increment_batch','locked','random_each'], true) ? (string)$settings['seed_mode'] : 'increment_batch');
            $settings['rerun_from_job_id'] = $sourceId;
            $settings['rerun_new_seed'] = $newSeed;

            $srcExt = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
            if ($srcExt === '') $srcExt = 'png';
            $idPart = date('Ymd_His') . '_' . bin2hex(random_bytes(5));
            $filename = 'input_' . $idPart . '.' . $srcExt;
            $rel = 'uploads/' . $filename;
            $dst = safe_join(__DIR__, $rel);
            if (!@copy($srcPath, $dst)) fail('Nelze zkopírovat původní obrázek pro nový job.');
            $srcSettings = $srcJob['settings_json'] ? (json_decode((string)$srcJob['settings_json'], true) ?: []) : [];
            $src2Rel = is_array($srcSettings) ? (string)($srcSettings['input_image_2'] ?? '') : '';
            if ($src2Rel !== '') {
                $src2Path = safe_join(__DIR__, $src2Rel);
                if (is_file($src2Path)) {
                    $src2Ext = strtolower(pathinfo($src2Path, PATHINFO_EXTENSION)) ?: 'png';
                    $filename2 = 'input2_' . $idPart . '.' . $src2Ext;
                    $rel2 = 'uploads/' . $filename2;
                    if (@copy($src2Path, safe_join(__DIR__, $rel2))) {
                        $settings['input_image_2'] = $rel2;
                        $settings['input_mode'] = '2pict';
                    }
                }
            }

            $owner_id = current_user_id();
            $owner_username = current_username() !== '' ? current_username() : (is_admin_session() ? 'admin' : null);
            $pdo = db();
            $pdo->prepare("INSERT INTO comfy_jobs
                (prompt, negative_prompt, preset, input_image, input_original_name, settings_json, target_worker, project_id, user_id, username, status, progress, ip)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 0, ?)")
                ->execute([$prompt, $negative, $preset, $rel, $srcJob['input_original_name'] ?? basename($srcPath), json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $srcJob['target_worker'] ?: null, $srcJob['project_id'] ?: null, $owner_id, $owner_username, client_ip()]);
            $newId = (int)$pdo->lastInsertId();
            add_event($newId, 'rerun', 'Job znovu zařazen z jobu #' . $sourceId . ($newSeed ? ' s novým seedem' : ' se stejným seedem'), ['source_job_id' => $sourceId, 'seed' => $settings['seed'], 'new_seed' => $newSeed]);
            touch_worker_wake();
            touch_dashboard_cache();
            json_out(['success' => true, 'id' => $newId, 'seed' => $settings['seed']]);

        case 'update_pending_image':
            if ($method !== 'POST') fail('Method not allowed', 405);
            require_session();
            require_csrf();
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) fail('ID chybí.');
            if (empty($_FILES['image']) || ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) fail('Nový obrázek se nenahrál.');

            $st = db()->prepare('SELECT * FROM comfy_jobs WHERE id=?');
            $st->execute([$id]);
            $job = $st->fetch();
            if (!$job) fail('Job nenalezen.', 404);
            if (!can_access_job($job)) fail('Nemáte oprávnění k tomuto jobu.', 403);
            if ((string)($job['status'] ?? '') !== 'pending') fail('Fotku lze změnit jen u pending jobu.', 409);

            [$mime, $ext] = validate_image_upload($_FILES['image']);
            $filename = 'input_replace_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            $rel = 'uploads/' . $filename;
            $dst = safe_join(__DIR__, $rel);
            if (!move_uploaded_file($_FILES['image']['tmp_name'], $dst)) fail('Nelze uložit nový obrázek.');

            $oldRel = (string)($job['input_image'] ?? '');
            $pdo = db();
            $up = $pdo->prepare("UPDATE comfy_jobs SET input_image=?, input_original_name=?, updated_at=datetime('now') WHERE id=? AND status='pending'");
            $up->execute([$rel, $_FILES['image']['name'] ?? basename($rel), $id]);
            if ($up->rowCount() < 1) {
                delete_file_rel($rel);
                fail('Job už mezitím není pending.', 409);
            }
            $deletedOld = false;
            if ($oldRel && ltrim($oldRel, '/') !== $rel) $deletedOld = delete_upload_if_unreferenced($oldRel, $id);
            add_event($id, 'edit', 'Vstupní fotka u pending jobu změněna', ['mime' => $mime, 'old' => $oldRel, 'new' => $rel, 'deleted_old' => $deletedOld]);
            touch_worker_wake();
            touch_dashboard_cache();
            $st = db()->prepare('SELECT * FROM comfy_jobs WHERE id=?');
            $st->execute([$id]);
            $job = $st->fetch();
            json_out(['success' => true, 'job' => job_row_to_public($job), 'deleted_old' => $deletedOld]);

        case 'update_pending_job':
            if ($method !== 'POST') fail('Method not allowed', 405);
            require_session();
            require_csrf();
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            $id = (int)($body['id'] ?? 0);
            if (!$id) fail('ID chybí.');
            $st = db()->prepare('SELECT * FROM comfy_jobs WHERE id=?');
            $st->execute([$id]);
            $job = $st->fetch();
            if (!$job) fail('Job nenalezen.', 404);
            if (!can_access_job($job)) fail('Nemáte oprávnění k tomuto jobu.', 403);
            if ((string)($job['status'] ?? '') !== 'pending') fail('Editovat lze jen pending job.', 409);

            $settings = $job['settings_json'] ? (json_decode((string)$job['settings_json'], true) ?: []) : [];
            if (!is_array($settings)) $settings = [];
            $in = is_array($body['settings'] ?? null) ? $body['settings'] : [];

            $promptInput = clean_text((string)($body['prompt'] ?? ''), 6000);
            if ($promptInput === '') fail('Prompt je prázdný.');
            $negativeInput = clean_text((string)($body['negative_prompt'] ?? ''), 4000);
            $preset = clean_text((string)($body['preset'] ?? ($job['preset'] ?? 'custom')), 80);

            $settings['width']  = max(256, min(4096, (int)($in['width'] ?? ($settings['width'] ?? 1280))));
            $settings['height'] = max(256, min(4096, (int)($in['height'] ?? ($settings['height'] ?? 720))));
            $settings['fps']    = max(1, min(60, (int)($in['fps'] ?? ($settings['fps'] ?? 25))));
            $settings['duration'] = max(1, min(60, (float)($in['duration'] ?? ($settings['duration'] ?? 5))));
            $settings['frame_count'] = max(1, min(3600, (int)round($settings['fps'] * $settings['duration'])));
            $settings['steps']  = max(1, min(200, (int)($in['steps'] ?? ($settings['steps'] ?? 30))));
            $settings['cfg']    = max(0, min(30, (float)($in['cfg'] ?? ($settings['cfg'] ?? 3.5))));
            $settings['motion_strength'] = max(0, min(2, (float)($in['motion_strength'] ?? ($settings['motion_strength'] ?? 0.75))));
            $settings['prompt_enhance'] = !empty($in['prompt_enhance']);
            $settings['enhance_tokens'] = max(64, min(512, (int)($in['enhance_tokens'] ?? ($settings['enhance_tokens'] ?? 128))));
            $settings['seed']   = isset($in['seed']) && $in['seed'] !== '' ? max(1, min(2147483647, (int)$in['seed'])) : max(1, min(2147483647, (int)($settings['seed'] ?? random_int(1, 2147483647))));
            $sm = (string)($in['seed_mode'] ?? ($settings['seed_mode'] ?? 'increment_batch'));
            $settings['seed_mode'] = in_array($sm, ['increment_batch','locked','random_each'], true) ? $sm : 'increment_batch';
            $settings['camera_motion'] = clean_text((string)($in['camera_motion'] ?? ($settings['camera_motion'] ?? '')), 1000);
            if ($settings['camera_motion'] === '') $settings['camera_motion'] = clean_text(camera_preset_text($preset), 1000);
            $settings['style'] = clean_text((string)($in['style'] ?? ($settings['style'] ?? '')), 1000);

            $inputLanguage = clean_text((string)($settings['input_language'] ?? 'en'), 12);
            $prompt = $promptInput;
            $negative = $negativeInput;
            if ($inputLanguage === 'cs') {
                $settings['original_prompt'] = $promptInput;
                $settings['original_negative_prompt'] = $negativeInput;
                $tr = translate_text_online(build_comfy_prompt($promptInput, $preset, $settings['camera_motion']), defined('TRANSLATE_SOURCE_LANG') ? TRANSLATE_SOURCE_LANG : 'cs', defined('TRANSLATE_TARGET_LANG') ? TRANSLATE_TARGET_LANG : 'en');
                if (!empty($tr['success']) && trim((string)($tr['translated'] ?? '')) !== '') {
                    $prompt = clean_text((string)$tr['translated'], 6000);
                    $settings['translated'] = true;
                    $settings['translation_provider'] = $tr['provider'] ?? 'online';
                } else {
                    $settings['translated'] = false;
                    $settings['translation_provider'] = 'none';
                }
                if ($negativeInput !== '') {
                    $negTr = translate_text_online($negativeInput, defined('TRANSLATE_SOURCE_LANG') ? TRANSLATE_SOURCE_LANG : 'cs', defined('TRANSLATE_TARGET_LANG') ? TRANSLATE_TARGET_LANG : 'en');
                    if (!empty($negTr['success']) && trim((string)($negTr['translated'] ?? '')) !== '') {
                        $negative = clean_text((string)$negTr['translated'], 4000);
                    }
                }
            } else {
                $settings['original_prompt'] = $promptInput;
                $settings['original_negative_prompt'] = $negativeInput;
            }

            $pdo = db();
            $up = $pdo->prepare("UPDATE comfy_jobs SET prompt=?, negative_prompt=?, preset=?, settings_json=?, updated_at=datetime('now') WHERE id=? AND status='pending'");
            $up->execute([$prompt, $negative, $preset, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
            if ($up->rowCount() < 1) fail('Job už mezitím není pending.', 409);
            add_event($id, 'edit', 'Pending job upraven před renderem', ['preset' => $preset, 'settings' => $settings]);
            touch_dashboard_cache();
            $st = db()->prepare('SELECT * FROM comfy_jobs WHERE id=?');
            $st->execute([$id]);
            $job = $st->fetch();
            json_out(['success' => true, 'job' => job_row_to_public($job)]);

        case 'job_file':
            require_any();
            $id = (int)($_GET['id'] ?? 0);
            $kind = clean_text((string)($_GET['kind'] ?? 'output'), 20);
            if (!$id) fail('ID chybí.');
            if (!in_array($kind, ['input','input2','output'], true)) fail('Neplatný typ souboru.');
            $st = db()->prepare('SELECT * FROM comfy_jobs WHERE id=?');
            $st->execute([$id]);
            $job = $st->fetch();
            if (!$job) fail('Job nenalezen.', 404);
            send_job_file($job, $kind);

        case 'has_users':
            $cnt = (int)db()->query("SELECT COUNT(*) FROM comfy_users WHERE active=1")->fetchColumn();
            json_out(['success' => true, 'has_users' => $cnt > 0]);

        case 'projects':
            require_any();
            $rows = db()->query("SELECT id,name,description,input_type,workflow_file,active,sort_order FROM comfy_projects WHERE active=1 ORDER BY sort_order,id")->fetchAll();
            json_out(['success' => true, 'projects' => $rows]);

        case 'default_workflow':
            require_any();
            $path = safe_join(__DIR__, 'workflows/ltx23_i2v_template.json');
            if (!is_file($path)) fail('Výchozí workflow neexistuje.', 404);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            readfile($path);
            exit;

        case 'project_workflow':
            require_any();
            $pid = (int)($_GET['id'] ?? 0);
            if (!$pid) fail('ID chybí.');
            $st = db()->prepare("SELECT workflow_file FROM comfy_projects WHERE id=? AND active=1");
            $st->execute([$pid]);
            $pr = $st->fetch();
            if (!$pr || !$pr['workflow_file']) fail('Workflow nenalezeno.', 404);
            $path = safe_join(__DIR__, $pr['workflow_file']);
            if (!is_file($path)) fail('Soubor neexistuje.', 404);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            readfile($path);
            exit;

        case 'dashboard_cached':
            require_any();
            $status = clean_text((string)($_GET['status'] ?? ''), 40);
            $limit = max(1, min(500, (int)($_GET['limit'] ?? 200)));
            $detailId = (int)($_GET['detail_id'] ?? 0);
            $force = !empty($_GET['force']);
            json_out(dashboard_cached_payload($status, $limit, $detailId, $force));

        case 'dashboard':
            require_any();
            $status = clean_text((string)($_GET['status'] ?? ''), 40);
            $limit = max(1, min(500, (int)($_GET['limit'] ?? 200)));
            $detailId = (int)($_GET['detail_id'] ?? 0);
            json_out(dashboard_payload($status, $limit, $detailId));

        case 'jobs':
            require_any();
            $status = clean_text((string)($_GET['status'] ?? ''), 40);
            $limit = max(1, min(500, (int)($_GET['limit'] ?? 200)));
            [$where, $params] = $status !== '' ? scoped_job_where('status=?', [$status]) : scoped_job_where();
            $order = $status !== '' ? 'id ASC' : 'id DESC';
            $st = db()->prepare("SELECT * FROM comfy_jobs WHERE $where ORDER BY $order LIMIT $limit");
            $st->execute($params);
            $jobs = array_map('job_row_to_public', $st->fetchAll());
            json_out(['success' => true, 'jobs' => $jobs, 'queue_counts' => queue_counts()]);

        case 'job_detail':
            require_any();
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) fail('ID chybí.');
            $st = db()->prepare('SELECT * FROM comfy_jobs WHERE id=?');
            $st->execute([$id]);
            $job = $st->fetch();
            if (!$job) fail('Job nenalezen.', 404);
            if (!can_access_job($job)) fail('Nemáte oprávnění k tomuto jobu.', 403);
            $ev = db()->prepare('SELECT type,message,data_json,created_at FROM comfy_events WHERE job_id=? ORDER BY id DESC LIMIT 80');
            $ev->execute([$id]);
            json_out(['success' => true, 'job' => job_row_to_public($job), 'events' => $ev->fetchAll()]);

        case 'cancel_job':
            if ($method !== 'POST') fail('Method not allowed', 405);
            require_session();
            require_csrf();
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            $id = (int)($body['id'] ?? 0);
            if (!$id) fail('ID chybí.');
            $st = db()->prepare('SELECT * FROM comfy_jobs WHERE id=?');
            $st->execute([$id]);
            $job = $st->fetch();
            if (!$job) fail('Job nenalezen.', 404);
            if (!can_access_job($job)) fail('Nemáte oprávnění rušit tento job.', 403);
            db()->prepare("UPDATE comfy_jobs SET status='cancelled', error='Zrušeno uživatelem', updated_at=datetime('now'), finished_at=COALESCE(finished_at, datetime('now')) WHERE id=? AND status IN ('pending','processing','queued','generating','uploading','downloading')")->execute([$id]);
            add_event($id, 'cancel', 'Job zrušen uživatelem');
            touch_dashboard_cache();
            json_out(['success' => true]);

        case 'delete_job':
            if ($method !== 'POST') fail('Method not allowed', 405);
            require_session();
            require_csrf();
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            $id = (int)($body['id'] ?? 0);
            if (!$id) fail('ID chybí.');
            $st = db()->prepare('SELECT * FROM comfy_jobs WHERE id=?');
            $st->execute([$id]);
            $j = $st->fetch();
            if (!$j) fail('Job nenalezen.', 404);
            if (!can_access_job($j)) fail('Nemáte oprávnění mazat tento job.', 403);
            $deletedFiles = delete_job_files($j);
            db()->prepare('DELETE FROM comfy_events WHERE job_id=?')->execute([$id]);
            db()->prepare('DELETE FROM comfy_jobs WHERE id=?')->execute([$id]);
            $cleanedUploads = cleanup_uploads_dir();
            touch_dashboard_cache();
            json_out(['success' => true, 'deleted_files' => $deletedFiles, 'cleaned_uploads' => $cleanedUploads]);

        case 'clear_finished':
            if ($method !== 'POST') fail('Method not allowed', 405);
            require_session();
            require_csrf();
            [$where, $params] = scoped_job_where("status IN ('done','error','cancelled')");
            $stRows = db()->prepare("SELECT id FROM comfy_jobs WHERE $where");
            $stRows->execute($params);
            $rows = $stRows->fetchAll();
            $deletedFiles = [];
            foreach ($rows as $r) {
                $id = (int)$r['id'];
                $st = db()->prepare('SELECT * FROM comfy_jobs WHERE id=?'); $st->execute([$id]); $j=$st->fetch();
                if ($j && can_access_job($j)) {
                    $deletedFiles = array_merge($deletedFiles, delete_job_files($j));
                    db()->prepare('DELETE FROM comfy_events WHERE job_id=?')->execute([$id]);
                    db()->prepare('DELETE FROM comfy_jobs WHERE id=?')->execute([$id]);
                }
            }
            $cleanedUploads = cleanup_uploads_dir();
            touch_dashboard_cache();
            json_out(['success' => true, 'deleted' => count($rows), 'deleted_files' => $deletedFiles, 'cleaned_uploads' => $cleanedUploads]);

        case 'worker_claim':
            if ($method !== 'POST') fail('Method not allowed', 405);
            require_token();
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            @set_time_limit(35);
            $worker = clean_text((string)($body['worker_id'] ?? 'worker'), 100);
            // Long-poll: jeden HTTP request může chvíli čekat na nový job.
            // Výrazně to sníží počet požadavků na FORPSI, ale job se po odeslání chytí rychle.
            $waitSeconds = max(0, min(25, (int)($body['wait_seconds'] ?? 0)));
            $sleepStep = 2;
            $deadline = time() + $waitSeconds;
            do {
                $pdo = db();
                $pdo->beginTransaction();
                // PHP-side prefix matching — robustnější než SQL LIKE
                $pending = $pdo->query("SELECT * FROM comfy_jobs WHERE status='pending' ORDER BY id ASC LIMIT 500")->fetchAll();
                $job = null;
                foreach ($pending as $row) {
                    $tw = trim((string)($row['target_worker'] ?? ''));
                    if ($tw === '') { $job = $row; break; }
                    if ($worker === $tw || strncmp($worker, $tw . '-', strlen($tw) + 1) === 0) {
                        $job = $row; break;
                    }
                }
                if ($job) {
                    $pdo->prepare("UPDATE comfy_jobs SET status='processing', worker_id=?, started_at=COALESCE(started_at, datetime('now')), updated_at=datetime('now'), progress=1 WHERE id=? AND status='pending'")->execute([$worker, $job['id']]);
                    $pdo->commit();
                    @unlink(cache_dir() . '/wake_worker.flag');
                    touch_dashboard_cache();
                    add_event((int)$job['id'], 'worker', 'Job převzal worker ' . $worker);
                    $st = db()->prepare('SELECT * FROM comfy_jobs WHERE id=?'); $st->execute([$job['id']]);
                    json_out(['success' => true, 'job' => job_row_to_public($st->fetch())]);
                }
                $pdo->commit();
                if (time() >= $deadline) break;
                sleep($sleepStep);
            } while (true);
            json_out(['success' => true, 'job' => null, 'retry_after' => 3]);

        case 'update_job':
        case 'update_progress':
            if ($method !== 'POST') fail('Method not allowed', 405);
            require_token();
            $b = json_decode(file_get_contents('php://input'), true) ?: [];
            $id = (int)($b['id'] ?? 0);
            if (!$id) fail('ID chybí.');

            // STOP musí být definitivní: worker nesmí dalším progress updatem
            // přepsat cancelled zpět na generating/downloading/done.
            $stCur = db()->prepare('SELECT status FROM comfy_jobs WHERE id=?');
            $stCur->execute([$id]);
            $currentStatus = (string)($stCur->fetchColumn() ?: '');
            $incomingStatus = array_key_exists('status', $b) ? (string)$b['status'] : '';
            if ($currentStatus === 'cancelled' && $incomingStatus !== 'cancelled') {
                if (!empty($b['message'])) add_event($id, 'progress_ignored', 'Update ignorován, job už je zrušen', ['incoming_status' => $incomingStatus, 'message' => (string)$b['message']]);
                touch_dashboard_cache();
                json_out(['success' => true, 'cancelled' => true, 'ignored' => true]);
            }

            $fields = [];
            $vals = [];
            foreach (['status','current_node','error','comfy_prompt_id','worker_id'] as $k) {
                if (array_key_exists($k, $b)) { $fields[] = "$k=?"; $vals[] = $b[$k] === null ? null : (string)$b[$k]; }
            }
            if (array_key_exists('progress', $b)) { $fields[] = 'progress=?'; $vals[] = max(0, min(100, (int)$b['progress'])); }
            if (($b['status'] ?? '') === 'done' || ($b['status'] ?? '') === 'error' || ($b['status'] ?? '') === 'cancelled') {
                $fields[] = "finished_at=COALESCE(finished_at, datetime('now'))";
                $fields[] = "duration_seconds=CASE WHEN started_at IS NOT NULL THEN (julianday('now')-julianday(started_at))*86400 ELSE duration_seconds END";
            }
            if (!$fields) fail('Není co aktualizovat.');
            $fields[] = "updated_at=datetime('now')";
            $vals[] = $id;
            db()->prepare('UPDATE comfy_jobs SET ' . implode(',', $fields) . ' WHERE id=?')->execute($vals);
            if (!empty($b['message'])) add_event($id, (string)($b['type'] ?? 'progress'), (string)$b['message'], $b['data'] ?? null);
            touch_dashboard_cache();
            $st = db()->prepare('SELECT status FROM comfy_jobs WHERE id=?'); $st->execute([$id]);
            $status = (string)$st->fetchColumn();
            json_out(['success' => true, 'cancelled' => $status === 'cancelled']);

        case 'check_cancel':
            if ($method !== 'POST') fail('Method not allowed', 405);
            require_token();
            $b = json_decode(file_get_contents('php://input'), true) ?: [];
            $id = (int)($b['id'] ?? 0);
            if (!$id) fail('ID chybí.');
            $st = db()->prepare('SELECT status FROM comfy_jobs WHERE id=?');
            $st->execute([$id]);
            $status = (string)$st->fetchColumn();
            json_out(['success' => true, 'cancelled' => $status === 'cancelled']);

        case 'upload_result':
            if ($method !== 'POST') fail('Method not allowed', 405);
            require_token();
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) fail('ID chybí.');
            $stCancel = db()->prepare('SELECT status FROM comfy_jobs WHERE id=?');
            $stCancel->execute([$id]);
            if ((string)$stCancel->fetchColumn() === 'cancelled') fail('Job je zrušený, výsledek se nenahraje.', 409, ['cancelled' => true]);
            if (empty($_FILES['video']) || ($_FILES['video']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) fail('Upload výsledku selhal.');
            $orig = $_FILES['video']['name'] ?? 'result.mp4';
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            // Video formáty + obrázkové výstupy (PHOTO EDIT režim, např. Flux.2 edit).
            $allowed = array_merge(unserialize(ALLOWED_VIDEO_EXT), ['png', 'jpg', 'jpeg', 'webp']);
            if (!in_array($ext, $allowed, true)) $ext = 'mp4';
            $name = 'job_' . $id . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $rel = 'outputs/' . $name;
            $dst = safe_join(__DIR__, $rel);
            if (!move_uploaded_file($_FILES['video']['tmp_name'], $dst)) fail('Nelze uložit výsledek.');

            $st = db()->prepare('SELECT output_files FROM comfy_jobs WHERE id=?'); $st->execute([$id]);
            $cur = $st->fetchColumn();
            $files = $cur ? (json_decode((string)$cur, true) ?: []) : [];
            $files[] = ['rel' => $rel, 'name' => $orig, 'size' => filesize($dst), 'uploaded_at' => date('Y-m-d H:i:s')];
            $upDone = db()->prepare("UPDATE comfy_jobs SET output_video=COALESCE(output_video, ?), output_files=?, status='done', progress=100, updated_at=datetime('now'), finished_at=COALESCE(finished_at, datetime('now')), duration_seconds=CASE WHEN started_at IS NOT NULL THEN (julianday('now')-julianday(started_at))*86400 ELSE duration_seconds END WHERE id=? AND status<>'cancelled'");
            $upDone->execute([$rel, json_encode($files, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
            if ($upDone->rowCount() < 1) {
                @unlink($dst);
                fail('Job byl mezitím zrušen, výsledek zahazuji.', 409, ['cancelled' => true]);
            }
            // Vstupní obrázek ponecháváme, aby šlo hotový job kdykoliv znovu spustit.
            $deletedInput = false;
            $cleanedUploads = cleanup_uploads_dir();
            add_event($id, 'result', 'Výsledné video nahráno, vstupní obrázek ponechán pro rerun', ['file' => $rel, 'size' => filesize($dst), 'deleted_input' => $deletedInput, 'cleaned_uploads' => $cleanedUploads]);
            touch_dashboard_cache();
            json_out(['success' => true, 'url' => public_url($rel), 'rel' => $rel, 'deleted_input' => $deletedInput, 'cleaned_uploads' => $cleanedUploads]);

        case 'sync_stats':
            if ($method !== 'POST') fail('Method not allowed', 405);
            require_token();
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            $data = [
                'gpu' => $body['gpu'] ?? null,
                'ram' => $body['ram'] ?? null,
                'disk' => $body['disk'] ?? null,
                'comfy' => $body['comfy'] ?? null,
                'worker' => $body['worker'] ?? null,
                'updated_at' => gmdate('Y-m-d\\TH:i:s') . 'Z',
            ];
            file_put_contents(cache_dir() . '/stats.json', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            // Multi-worker stav: DOMA a PRACE se nebudou navzájem přepisovat.
            $workersFile = cache_dir() . '/stats_workers.json';
            $workers = [];
            if (file_exists($workersFile)) {
                $decoded = json_decode((string)file_get_contents($workersFile), true);
                if (is_array($decoded)) $workers = $decoded;
            }
            $wid = (string)($data['worker']['id'] ?? 'worker');
            $workers[$wid] = $data;
            foreach ($workers as $k => $v) {
                $ts = strtotime((string)($v['updated_at'] ?? ''));
                if ($ts && time() - $ts > 86400) unset($workers[$k]);
            }
            file_put_contents($workersFile, json_encode($workers, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            touch_dashboard_cache();

            json_out(['success' => true]);

        case 'diagnostics':
            require_any();
            $checks = [];
            $addCheck = function(string $name, string $status, string $message) use (&$checks) {
                $checks[] = ['name' => $name, 'status' => $status, 'message' => $message];
            };
            $addCheck('PHP', 'ok', PHP_VERSION);
            try {
                $cnt1 = (int)db()->query("SELECT COUNT(*) FROM comfy_projects WHERE active=1 AND workflow_file='workflows/ltx23_i2v_template.json'")->fetchColumn();
                $cnt2 = (int)db()->query("SELECT COUNT(*) FROM comfy_projects WHERE active=1 AND workflow_file='workflows/ltx23_flf2v_template.json'")->fetchColumn();
                $addCheck('1 PICT project DB', $cnt1 > 0 ? 'ok' : 'bad', $cnt1 > 0 ? 'Projekt je v databázi.' : 'Projekt chybí v databázi.');
                $addCheck('2 PICT project DB', $cnt2 > 0 ? 'ok' : 'bad', $cnt2 > 0 ? 'Projekt je v databázi.' : 'Projekt chybí v databázi.');
            } catch (Throwable $e) { $addCheck('Project DB import', 'bad', $e->getMessage()); }
            try { db()->query('SELECT 1'); $addCheck('SQLite', 'ok', 'Databáze dostupná.'); } catch (Throwable $e) { $addCheck('SQLite', 'bad', $e->getMessage()); }
            foreach (['uploads','outputs','tmp','cache','workflows'] as $dir) {
                $path = safe_join(__DIR__, $dir);
                $addCheck($dir . '/', is_dir($path) && is_writable($path) ? 'ok' : 'bad', is_dir($path) ? (is_writable($path) ? 'Zapisovatelná složka.' : 'Složka není zapisovatelná.') : 'Složka neexistuje.');
            }
            foreach (['workflows/ltx23_i2v_template.json' => '1 PICT workflow', 'workflows/ltx23_flf2v_template.json' => '2 PICT workflow'] as $wf => $label) {
                $path = safe_join(__DIR__, $wf);
                if (!is_file($path)) { $addCheck($label, 'bad', 'Soubor chybí: ' . $wf); continue; }
                $json = json_decode((string)file_get_contents($path), true);
                $addCheck($label, is_array($json) ? 'ok' : 'bad', is_array($json) ? 'JSON OK.' : 'JSON nejde načíst.');
                if (is_array($json)) {
                    $hasTok = false; $hasEnh = false;
                    foreach ($json as $node) {
                        if (!is_array($node)) continue;
                        $ct = (string)($node['class_type'] ?? '');
                        $title = strtolower((string)($node['_meta']['title'] ?? ''));
                        if ($ct === 'TextGenerateLTX2Prompt') $hasTok = true;
                        if ($ct === 'PrimitiveBoolean' && (str_contains($title, 'prompt enhance') || str_contains($title, 'enable prompt'))) $hasEnh = true;
                    }
                    $addCheck($label . ' tokens', $hasTok ? 'ok' : 'warn', $hasTok ? 'TextGenerateLTX2Prompt nalezen.' : 'Token node v této šabloně není, funkce se přeskočí.');
                    $addCheck($label . ' Prompt Enhance', $hasEnh ? 'ok' : 'warn', $hasEnh ? 'Prompt Enhance boolean nalezen.' : 'Prompt Enhance node v této šabloně není, funkce se přeskočí.');
                }
            }
            $workersFile = cache_dir() . '/stats_workers.json';
            $workersRaw = file_exists($workersFile) ? json_decode((string)file_get_contents($workersFile), true) : [];
            $workersOut = [];
            if (is_array($workersRaw)) {
                foreach ($workersRaw as $wid => $wx) {
                    $ts = strtotime((string)($wx['updated_at'] ?? '')) ?: 0;
                    $age = $ts ? time() - $ts : 999999;
                    $w = is_array($wx['worker'] ?? null) ? $wx['worker'] : [];
                    $c = is_array($wx['comfy'] ?? null) ? $wx['comfy'] : [];
                    $workersOut[] = ['id' => (string)$wid, 'version' => (string)($w['version'] ?? 'old'), 'state' => ($age < 240 ? 'online' : 'offline/stale'), 'comfy' => !empty($c['online']) ? 'ready' : (string)($c['state'] ?? 'offline')];
                }
            }
            $workerOk = false; $workerOld = false;
            foreach ($workersOut as $w) { if ($w['state'] === 'online') { $workerOk = true; if (($w['version'] ?? '') !== EXPECTED_WORKER_VERSION) $workerOld = true; } }
            $addCheck('Worker', $workerOk ? ($workerOld ? 'warn' : 'ok') : 'warn', $workerOk ? ($workerOld ? 'Některý worker je starý, stáhni nový ZIP.' : 'Worker online a aktuální.') : 'Žádný aktuální worker signál.');
            json_out(['success' => true, 'expected_worker_version' => EXPECTED_WORKER_VERSION, 'checks' => $checks, 'workers' => $workersOut]);

        case 'stats':
            require_any();
            worker_watchdog_check(false);
            $file = cache_dir() . '/stats.json';
            $data = file_exists($file) ? json_decode(file_get_contents($file), true) : null;
            $workersFile = cache_dir() . '/stats_workers.json';
            $workers = file_exists($workersFile) ? json_decode(file_get_contents($workersFile), true) : null;
            json_out(['success' => true, 'data' => $data, 'workers' => is_array($workers) ? $workers : [], 'queue_counts' => queue_counts()]);


        case 'request_comfy_start':
        case 'request_comfyui_start':
        case 'start_comfy':
            if ($method !== 'POST') fail('Method not allowed', 405);
            require_session();
            require_csrf();
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            $target = normalize_worker_target((string)($body['target_worker'] ?? 'any'));
            $reason = clean_text((string)($body['reason'] ?? 'Ruční start ComfyUI z webu'), 300);
            $req = queue_worker_command($target, 'start_comfy', $reason, null);
            json_out(['success' => true, 'message' => 'Požadavek na spuštění ComfyUI byl odeslán workeru.', 'request' => $req]);

        case 'request_worker_restart':
            if ($method !== 'POST') fail('Method not allowed', 405);
            require_session();
            require_csrf();
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            $target = normalize_worker_target((string)($body['target_worker'] ?? 'any'));
            $reason = clean_text((string)($body['reason'] ?? 'Ruční restart workeru z webu'), 300);
            $req = queue_worker_restart($target, $reason, null);
            json_out(['success' => true, 'message' => 'Požadavek na restart workeru byl odeslán.', 'request' => $req]);

        case 'worker_control':
            if ($method !== 'POST') fail('Method not allowed', 405);
            require_token();
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            $worker = clean_text((string)($body['worker_id'] ?? 'worker'), 100);
            $activeJobId = (int)($body['active_job_id'] ?? 0);
            $activePromptId = clean_text((string)($body['active_prompt_id'] ?? ''), 120);
            $secondsSinceActivity = max(0, (int)($body['seconds_since_activity'] ?? 0));
            $comfyState = is_array($body['comfy'] ?? null) ? $body['comfy'] : null;

            // Worker-control heartbeat zároveň udržuje multi-worker stav čerstvý i během dlouhých renderů.
            // Web tak neoznačí worker jako offline jen proto, že hlavní vlákno právě čeká na ComfyUI.
            $workersFile = cache_dir() . '/stats_workers.json';
            $workers = [];
            if (file_exists($workersFile)) {
                $decoded = json_decode((string)file_get_contents($workersFile), true);
                if (is_array($decoded)) $workers = $decoded;
            }
            $prev = is_array($workers[$worker] ?? null) ? $workers[$worker] : [];
            $prevWorker = is_array($prev['worker'] ?? null) ? $prev['worker'] : [];
            $prevComfy = is_array($prev['comfy'] ?? null) ? $prev['comfy'] : [];
            $gpuState = is_array($body['gpu'] ?? null) ? $body['gpu'] : ($prev['gpu'] ?? null);
            $ramState = is_array($body['ram'] ?? null) ? $body['ram'] : ($prev['ram'] ?? null);
            $workers[$worker] = array_merge($prev, [
                'gpu' => $gpuState,
                'ram' => $ramState,
                'worker' => array_merge($prevWorker, [
                    'id' => $worker,
                    'last_job' => $activeJobId ?: (int)($prevWorker['last_job'] ?? 0),
                    'active_job' => $activeJobId,
                    'active_prompt_id' => $activePromptId,
                    'seconds_since_activity' => $secondsSinceActivity,
                    'control_heartbeat' => true,
                ]),
                'comfy' => $comfyState ? array_merge($prevComfy, $comfyState) : $prevComfy,
                'updated_at' => gmdate('Y-m-d\TH:i:s') . 'Z',
            ]);
            foreach ($workers as $k => $v) {
                $ts = strtotime((string)($v['updated_at'] ?? ''));
                if ($ts && time() - $ts > 86400) unset($workers[$k]);
            }
            file_put_contents($workersFile, json_encode($workers, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            touch_dashboard_cache();

            worker_watchdog_check(false, $worker, $activeJobId);
            $req = next_worker_restart_request($worker);
            if ($req) {
                json_out(['success' => true, 'restart' => true, 'request' => $req, 'exit_code' => 75]);
            }
            $cmd = next_worker_command($worker);
            if ($cmd) {
                json_out(['success' => true, 'restart' => false, 'command' => $cmd, 'watchdog_seconds' => WORKER_WATCHDOG_SECONDS]);
            }
            json_out(['success' => true, 'restart' => false, 'watchdog_seconds' => WORKER_WATCHDOG_SECONDS]);

        case 'cleanup_uploads':
            if ($method !== 'POST') fail('Method not allowed', 405);
            require_session();
            require_csrf();
            $cleanedUploads = cleanup_uploads_dir();
            if ($cleanedUploads > 0) touch_dashboard_cache();
            json_out(['success' => true, 'cleaned_uploads' => $cleanedUploads]);

        case 'worker_ping':
            if ($method !== 'POST') fail('Method not allowed', 405);
            require_token();
            json_out(['success' => true, 'time' => date('Y-m-d H:i:s')]);

        default:
            fail('Neznámá akce: ' . $action, 404);
    }
} catch (Throwable $e) {
    fail('Server error: ' . $e->getMessage(), 500);
}
