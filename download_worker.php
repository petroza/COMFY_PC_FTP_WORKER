<?php
// ============================================================
//  PZ COMFY VIDEO REMOTE — dynamické stažení lokálního workeru
//  Vytvoří ZIP už s nastavenou URL právě této webové instalace.
// ============================================================
ini_set('display_errors', '0');
ini_set('html_errors', '0');
require_once __DIR__ . '/config.php';
pz_security_headers();
pz_start_secure_session();
if (empty($_SESSION['authenticated'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Nejdřív se přihlas do PZ COMFY webu a potom stáhni worker.\n";
    exit;
}

function pz_scheme(): string {
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $p = strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']);
        if ($p === 'https' || $p === 'http') return $p;
    }
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
}
function pz_base_url(): string {
    $host = $_SERVER['HTTP_HOST'] ?? 'www.petrzavorka.cz';
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/comfy/download_worker.php');
    $dir = rtrim(dirname($script), '/');
    return pz_scheme() . '://' . $host . ($dir === '' || $dir === '.' ? '' : $dir);
}
function pz_dos_time(int $t): array {
    $d = getdate($t);
    $time = (($d['hours'] & 0x1f) << 11) | (($d['minutes'] & 0x3f) << 5) | (intdiv((int)$d['seconds'], 2) & 0x1f);
    $date = ((($d['year'] - 1980) & 0x7f) << 9) | (($d['mon'] & 0x0f) << 5) | ($d['mday'] & 0x1f);
    return [(int)$time, (int)$date];
}
function pz_make_zip(array $files): string {
    $zip = '';
    $central = '';
    $offset = 0;
    [$mtime, $mdate] = pz_dos_time(time());
    foreach ($files as $name => $data) {
        $name = str_replace('\\', '/', ltrim((string)$name, '/'));
        $data = (string)$data;
        $crc = crc32($data);
        $len = strlen($data);
        $nlen = strlen($name);
        $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $mtime, $mdate, $crc, $len, $len, $nlen, 0) . $name . $data;
        $zip .= $local;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $mtime, $mdate, $crc, $len, $len, $nlen, 0, 0, 0, 0, 0, $offset) . $name;
        $offset += strlen($local);
    }
    $zip .= $central;
    $zip .= pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), $offset, 0);
    return $zip;
}

$base = pz_base_url();
$apiUrl = $base . '/api.php';
$workflowUrl = $base . '/api.php?action=default_workflow';
$token = pz_issue_worker_token('Windows worker ' . date('Y-m-d H:i'), (string)($_SESSION['username'] ?? 'admin'));
$workerPyPath = __DIR__ . '/worker_comfy.py';
if (!is_file($workerPyPath)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Chybí worker_comfy.py na serveru.\n";
    exit;
}
$workerPy = file_get_contents($workerPyPath);

$bat = <<<BAT
@echo off
setlocal
chcp 65001 >nul
cd /d "%~dp0_worker"
title PZ COMFY WORKER

set COMFY_BASE=http://127.0.0.1:8000
set PZ_COMFY_EXE=
set PZ_COMFY_START_CMD=%~dp0_worker\START_COMFY.bat
set PZ_COMFY_START_CWD=%~dp0_worker
set PZ_COMFY_FORCE_SAFE_START=1
set PZ_COMFY_API=$apiUrl
set PZ_COMFY_TOKEN=$token
set PZ_COMFY_WORKFLOW_URL=$workflowUrl
set PZ_COMFY_POLL=3
set PZ_COMFY_LONGPOLL=25
set PZ_COMFY_STATS=30
set PZ_WORKER_WATCHDOG_SECONDS=1200
set PZ_WORKER_CONTROL_INTERVAL=30
set PZ_COMFY_RESTART_EXIT_CODE=75
set PZ_COMFY_EXTRA_ARGS=--disable-mmap --disable-dynamic-vram
set PZ_WORKER_ID=%COMPUTERNAME%

echo ================================================
echo   PZ COMFY VIDEO REMOTE - WORKER
echo ================================================
echo Web API:  %PZ_COMFY_API%
echo ComfyUI:  %COMFY_BASE%
echo Start BAT:%PZ_COMFY_START_CMD%
echo Extra:    %PZ_COMFY_EXTRA_ARGS%
echo Worker:   %PZ_WORKER_ID%
echo.
echo Worker pouziva novy bezpecny START_COMFY.bat.
echo Ten nespousti stare ComfyUI.exe, ale primo Comfy Desktop backend main.py.
echo.

