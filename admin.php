<?php
// ============================================================
//  PZ COMFY — Admin panel (projekty + uzivatele)
// ============================================================
require_once __DIR__ . '/config.php';
pz_security_headers();
pz_start_secure_session();

$auth  = !empty($_SESSION['authenticated']);
$admin = $auth && (!empty($_SESSION['is_admin']) || (($_SESSION['role'] ?? '') === 'admin'));

// Logout
if (isset($_GET['logout'])) { pz_logout_session(); header('Location: admin.php'); exit; }

// Login POST
$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $pass = (string)$_POST['password'];
    $user = trim((string)($_POST['username'] ?? ''));
    $loggedIn = false;
    try {
        if ($user === '') {
            $loginError = 'Zadejte uživatelské jméno.';
        } else {
            $pdo  = new PDO('sqlite:' . DB_PATH);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA busy_timeout=5000');
            $pdo->exec("CREATE TABLE IF NOT EXISTS comfy_users(id INTEGER PRIMARY KEY AUTOINCREMENT,username TEXT NOT NULL UNIQUE,password_hash TEXT NOT NULL,role TEXT NOT NULL DEFAULT 'user',active INTEGER NOT NULL DEFAULT 1,created_at TEXT NOT NULL DEFAULT(datetime('now')),last_login TEXT)");
            if ($msg = pz_login_throttle_check($pdo)) {
                $loginError = $msg;
            } else {
                $st = $pdo->prepare("SELECT * FROM comfy_users WHERE username=? AND active=1");
                $st->execute([$user]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row && password_verify($pass, $row['password_hash']) && $row['role'] === 'admin') {
                    pz_login_session($row);
                    $pdo->prepare("UPDATE comfy_users SET last_login=datetime('now') WHERE id=?")->execute([$row['id']]);
                    pz_login_throttle_record($pdo, $user, true);
                    $loggedIn = true;
                } elseif (pz_verify_config_login($user, $pass)) {
                    pz_login_session(['username' => LOGIN_USERNAME, 'role' => 'admin']);
                    pz_login_throttle_record($pdo, $user, true);
                    $loggedIn = true;
                } else {
                    pz_login_throttle_record($pdo, $user, false);
                    $loginError = 'Nesprávné jméno, heslo nebo nemáte admin práva.';
                }
            }
        }
    } catch (Throwable $e) {
        $loginError = 'Chyba přihlášení.';
    }
    if ($loggedIn) { header('Location: admin.php'); exit; }
}


if (!$admin) { // zobraz login — i pokud má session bez admin práv
?><!doctype html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin — PZ Comfy</title>
<style>@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@600;700&display=swap');:root{--grad:linear-gradient(115deg,#2f7bff,#38bdf8);--bd2:#363d5e;--mut:#9097b6}*{box-sizing:border-box}body{font-family:'Inter',system-ui,sans-serif;color:#eef0fb;margin:0;display:grid;min-height:100dvh;place-items:center;padding:20px;background:radial-gradient(950px 560px at 88% -12%,rgba(47,123,255,.22),transparent 56%),radial-gradient(780px 500px at 0% -6%,rgba(56,189,248,.15),transparent 55%),#0a0c16;background-attachment:fixed}.box{position:relative;width:100%;max-width:380px;background:rgba(18,21,38,.62);backdrop-filter:saturate(1.15) blur(18px);-webkit-backdrop-filter:saturate(1.15) blur(18px);border:1px solid var(--bd2);border-radius:24px;padding:32px;overflow:hidden;box-shadow:0 30px 80px rgba(0,0,0,.5),inset 0 1px 0 rgba(255,255,255,.06)}.box::before{content:"";position:absolute;inset:0 0 auto 0;height:3px;background:var(--grad)}.logo{font-family:'Space Grotesk',sans-serif;font-weight:700;text-align:center;font-size:17px;margin-bottom:24px;letter-spacing:.04em;background:var(--grad);-webkit-background-clip:text;background-clip:text;color:transparent}.field{display:flex;flex-direction:column;gap:6px;margin-bottom:12px}label{font-size:11px;color:var(--mut);text-transform:uppercase;letter-spacing:.06em;font-weight:600}input{background:rgba(255,255,255,.04);color:#f3f4ff;border:1px solid var(--bd2);border-radius:10px;padding:11px 12px;outline:none;width:100%;transition:.15s}input:focus{border-color:#2f7bff;box-shadow:0 0 0 3px rgba(47,123,255,.22);background:rgba(255,255,255,.06)}.btn{width:100%;background:var(--grad);border:none;color:#fff;border-radius:11px;padding:12px;font-size:15px;font-weight:700;cursor:pointer;margin-top:4px;box-shadow:0 8px 22px rgba(47,123,255,.4)}.btn:hover{filter:brightness(1.08)}.err{color:#ffb3bc;text-align:center;font-size:13px;margin-top:12px;padding:9px 11px;border-radius:9px;background:rgba(255,107,129,.11);border:1px solid rgba(255,107,129,.42)}</style>
</head><body><div class="box">
<div class="logo">&#9881; PZ COMFY ADMIN</div>
<form method="post">
<div class="field"><label>Uživatelské jméno</label><input name="username" autocomplete="username" value="" required></div>
<div class="field"><label>Heslo</label><input name="password" type="password" autofocus autocomplete="current-password" required></div>
<button class="btn">Přihlásit se do adminu</button>
<?php if($loginError):?><div class="err"><?=htmlspecialchars($loginError)?></div><?php endif;?>
</form></div></body></html>
<?php exit; }

