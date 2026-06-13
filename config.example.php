<?php

// Fallback pro hostingy bez mbstring.
if (!function_exists('mb_substr')) {
    function mb_substr($string, $start, $length = null, $encoding = null) {
        return $length === null ? substr((string)$string, (int)$start) : substr((string)$string, (int)$start, (int)$length);
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen($string, $encoding = null) { return strlen((string)$string); }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($string, $encoding = null) { return strtolower((string)$string); }
}

// ============================================================
//  PZ COMFY VIDEO REMOTE — bezpečná konfigurace (VZOROVÝ SOUBOR)
//
//  POUŽITÍ:
//  1) Zkopíruj tento soubor jako config.php (config.php NIKDY necommituj).
//  2) Vyplň LOGIN_USERNAME, LOGIN_PASSWORD_HASH a WORKER_TOKEN_PEPPER níže.
//  3) Soubor config.php musí být na serveru blokovaný přes .htaccess
//     (přibalený .htaccess to už dělá).
// ============================================================

// Přihlášení: heslo není uložené v plaintextu, jen jako bcrypt hash.
// Hash svého hesla vygeneruješ příkazem:
//   php -r "echo password_hash('TvojeNoveHeslo', PASSWORD_BCRYPT, ['cost' => 12]);"
// Výsledek (začíná $2y$12$...) vlož místo prázdného řetězce níže.
// Dokud je hash prázdný, nejde se přihlásit.
define('LOGIN_USERNAME', 'admin');
define('LOGIN_PASSWORD_HASH', '');
define('DISABLE_LEGACY_MASTER_LOGIN', true);

// Worker tokeny: každý stažený worker ZIP dostane vlastní token.
// Na serveru se ukládá jen HMAC hash tokenu, nikdy plaintext.
// PEPPER je tajná sůl pro HMAC — vygeneruj si vlastní náhodný řetězec:
//   php -r "echo 'pzpep_' . rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');"
// POZOR: po změně pepperu přestanou platit všechny dříve vydané worker tokeny.
define('WORKER_TOKEN_PEPPER', 'pzpep_DOPLN_VLASTNI_NAHODNY_RETEZEC');
define('WORKER_TOKEN_PREFIX', 'pzwrk_');
define('WORKER_TOKEN_DEFAULT_TTL_DAYS', 180);
define('AUTO_PURGE_FINISHED_AFTER_HOURS', 48);

function pz_base64url_random(int $bytes = 48): string {
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function pz_worker_token_pdo(): PDO {
    static $pdo = null;
    if (!$pdo) {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA busy_timeout=5000');
        $pdo->exec("CREATE TABLE IF NOT EXISTS comfy_worker_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token_hash TEXT NOT NULL UNIQUE,
            label TEXT,
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT(datetime('now')),
            created_by TEXT,
            last_seen TEXT,
            last_ip TEXT,
            last_user_agent TEXT,
            revoked_at TEXT,
            expires_at TEXT
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_worker_tokens_active ON comfy_worker_tokens(active, expires_at)");
    }
    return $pdo;
}

function pz_worker_token_hash(string $token): string {
    return hash_hmac('sha256', $token, WORKER_TOKEN_PEPPER);
}

function pz_issue_worker_token(string $label = 'worker', string $createdBy = ''): string {
    $label = trim(mb_substr($label, 0, 120));
    if ($label === '') $label = 'worker';
    $createdBy = trim(mb_substr($createdBy, 0, 120));
    $token = WORKER_TOKEN_PREFIX . pz_base64url_random(48);
    $hash = pz_worker_token_hash($token);
    $expires = date('Y-m-d H:i:s', time() + WORKER_TOKEN_DEFAULT_TTL_DAYS * 86400);
    $pdo = pz_worker_token_pdo();
    $st = $pdo->prepare("INSERT INTO comfy_worker_tokens(token_hash,label,created_by,expires_at) VALUES(?,?,?,?)");
    $st->execute([$hash, $label, $createdBy, $expires]);
    return $token;
}

function pz_verify_worker_token(string $token): bool {
    $token = trim($token);
    if ($token === '' || strlen($token) > 180 || !str_starts_with($token, WORKER_TOKEN_PREFIX)) return false;
    $hash = pz_worker_token_hash($token);
    try {
        $pdo = pz_worker_token_pdo();
        $st = $pdo->prepare("SELECT id, active, expires_at FROM comfy_worker_tokens WHERE token_hash=? LIMIT 1");
        $st->execute([$hash]);
        $row = $st->fetch();
        if (!$row || (int)$row['active'] !== 1) return false;
        if (!empty($row['expires_at']) && strtotime((string)$row['expires_at']) < time()) return false;
        $ip = pz_client_ip_simple();
        $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 240);
        $pdo->prepare("UPDATE comfy_worker_tokens SET last_seen=datetime('now'), last_ip=?, last_user_agent=? WHERE id=?")
            ->execute([$ip, $ua, (int)$row['id']]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function pz_list_worker_tokens(): array {
    try {
        $pdo = pz_worker_token_pdo();
        return $pdo->query("SELECT id,label,active,created_at,created_by,last_seen,last_ip,revoked_at,expires_at FROM comfy_worker_tokens ORDER BY id DESC LIMIT 200")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function pz_revoke_worker_token(int $id): bool {
    if ($id <= 0) return false;
    try {
        $st = pz_worker_token_pdo()->prepare("UPDATE comfy_worker_tokens SET active=0, revoked_at=datetime('now') WHERE id=?");
        $st->execute([$id]);
        return $st->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function pz_revoke_all_worker_tokens(): int {
    try {
        $st = pz_worker_token_pdo()->prepare("UPDATE comfy_worker_tokens SET active=0, revoked_at=datetime('now') WHERE active=1");
        $st->execute();
        return $st->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}



// Session cookie
define('SESSION_NAME', 'pz_comfy_secure_2026');

// SQLite databáze
define('DB_PATH', __DIR__ . '/db.sqlite');

// Složky
define('UPLOAD_DIR', __DIR__ . '/uploads');
define('OUTPUT_DIR', __DIR__ . '/outputs');
define('TMP_DIR', __DIR__ . '/tmp');
define('WORKFLOW_DIR', __DIR__ . '/workflows');

// Limity
define('MAX_IMAGE_BYTES', 60 * 1024 * 1024); // 60 MB
define('MAX_VIDEO_BYTES', 2 * 1024 * 1024 * 1024); // 2 GB — reálně závisí na PHP/hosting limitu
define('RATE_LIMIT_PER_HOUR', 200);
define('LOGIN_RATE_LIMIT_PER_15_MIN', 12);

// Povolené typy vstupních obrázků
define('ALLOWED_IMAGE_MIME', serialize([
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
]));

// Povolené typy výsledných videí
define('ALLOWED_VIDEO_EXT', serialize(['mp4', 'webm', 'mov', 'mkv', 'gif']));

// Automatický překlad promptu do angličtiny
define('TRANSLATE_PROMPT_DEFAULT', true);
define('TRANSLATE_PROVIDER', 'google_gtx');
define('TRANSLATE_SOURCE_LANG', 'cs');
define('TRANSLATE_TARGET_LANG', 'en');
define('TRANSLATE_TIMEOUT_SECONDS', 12);

// Název webu
define('APP_TITLE', 'PZ COMFY VIDEO REMOTE');

function pz_is_https(): bool {
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        return strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
    }
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
}

function pz_security_headers(bool $noStore = true): void {
    if (!headers_sent()) {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
        if ($noStore) header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        if (pz_is_https()) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function pz_start_secure_session(): void {
    if (session_status() !== PHP_SESSION_NONE) return;
    session_name(SESSION_NAME);
    $secure = pz_is_https();
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(0, '/; samesite=Lax', '', $secure, true);
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

function pz_csrf_token(): string {
    pz_start_secure_session();
    return (string)($_SESSION['csrf_token'] ?? '');
}

function pz_verify_csrf(): bool {
    pz_start_secure_session();
    $sent = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($sent === '' && isset($_POST['csrf_token'])) $sent = (string)$_POST['csrf_token'];
    $real = (string)($_SESSION['csrf_token'] ?? '');
    return $sent !== '' && $real !== '' && hash_equals($real, $sent);
}

function pz_verify_config_login(string $username, string $password): bool {
    $username = trim($username);
    if ($username === '' || !hash_equals(LOGIN_USERNAME, $username)) return false;
    return password_verify($password, LOGIN_PASSWORD_HASH);
}

function pz_login_session(array $user): void {
    pz_start_secure_session();
    session_regenerate_id(true);
    $_SESSION['authenticated'] = true;
    $_SESSION['role'] = (string)($user['role'] ?? 'admin');
    $_SESSION['is_admin'] = (($_SESSION['role'] ?? '') === 'admin');
    $_SESSION['username'] = (string)($user['username'] ?? LOGIN_USERNAME);
    if (!empty($user['id'])) $_SESSION['user_id'] = (int)$user['id'];
    else unset($_SESSION['user_id']);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function pz_logout_session(): void {
    pz_start_secure_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
    }
    session_destroy();
}

function pz_client_ip_simple(): string {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $v = trim(explode(',', (string)$_SERVER[$k])[0]);
            if (filter_var($v, FILTER_VALIDATE_IP)) return $v;
        }
    }
    return 'unknown';
}

function pz_login_throttle_check(PDO $pdo): ?string {
    $pdo->exec("CREATE TABLE IF NOT EXISTS comfy_login_failures(ip TEXT NOT NULL, username TEXT, created_at TEXT NOT NULL DEFAULT(datetime('now')))");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_login_failures_ip ON comfy_login_failures(ip,created_at)");
    $pdo->prepare("DELETE FROM comfy_login_failures WHERE created_at < datetime('now','-15 minutes')")->execute();
    $ip = pz_client_ip_simple();
    $st = $pdo->prepare("SELECT COUNT(*) FROM comfy_login_failures WHERE ip=?");
    $st->execute([$ip]);
    if ((int)$st->fetchColumn() >= LOGIN_RATE_LIMIT_PER_15_MIN) {
        return 'Moc špatných pokusů. Zkus to za pár minut.';
    }
    return null;
}

function pz_login_throttle_record(PDO $pdo, string $username, bool $success): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS comfy_login_failures(ip TEXT NOT NULL, username TEXT, created_at TEXT NOT NULL DEFAULT(datetime('now')))");
        $ip = pz_client_ip_simple();
        if ($success) {
            $pdo->prepare("DELETE FROM comfy_login_failures WHERE ip=?")->execute([$ip]);
        } else {
            $pdo->prepare("INSERT INTO comfy_login_failures(ip,username) VALUES(?,?)")->execute([$ip, mb_substr($username, 0, 80)]);
        }
    } catch (Throwable $e) {}
}