where python >nul 2>nul
if errorlevel 1 (
  where py >nul 2>nul
  if errorlevel 1 (
    echo Python neni nainstalovany nebo neni v PATH.
    echo Nainstaluj Python 3.10+ a zaskrtni Add python.exe to PATH.
    echo.
    pause
    exit /b 1
  )
  set PYTHON_CMD=py
) else (
  set PYTHON_CMD=python
)

echo Instaluji / kontroluji Python balicky pro worker...
%PYTHON_CMD% -m pip install --upgrade pip
%PYTHON_CMD% -m pip install requests websocket-client psutil

echo.
echo Startuji spravny ComfyUI backend pro worker...
echo Pokud bezi stary ComfyUI.exe nebo Comfy Desktop, bude ukoncen kvuli portu 8000 / database lock.
call "%PZ_COMFY_START_CMD%"
echo Cekam 10 s na nabeh ComfyUI...
timeout /t 10 /nobreak >nul

echo.
echo Startuji worker. Po par sekundach se ma na webu rozsvitit online.
echo Watchdog: 20 min bez aktivity; pred restartem kontroluje, jestli ComfyUI stale bezi/renderuje.
echo Pro ukonceni zavri toto okno.
echo.
:WORKER_LOOP
%PYTHON_CMD% worker_comfy.py
set EXITCODE=%ERRORLEVEL%
echo.
echo Worker skoncil s kodem %EXITCODE%.
if "%EXITCODE%"=="0" goto END_WORKER
if "%EXITCODE%"=="75" (
  echo Restart workeru vyzadan watchdogem/webem. Spoustim znovu za 3 s...
  timeout /t 3 /nobreak >nul
  goto WORKER_LOOP
)
echo Worker spadl nebo byl ukoncen. Automaticky restart za 10 s kvuli nocni fronte...
timeout /t 10 /nobreak >nul
goto WORKER_LOOP
:END_WORKER
pause
BAT;

$readme = <<<TXT
PZ COMFY VIDEO REMOTE — lokální worker SAFE

Postup:
1) Rozbal ZIP.
2) Zavři Comfy Desktop / staré ComfyUI okno, pokud běží.
3) Spusť START_WORKER.bat. V hlavní složce zůstává jen spouštěč, vše ostatní je ve _worker/.

Co je opraveno:
- START_COMFY.bat už nespouští staré C:\Users\USERNAME\AppData\Local\Programs\ComfyUI\ComfyUI.exe.
- Spouští přímo nový Comfy Desktop backend:
  C:\Users\USERNAME\Documents\ComfyUI\.venv\Scripts\python.exe -s ComfyUI\main.py
- Používá stejný base/user/input/output/model paths jako Comfy Desktop log.
- Přidává --disable-mmap a --disable-dynamic-vram.
- Před startem ukončí procesy, které drží port 8000 nebo comfyui.db.
- Worker už preferuje START_COMFY.bat před EXE.

Web API:
$apiUrl

Když Comfy hlásí staré requirements:
- spusť REPAIR_COMFY_REQUIREMENTS.bat
- pak znovu START_WORKER.bat
TXT;

$startComfyBat = <<<BAT
@echo off
setlocal EnableExtensions
chcp 65001 >nul
title PZ START COMFYUI SAFE BACKEND

REM ============================================================
REM  PZ START COMFYUI SAFE BACKEND
REM  Nespousti stare ComfyUI.exe.
REM  Spousti primo Comfy Desktop backend main.py.
REM ============================================================

set "PYTHON_EXE=C:\Users\USERNAME\Documents\ComfyUI\.venv\Scripts\python.exe"
set "COMFY_ROOT=C:\Users\USERNAME\ComfyUI-Installs\ComfyUI"
set "BASE_DIR=C:\Users\USERNAME\Documents\ComfyUI"
set "USER_DIR=C:\Users\USERNAME\Documents\ComfyUI\user"
set "DB_URL=sqlite:///C:\Users\USERNAME\Documents\ComfyUI\user\comfyui.db"
set "MODEL_PATHS=C:\Users\USERNAME\AppData\Roaming\Comfy Desktop\shared_model_paths.yaml"
set "INPUT_DIR=C:\Users\USERNAME\Documents\ComfyUI\input"
set "OUTPUT_DIR=C:\Users\USERNAME\Documents\ComfyUI\output"
set "LOG=%USERPROFILE%\Desktop\PZ_WORKER_COMFY_START_LOG.txt"

echo ============================================================ > "%LOG%"
echo PZ WORKER COMFY SAFE START >> "%LOG%"
echo %DATE% %TIME% >> "%LOG%"
echo ============================================================ >> "%LOG%"

echo.
echo ============================================================
echo  PZ COMFY VIDEO REMOTE - START COMFYUI SAFE
echo  Backend: ComfyUI\main.py
echo  Port:    8000
echo  Flags:   --disable-mmap --disable-dynamic-vram
echo  Log:     %LOG%
echo ============================================================
echo.