// ── Helpers ──────────────────────────────────────────────────────────────────
function db2(): PDO {
    static $p = null;
    if (!$p) {
        $p = new PDO('sqlite:' . DB_PATH);
        $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $p->exec('PRAGMA journal_mode=WAL');
        $p->exec('PRAGMA busy_timeout=5000');
    }
    return $p;
}
function slug(string $s): string {
    $s = mb_strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    return trim($s, '_') ?: 'project';
}

// ── AJAX akce ─────────────────────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $act = $_GET['ajax'];
    try {
        $pdo = db2();
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !pz_verify_csrf()) {
            http_response_code(403);
            echo json_encode(['ok'=>false,'error'=>'Neplatný bezpečnostní token formuláře']);
            exit;
        }
        if ($act === 'list_projects') {
            $rows = $pdo->query("SELECT * FROM comfy_projects ORDER BY sort_order,id")->fetchAll();
            echo json_encode(['ok'=>true,'rows'=>$rows]);
        } elseif ($act === 'list_users') {
            $rows = $pdo->query("SELECT id,username,role,active,created_at,last_login FROM comfy_users ORDER BY id")->fetchAll();
            echo json_encode(['ok'=>true,'rows'=>$rows]);
        } elseif ($act === 'delete_project' && $_SERVER['REQUEST_METHOD']==='POST') {
            $b = json_decode(file_get_contents('php://input'),true)??[];
            $id = (int)($b['id']??0);
            $row = $pdo->prepare("SELECT workflow_file FROM comfy_projects WHERE id=?")->execute([$id]) ? $pdo->query("SELECT workflow_file FROM comfy_projects WHERE id=$id")->fetch() : [];
            if (!empty($row['workflow_file'])) @unlink(__DIR__.'/'.$row['workflow_file']);
            $pdo->prepare("DELETE FROM comfy_projects WHERE id=?")->execute([$id]);
            echo json_encode(['ok'=>true]);
        } elseif ($act === 'toggle_project' && $_SERVER['REQUEST_METHOD']==='POST') {
            $b = json_decode(file_get_contents('php://input'),true)??[];
            $pdo->prepare("UPDATE comfy_projects SET active=CASE WHEN active=1 THEN 0 ELSE 1 END WHERE id=?")->execute([(int)($b['id']??0)]);
            echo json_encode(['ok'=>true]);
        } elseif ($act === 'save_project' && $_SERVER['REQUEST_METHOD']==='POST') {
            $id   = (int)($_POST['id']??0);
            $name = trim((string)($_POST['name']??''));
            $desc = trim((string)($_POST['description']??''));
            $itype= in_array($_POST['input_type']??'image',['image','text','none'])?$_POST['input_type']:'image';
            $sort = (int)($_POST['sort_order']??0);
            $active=(int)(!empty($_POST['active']));
            if (!$name) { echo json_encode(['ok'=>false,'error'=>'Název je povinný']); exit; }
            // Workflow upload
            $wfFile = null;
            if (!empty($_FILES['workflow']) && $_FILES['workflow']['error']===UPLOAD_ERR_OK) {
                $dir = __DIR__.'/project_workflows';
                if (!is_dir($dir)) mkdir($dir,0755,true);
                $sfx = $id ? $id : (time().'_'.bin2hex(random_bytes(4)));
                $fname = $sfx.'_'.slug($name).'.json';
                $dst = $dir.'/'.$fname;
                // Ověř JSON
                $content = file_get_contents($_FILES['workflow']['tmp_name']);
                $parsed  = json_decode($content,true);
                if (!is_array($parsed)) { echo json_encode(['ok'=>false,'error'=>'Soubor není platný JSON']); exit; }
                if (!move_uploaded_file($_FILES['workflow']['tmp_name'],$dst)) { echo json_encode(['ok'=>false,'error'=>'Nelze uložit soubor']); exit; }
                $wfFile = 'project_workflows/'.$fname;
            }
            if ($id) {
                $fields = "name=?,description=?,input_type=?,sort_order=?,active=?,updated_at=datetime('now')";
                $vals   = [$name,$desc,$itype,$sort,$active];
                if ($wfFile) { $fields.=",workflow_file=?"; $vals[]=$wfFile; }
                $vals[] = $id;
                $pdo->prepare("UPDATE comfy_projects SET $fields WHERE id=?")->execute($vals);
            } else {
                $pdo->prepare("INSERT INTO comfy_projects(name,description,input_type,sort_order,active".($wfFile?",workflow_file":"").") VALUES(?,?,?,?,?".($wfFile?",?":"").")")
                    ->execute(array_merge([$name,$desc,$itype,$sort,$active],$wfFile?[$wfFile]:[]));
                $id=(int)$pdo->lastInsertId();
                // Přejmenuj soubor s ID
                if ($wfFile) {
                    $newName = $id.'_'.slug($name).'.json';
                    $newPath = __DIR__.'/project_workflows/'.$newName;
                    rename(__DIR__.'/'.$wfFile,$newPath);
                    $pdo->prepare("UPDATE comfy_projects SET workflow_file=? WHERE id=?")->execute(['project_workflows/'.$newName,$id]);
                }
            }
            echo json_encode(['ok'=>true,'id'=>$id]);
        } elseif ($act === 'delete_user' && $_SERVER['REQUEST_METHOD']==='POST') {
            $b = json_decode(file_get_contents('php://input'),true)??[];
            $id=(int)($b['id']??0);
            if (!$id) { echo json_encode(['ok'=>false,'error'=>'ID chybí']); exit; }
            $pdo->prepare("DELETE FROM comfy_users WHERE id=?")->execute([$id]);
            echo json_encode(['ok'=>true]);
        } elseif ($act === 'save_user' && $_SERVER['REQUEST_METHOD']==='POST') {
            $b = json_decode(file_get_contents('php://input'),true)??[];
            $id   = (int)($b['id']??0);
            $user = trim((string)($b['username']??''));
            $pass = (string)($b['password']??'');
            $role = in_array($b['role']??'user',['admin','user'])?$b['role']:'user';
            $active=(int)(!empty($b['active']));
            if (!$user) { echo json_encode(['ok'=>false,'error'=>'Jméno je povinné']); exit; }
            if ($id) {
                if ($pass) {
                    $pdo->prepare("UPDATE comfy_users SET username=?,role=?,active=?,password_hash=? WHERE id=?")->execute([$user,$role,$active,password_hash($pass,PASSWORD_DEFAULT),$id]);
                } else {
                    $pdo->prepare("UPDATE comfy_users SET username=?,role=?,active=? WHERE id=?")->execute([$user,$role,$active,$id]);
                }
                try { $pdo->prepare("UPDATE comfy_jobs SET username=? WHERE user_id=?")->execute([$user,$id]); } catch(Throwable $e) {}
            } else {
                if (!$pass) { echo json_encode(['ok'=>false,'error'=>'Heslo je povinné']); exit; }
                try {
                    $pdo->prepare("INSERT INTO comfy_users(username,password_hash,role,active) VALUES(?,?,?,?)")->execute([$user,password_hash($pass,PASSWORD_DEFAULT),$role,$active]);
                    $id=(int)$pdo->lastInsertId();
                } catch(Throwable $e) { echo json_encode(['ok'=>false,'error'=>'Jméno již existuje']); exit; }
            }
            echo json_encode(['ok'=>true,'id'=>$id]);
        } else {
            echo json_encode(['ok'=>false,'error'=>'Neznámá akce']);
        }
    } catch(Throwable $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}