if not exist "%PYTHON_EXE%" (
  echo [CHYBA] Python Comfy Desktop neexistuje:
  echo %PYTHON_EXE%
  echo.
  pause
  exit /b 1
)

if not exist "%COMFY_ROOT%\ComfyUI\main.py" (
  echo [CHYBA] ComfyUI main.py neexistuje:
  echo %COMFY_ROOT%\ComfyUI\main.py
  echo.
  pause
  exit /b 1
)

echo [INFO] Ukoncuji stare Comfy procesy, aby nebyl port 8000 ani database lock...
echo [INFO] Ukoncuji stare Comfy procesy... >> "%LOG%"

taskkill /F /IM "ComfyUI.exe" >nul 2>nul
taskkill /F /IM "Comfy Desktop.exe" >nul 2>nul

powershell -NoProfile -ExecutionPolicy Bypass -Command "Get-CimInstance Win32_Process | Where-Object { ((\$_.Name -like 'python*.exe') -and (\$_.CommandLine -like '*ComfyUI*main.py*')) } | ForEach-Object { try { Stop-Process -Id \$_.ProcessId -Force } catch {} }" >> "%LOG%" 2>&1

for /f "tokens=5" %%P in ('netstat -ano ^| findstr ":8000 " ^| findstr "LISTENING"') do (
  echo [INFO] Port 8000 drzi PID %%P - ukoncuji...
  echo [INFO] Port 8000 drzi PID %%P - ukoncuji... >> "%LOG%"
  taskkill /PID %%P /F >nul 2>nul
)

timeout /t 3 /nobreak >nul

cd /d "%COMFY_ROOT%"

echo [INFO] Startuji spravny backend... >> "%LOG%"
echo [INFO] Python: %PYTHON_EXE% >> "%LOG%"
echo [INFO] Root: %COMFY_ROOT% >> "%LOG%"
echo.
echo Startuji v novem okne. Toto okno se muze zavrit.
echo.

start "PZ ComfyUI backend 8000" cmd /k ""%PYTHON_EXE%" -s ComfyUI\main.py --feature-flag show_signin_button=true --base-directory "%BASE_DIR%" --user-directory "%USER_DIR%" --database-url "%DB_URL%" --port 8000 --enable-manager --extra-model-paths-config "%MODEL_PATHS%" --input-directory "%INPUT_DIR%" --output-directory "%OUTPUT_DIR%" --disable-mmap --disable-dynamic-vram"

exit /b 0
BAT;

$repairBat = <<<BAT
@echo off
setlocal EnableExtensions
chcp 65001 >nul
title PZ REPAIR COMFY REQUIREMENTS

set "PYTHON_EXE=C:\Users\USERNAME\Documents\ComfyUI\.venv\Scripts\python.exe"
set "REQ=C:\Users\USERNAME\ComfyUI-Installs\ComfyUI\ComfyUI\requirements.txt"
set "LOG=%USERPROFILE%\Desktop\PZ_COMFY_REPAIR_REQUIREMENTS_LOG.txt"

echo ============================================================ > "%LOG%"
echo PZ COMFY REPAIR REQUIREMENTS >> "%LOG%"
echo %DATE% %TIME% >> "%LOG%"
echo ============================================================ >> "%LOG%"

echo.
echo Opravuji Comfy requirements podle hlasky ComfyUI...
echo Log: %LOG%
echo.

if not exist "%PYTHON_EXE%" (
  echo [CHYBA] Python neexistuje: %PYTHON_EXE%
  pause
  exit /b 1
)
if not exist "%REQ%" (
  echo [CHYBA] requirements.txt neexistuje: %REQ%
  pause
  exit /b 1
)

"%PYTHON_EXE%" -s -m pip install --upgrade pip >> "%LOG%" 2>&1
"%PYTHON_EXE%" -s -m pip install -r "%REQ%" >> "%LOG%" 2>&1

echo.
echo Hotovo. Pak spusť START_WORKER.bat.
echo Log: %LOG%
pause
BAT;

$files = [
    'START_WORKER.bat' => $bat,
    '_worker/START_COMFY.bat' => $startComfyBat,
    '_worker/REPAIR_COMFY_REQUIREMENTS.bat' => $repairBat,
    '_worker/worker_comfy.py' => $workerPy,
    '_worker/README_START.txt' => $readme,
];
$zip = pz_make_zip($files);
$filename = 'PZ_Comfy_Worker_' . date('Ymd_His') . '.zip';
while (ob_get_level() > 0) { @ob_end_clean(); }
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($zip));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
echo $zip;