// Zajisti tabulky
try {
    $pdo = db2();
    $pdo->exec("CREATE TABLE IF NOT EXISTS comfy_projects(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL,description TEXT,workflow_file TEXT,input_type TEXT NOT NULL DEFAULT 'image',settings_json TEXT,active INTEGER NOT NULL DEFAULT 1,sort_order INTEGER NOT NULL DEFAULT 0,created_at TEXT NOT NULL DEFAULT(datetime('now')),updated_at TEXT NOT NULL DEFAULT(datetime('now')))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS comfy_users(id INTEGER PRIMARY KEY AUTOINCREMENT,username TEXT NOT NULL UNIQUE,password_hash TEXT NOT NULL,role TEXT NOT NULL DEFAULT 'user',active INTEGER NOT NULL DEFAULT 1,created_at TEXT NOT NULL DEFAULT(datetime('now')),last_login TEXT)");
    try{$pdo->exec("ALTER TABLE comfy_jobs ADD COLUMN project_id INTEGER");}catch(Throwable $e){}
    try{$pdo->exec("ALTER TABLE comfy_jobs ADD COLUMN user_id INTEGER");}catch(Throwable $e){}
    try{$pdo->exec("ALTER TABLE comfy_jobs ADD COLUMN username TEXT");}catch(Throwable $e){}
    try{$pdo->exec('CREATE INDEX IF NOT EXISTS idx_comfy_jobs_user ON comfy_jobs(user_id)');}catch(Throwable $e){}
} catch(Throwable $e) {}
?><!doctype html>
<html lang="cs"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<title>Admin — PZ Comfy</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&family=JetBrains+Mono:wght@500;600&display=swap');
:root{--bg:#0a0c16;--glass:rgba(255,255,255,.045);--glass2:rgba(255,255,255,.075);--glass3:rgba(255,255,255,.11);
  --bd:#272c44;--bd2:#363d5e;--txt:#eef0fb;--mut:#9097b6;--mut2:#6b7196;--ac:#2f7bff;--ac2:#38bdf8;
  --grad:linear-gradient(115deg,#2f7bff,#38bdf8);--ok:#34e3a4;--okbd:rgba(52,227,164,.42);--okbg:rgba(52,227,164,.12);
  --dng:#ff6b81;--dngbd:rgba(255,107,129,.42);--dngbg:rgba(255,107,129,.11);--warn:#ffcf6b;--warnbd:rgba(255,207,107,.42);--warnbg:rgba(255,207,107,.12);
  --blur:saturate(1.15) blur(16px);--font:'Inter',Segoe UI,system-ui,sans-serif;--display:'Space Grotesk',var(--font);--mono:'JetBrains Mono',Consolas,monospace}
*{box-sizing:border-box}html,body{margin:0;height:100%}
body{font-family:var(--font);color:var(--txt);
  background:radial-gradient(950px 560px at 88% -12%,rgba(47,123,255,.22),transparent 56%),radial-gradient(780px 500px at 0% -6%,rgba(56,189,248,.15),transparent 55%),var(--bg);background-attachment:fixed}
button,input,textarea,select{font:inherit}
::-webkit-scrollbar{width:10px;height:10px}::-webkit-scrollbar-thumb{background:#2a3050;border-radius:99px;border:2px solid transparent;background-clip:padding-box}
.topbar{position:sticky;top:0;z-index:5;background:rgba(10,12,22,.7);backdrop-filter:var(--blur);-webkit-backdrop-filter:var(--blur);
  border-bottom:1px solid var(--bd);padding:13px 20px;display:flex;align-items:center;gap:14px}
.topbar-logo{font-family:var(--display);font-weight:700;letter-spacing:.01em;font-size:16px;
  background:var(--grad);-webkit-background-clip:text;background-clip:text;color:transparent}
.topbar a{color:var(--mut);font-size:13px;text-decoration:none}.topbar a:hover{color:#d6d9f0}
.topbar-right{margin-left:auto;display:flex;gap:12px;align-items:center}
.main{max-width:1100px;margin:0 auto;padding:24px 16px}
.tabs{display:flex;gap:6px;border-bottom:1px solid var(--bd);margin-bottom:22px}
.tab{background:none;border:none;border-bottom:2px solid transparent;color:var(--mut);padding:10px 18px;cursor:pointer;font-size:14px;font-weight:600;margin-bottom:-1px}
.tab:hover{color:#d6d9f0}
.tab.active{color:#fff;border-bottom-color:transparent;background:var(--grad);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;position:relative}
.tab.active::after{content:"";position:absolute;left:10px;right:10px;bottom:-1px;height:2px;background:var(--grad);border-radius:2px}
.tab-panel{display:none}.tab-panel.active{display:block}
.card{position:relative;background:rgba(18,21,38,.5);backdrop-filter:var(--blur);-webkit-backdrop-filter:var(--blur);
  border:1px solid var(--bd);border-radius:18px;overflow:hidden;margin-bottom:16px;box-shadow:0 18px 44px rgba(0,0,0,.3),inset 0 1px 0 rgba(255,255,255,.05)}
.card::before{content:"";position:absolute;inset:0 0 auto 0;height:2px;background:var(--grad);opacity:.7}
.card-head{padding:15px 16px;border-bottom:1px solid var(--bd);display:flex;align-items:center;gap:10px}
.card-head h2{margin:0;font-family:var(--display);font-size:15px;font-weight:600;flex:1}
.card-body{padding:16px}
.btn{border:1px solid var(--bd2);background:var(--glass2);color:#eef0fb;border-radius:11px;padding:9px 14px;cursor:pointer;font-size:13px;font-weight:600;transition:.15s}
.btn:hover{background:var(--glass3);border-color:#46507d}
.btn.primary{background:var(--grad);border:none;color:#fff;font-weight:700;box-shadow:0 8px 20px rgba(47,123,255,.4)}
.btn.primary:hover{filter:brightness(1.08)}
.btn.red{background:var(--dngbg);border-color:var(--dngbd);color:var(--dng)}
.btn.red:hover{background:rgba(255,107,129,.18)}
.btn.green{background:var(--okbg);border-color:var(--okbd);color:var(--ok)}
.btn.green:hover{background:rgba(52,227,164,.18)}
.btn.sm{padding:5px 10px;font-size:12px}
.field{display:flex;flex-direction:column;gap:6px;margin-bottom:12px}
label{font-size:11px;color:var(--mut);text-transform:uppercase;letter-spacing:.06em;font-weight:600}
input[type=text],input[type=password],textarea,select{background:rgba(255,255,255,.04);color:#f3f4ff;border:1px solid var(--bd2);border-radius:10px;padding:10px 12px;outline:none;width:100%;transition:.15s}
input:focus,textarea:focus,select:focus{border-color:var(--ac);box-shadow:0 0 0 3px rgba(47,123,255,.22);background:rgba(255,255,255,.06)}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
select option,select optgroup{background:#12152a;color:#eef0fb}
.grid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
table{width:100%;border-collapse:collapse;font-size:13px}
th{color:var(--mut);font-weight:600;text-align:left;padding:9px 10px;border-bottom:1px solid var(--bd);font-size:11px;text-transform:uppercase;letter-spacing:.06em}
td{padding:10px;border-bottom:1px solid rgba(255,255,255,.05);vertical-align:middle}
tr:last-child td{border-bottom:none}
.badge{display:inline-block;border-radius:999px;padding:2px 10px;font-size:11px;font-weight:600}
.badge-on{background:var(--okbg);color:var(--ok);border:1px solid var(--okbd)}
.badge-off{background:var(--dngbg);color:var(--dng);border:1px solid var(--dngbd)}
.badge-admin{background:var(--warnbg);color:var(--warn);border:1px solid var(--warnbd)}
.badge-user{background:rgba(47,123,255,.12);color:#a8c9ff;border:1px solid var(--bd2)}
.actions-cell{display:flex;gap:6px;flex-wrap:wrap}
.modal-bg{display:none;position:fixed;inset:0;background:rgba(6,8,18,.72);backdrop-filter:blur(6px);z-index:100;place-items:center;padding:20px}
.modal-bg.open{display:grid}
.modal{background:rgba(20,23,42,.92);backdrop-filter:var(--blur);border:1px solid var(--bd2);border-radius:20px;padding:24px;width:100%;max-width:500px;max-height:92dvh;overflow-y:auto;box-shadow:0 30px 80px #000a}
.modal h3{margin:0 0 18px;font-family:var(--display);font-size:16px;font-weight:600}
.modal-foot{display:flex;gap:10px;justify-content:flex-end;margin-top:16px}
.err-msg{color:#ffb3bc;font-size:13px;margin-top:8px;display:none}.err-msg.show{display:block}
.input-file-label{border:1px dashed var(--bd2);border-radius:10px;padding:12px;text-align:center;cursor:pointer;color:var(--mut2);font-size:13px;transition:.15s}
.input-file-label:hover{border-color:var(--ac);color:#c8ddff}
.input-file-label.has-file{border-color:var(--okbd);color:var(--ok);background:var(--okbg)}
.small{font-size:12px;color:var(--mut2)}
.itype-badge{font-size:11px;border-radius:6px;padding:2px 7px;background:var(--glass);border:1px solid var(--bd2);color:var(--mut);font-family:var(--mono)}
@media(max-width:700px){.grid2,.grid3{grid-template-columns:1fr}.main{padding:16px 10px}}
</style>
</head>
<body>
<div class="topbar">
  <div class="topbar-logo">&#9881; PZ COMFY ADMIN</div>
  <a href="app.php">&#8592; Web</a>
  <div class="topbar-right">
    <?php if(!empty($_SESSION['username'])):?><span style="font-size:13px;color:#888"><?=htmlspecialchars($_SESSION['username'])?></span><?php endif;?>
    <a href="?logout">Odhlásit</a>
  </div>
</div>
<div class="main">
<!-- ADMIN_TAB_ORDER: users_first_2026_04_30 -->
  <div class="tabs">
    <button class="tab active" onclick="showTab('users')">&#128100; Uživatelé</button>
    <button class="tab" onclick="showTab('projects')">&#127381; Projekty / Modely</button>
  </div>

  <!-- TAB: UZIVATELE -->
  <div class="tab-panel active" id="tab-users">
    <div class="card">
      <div class="card-head">
        <h2>Uživatelé</h2>
        <button class="btn primary" onclick="openUserModal()">+ Přidat uživatele</button>
      </div>
      <div class="card-body" style="padding:0">
        <table><thead><tr><th>#</th><th>Jméno</th><th>Role</th><th>Stav</th><th>Poslední login</th><th>Akce</th></tr></thead>
          <tbody id="usersBody"><tr><td colspan="6" style="padding:20px;color:#666;text-align:center">Načítám…</td></tr></tbody>
        </table>
      </div>
    </div>
    <p class="small">Přidaní uživatelé se přihlašují jménem + heslem na hlavní stránce (<a href="app.php" style="color:#888">app.php</a>).<br>
    Role <b>admin</b> má přístup do tohoto admin panelu. Role <b>user</b> může jen zadávat joby.</p>
  </div>
</div>

  <!-- TAB: PROJEKTY -->
  <div class="tab-panel" id="tab-projects">
    <div class="card">
      <div class="card-head">
        <h2>Comfy projekty</h2>
        <button class="btn primary" onclick="openProjectModal()">+ Přidat projekt</button>
      </div>
      <div class="card-body" style="padding:0">
        <table id="projectsTable">
          <thead><tr><th>#</th><th>Název</th><th>Typ vstupu</th><th>Workflow</th><th>Stav</th><th>Akce</th></tr></thead>
          <tbody id="projectsBody"><tr><td colspan="6" style="padding:20px;color:#666;text-align:center">Načítám…</td></tr></tbody>
        </table>
      </div>
    </div>
    <p class="small">Každý projekt má vlastní ComfyUI API workflow JSON. Worker ho stáhne a použije pro generování.<br>
    <b>Input type "image"</b> = i2v (LTX, Wan2 i2v…) &nbsp;|&nbsp; <b>"text"</b> = t2v/t2i (Wan2 t2v, SDXL…) &nbsp;|&nbsp; <b>"none"</b> = bez vstupu</p>
  </div>

<!-- Modal: projekt -->
<div class="modal-bg" id="projectModal">
<div class="modal">
  <h3 id="projectModalTitle">Nový projekt</h3>
  <input type="hidden" id="pmId" value="">
  <div class="field"><label>Název projektu *</label><input type="text" id="pmName" placeholder="např. Wan2 animace, SDXL fotky…"></div>
  <div class="field"><label>Popis</label><textarea id="pmDesc" rows="2" placeholder="Volitelný popis modelu / nastavení"></textarea></div>
  <div class="grid2">
    <div class="field"><label>Typ vstupu</label>
      <select id="pmInputType">
        <option value="image">🖼 Image (i2v, i2i)</option>
        <option value="text">📝 Text only (t2v, t2i)</option>
        <option value="none">⚡ Bez vstupu</option>
      </select>
    </div>
    <div class="field"><label>Pořadí (sort)</label><input type="text" id="pmSort" value="0" style="width:80px"></div>
  </div>
  <div class="field">
    <label>ComfyUI API workflow JSON</label>
    <label class="input-file-label" id="pmWfLabel" for="pmWf">
      Klikni nebo přetáhni workflow .json soubor
    </label>
    <input type="file" id="pmWf" accept=".json,application/json" hidden>
    <div class="small" id="pmCurrentWf" style="margin-top:6px"></div>
  </div>
  <div class="field" style="flex-direction:row;align-items:center;gap:10px">
    <input type="checkbox" id="pmActive" checked style="width:auto">
    <label for="pmActive" style="text-transform:none;letter-spacing:0;margin:0">Projekt je aktivní (zobrazí se uživatelům)</label>
  </div>
  <div class="err-msg" id="pmErr"></div>
  <div class="modal-foot">
    <button class="btn" onclick="closeProjectModal()">Zrušit</button>
    <button class="btn primary" onclick="saveProject()">Uložit</button>
  </div>
</div>
</div>

<!-- Modal: uživatel -->
<div class="modal-bg" id="userModal">
<div class="modal">
  <h3 id="userModalTitle">Nový uživatel</h3>
  <input type="hidden" id="umId" value="">
  <div class="grid2">
    <div class="field"><label>Uživatelské jméno *</label><input type="text" id="umUser" autocomplete="off"></div>
    <div class="field"><label>Heslo <span id="umPassHint" style="color:#555;text-transform:none;font-size:11px">(povinné)</span></label><input type="password" id="umPass" autocomplete="new-password" placeholder="min. 4 znaky"></div>
  </div>
  <div class="field"><label>Role</label>
    <select id="umRole">
      <option value="user">user — může zadávat joby</option>
      <option value="admin">admin — má přístup do admin panelu</option>
    </select>
  </div>
  <div class="field" style="flex-direction:row;align-items:center;gap:10px">
    <input type="checkbox" id="umActive" checked style="width:auto">
    <label for="umActive" style="text-transform:none;letter-spacing:0;margin:0">Účet je aktivní</label>
  </div>
  <div class="err-msg" id="umErr"></div>
  <div class="modal-foot">
    <button class="btn" onclick="closeUserModal()">Zrušit</button>
    <button class="btn primary" onclick="saveUser()">Uložit</button>
  </div>
</div>
</div>

<script>
const CSRF='<?=htmlspecialchars(pz_csrf_token(), ENT_QUOTES, 'UTF-8')?>';
const esc=s=>(s??'').toString().replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
async function ajax(action,method='GET',body=null){
  const opt={method,credentials:'same-origin',headers:{'X-CSRF-Token':CSRF}};
  if(body instanceof FormData){opt.body=body;}
  else if(body){opt.headers['Content-Type']='application/json';opt.body=JSON.stringify(body);}
  const r=await fetch(`admin.php?ajax=${action}`,opt);
  return r.json();
}

// ── TABS ──────────────────────────────────────────────────────
function showTab(id){
  document.querySelectorAll('.tab-panel').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active'));
  document.getElementById('tab-'+id).classList.add('active');
  document.querySelectorAll('.tab').forEach(t=>{if(t.textContent.toLowerCase().includes(id==='projects'?'projekt':'uživ'))t.classList.add('active');});
  if(id==='projects')loadProjects();
  else loadUsers();
}

// ── PROJEKTY ──────────────────────────────────────────────────
let projectsById = {};
async function loadProjects(){
  const d=await ajax('list_projects');
  const tb=document.getElementById('projectsBody');
  projectsById = {};
  if(!d.rows||!d.rows.length){tb.innerHTML='<tr><td colspan="6" style="padding:20px;color:#666;text-align:center">Žádné projekty — přidej první.</td></tr>';return;}
  d.rows.forEach(p=>{projectsById[String(p.id)] = p;});
  tb.innerHTML=d.rows.map(p=>`<tr>
    <td style="color:#555;font-family:Consolas,monospace">#${p.id}</td>
    <td><b>${esc(p.name)}</b>${p.description?`<br><span style="color:#666;font-size:12px">${esc(p.description)}</span>`:''}</td>
    <td><span class="itype-badge">${esc(p.input_type)}</span></td>
    <td style="font-size:12px;color:#666">${p.workflow_file?'<span style="color:#7ddd9e">&#10003; '+esc(p.workflow_file.split('/').pop())+'</span>':'<span style="color:#ff8585">chybí</span>'}</td>
    <td><span class="badge ${p.active?'badge-on':'badge-off'}">${p.active?'aktivní':'skrytý'}</span></td>
    <td class="actions-cell">
      <button class="btn sm" onclick="openProjectModalById(${Number(p.id)})">Upravit</button>
      <button class="btn sm green" onclick="toggleProject(${Number(p.id)})">${p.active?'Skrýt':'Zobrazit'}</button>
      <button class="btn sm red" onclick="deleteProject(${Number(p.id)})">Smazat</button>
    </td></tr>`).join('');
}
function openProjectModalById(id){ openProjectModal(projectsById[String(id)] || null); }
function openProjectModal(p=null){
  document.getElementById('pmId').value=p?p.id:'';
  document.getElementById('pmName').value=p?p.name:'';
  document.getElementById('pmDesc').value=p?p.description||'':'';
  document.getElementById('pmInputType').value=p?p.input_type:'image';
  document.getElementById('pmSort').value=p?p.sort_order:0;
  document.getElementById('pmActive').checked=p?!!p.active:true;
  document.getElementById('pmCurrentWf').textContent=p&&p.workflow_file?'Aktuální: '+p.workflow_file.split('/').pop():'';
  document.getElementById('pmWfLabel').textContent='Klikni nebo přetáhni workflow .json soubor';
  document.getElementById('pmWfLabel').className='input-file-label';
  document.getElementById('pmWf').value='';
  document.getElementById('pmErr').className='err-msg';
  document.getElementById('projectModalTitle').textContent=p?'Upravit projekt':'Nový projekt';
  document.getElementById('projectModal').classList.add('open');
}
function closeProjectModal(){document.getElementById('projectModal').classList.remove('open');}
document.getElementById('pmWf').addEventListener('change',function(){
  const f=this.files[0];
  const lbl=document.getElementById('pmWfLabel');
  if(f){lbl.textContent='✓ '+f.name;lbl.className='input-file-label has-file';}
  else{lbl.textContent='Klikni nebo přetáhni workflow .json soubor';lbl.className='input-file-label';}
});
async function saveProject(){
  const fd=new FormData();
  fd.append('id',document.getElementById('pmId').value);
  fd.append('name',document.getElementById('pmName').value.trim());
  fd.append('description',document.getElementById('pmDesc').value.trim());
  fd.append('input_type',document.getElementById('pmInputType').value);
  fd.append('sort_order',document.getElementById('pmSort').value||'0');
  fd.append('active',document.getElementById('pmActive').checked?'1':'0');
  const wf=document.getElementById('pmWf').files[0];
  if(wf)fd.append('workflow',wf,wf.name);
  const err=document.getElementById('pmErr');
  const d=await ajax('save_project','POST',fd);
  if(d.ok){closeProjectModal();loadProjects();}
  else{err.textContent=d.error||'Chyba';err.className='err-msg show';}
}
async function toggleProject(id){const d=await ajax('toggle_project','POST',{id});if(d.ok)loadProjects();}
async function deleteProject(id){
  const p = projectsById[String(id)] || {};
  const name = p.name || ('#' + id);
  if(!confirm(`Smazat projekt "${name}"?\nWorkflow soubor bude také smazán.`))return;
  const d=await ajax('delete_project','POST',{id});
  if(d.ok)loadProjects();else alert(d.error);
}

// ── UZIVATELE ─────────────────────────────────────────────────
let usersById = {};
async function loadUsers(){
  const d=await ajax('list_users');
  const tb=document.getElementById('usersBody');
  usersById = {};
  if(!d.rows||!d.rows.length){tb.innerHTML='<tr><td colspan="6" style="padding:20px;color:#666;text-align:center">Žádní uživatelé. V balíčku je připraven účet admin.</td></tr>';return;}
  d.rows.forEach(u=>{usersById[String(u.id)] = u;});
  tb.innerHTML=d.rows.map(u=>`<tr>
    <td style="color:#555;font-family:Consolas,monospace">#${u.id}</td>
    <td><b>${esc(u.username)}</b></td>
    <td><span class="badge ${u.role==='admin'?'badge-admin':'badge-user'}">${esc(u.role)}</span></td>
    <td><span class="badge ${u.active?'badge-on':'badge-off'}">${u.active?'aktivní':'neaktivní'}</span></td>
    <td style="font-size:12px;color:#666">${esc(u.last_login||'—')}</td>
    <td class="actions-cell">
      <button class="btn sm" onclick="openUserModalById(${Number(u.id)})">Upravit</button>
      <button class="btn sm red" onclick="deleteUser(${Number(u.id)})">Smazat</button>
    </td></tr>`).join('');
}
function openUserModalById(id){ openUserModal(usersById[String(id)] || null); }
function openUserModal(u=null){
  document.getElementById('umId').value=u?u.id:'';
  document.getElementById('umUser').value=u?u.username:'';
  document.getElementById('umPass').value='';
  document.getElementById('umRole').value=u?u.role:'user';
  document.getElementById('umActive').checked=u?!!u.active:true;
  document.getElementById('umPassHint').textContent=u?'(nechat prázdné = nezměnit)':'(povinné)';
  document.getElementById('umErr').className='err-msg';
  document.getElementById('userModalTitle').textContent=u?'Upravit uživatele':'Nový uživatel';
  document.getElementById('userModal').classList.add('open');
}
function closeUserModal(){document.getElementById('userModal').classList.remove('open');}
async function saveUser(){
  const err=document.getElementById('umErr');
  const body={id:document.getElementById('umId').value,username:document.getElementById('umUser').value.trim(),password:document.getElementById('umPass').value,role:document.getElementById('umRole').value,active:document.getElementById('umActive').checked};
  const d=await ajax('save_user','POST',body);
  if(d.ok){closeUserModal();loadUsers();}
  else{err.textContent=d.error||'Chyba';err.className='err-msg show';}
}
async function deleteUser(id){
  const u = usersById[String(id)] || {};
  const name = u.username || ('#' + id);
  if(!confirm(`Smazat uživatele "${name}"?`))return;
  const d=await ajax('delete_user','POST',{id});
  if(d.ok)loadUsers();else alert(d.error);
}

// Zavri modal klikem mimo
document.querySelectorAll('.modal-bg').forEach(bg=>bg.addEventListener('click',e=>{if(e.target===bg){bg.classList.remove('open');}}));
// Init
loadUsers();
</script>
</body></html>
