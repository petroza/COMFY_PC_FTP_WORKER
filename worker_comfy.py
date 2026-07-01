#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
PZ COMFY VIDEO REMOTE — lokální worker pro ComfyUI
- běží doma na PC
- ComfyUI musí běžet lokálně: http://127.0.0.1:8188
- web /comfy2/ běží na FORPSI
"""
from __future__ import annotations

import json
import os
import sys
import time
import uuid
import shutil
import socket
import logging
import subprocess
import threading
from pathlib import Path
from typing import Any, Dict, Optional, List
from urllib.parse import urlencode, urlparse

try:
    import requests
except ImportError:
    raise SystemExit("Nainstaluj requests: pip install requests")

try:
    import websocket  # websocket-client
except ImportError:
    websocket = None

# ─── KONFIGURACE ─────────────────────────────────────────────
SCRIPT_DIR    = Path(__file__).parent
TMP_DIR       = SCRIPT_DIR / "tmp_worker"
TMP_DIR.mkdir(exist_ok=True)

API_BASE      = os.environ.get("PZ_COMFY_API", "https://www.petrzavorka.cz/comfy/api.php")
API_TOKEN     = os.environ.get("PZ_COMFY_TOKEN", "")
COMFY_BASE    = os.environ.get("COMFY_BASE", "http://127.0.0.1:8000")
COMFY_START_CMD = os.environ.get("PZ_COMFY_START_CMD", "").strip()
COMFY_START_CWD = os.environ.get("PZ_COMFY_START_CWD", "").strip()
# Pevná cesta k instalaci ComfyUI na Petrovu PC. Dá se přepsat v START_WORKER.bat přes PZ_COMFY_EXE.
COMFY_EXE_PATH = os.environ.get("PZ_COMFY_EXE", r"C:\Users\USERNAME\AppData\Local\Programs\ComfyUI\ComfyUI.exe").strip()
# Dočasná ochrana proti chybě ComfyUI ModelMMAP/get_file_handle u nových buildů.
# Když worker ComfyUI spouští sám, přidá tento parametr. Když ComfyUI běží ručně, je nutné ho restartovat stejně.
COMFY_EXTRA_ARGS = os.environ.get("PZ_COMFY_EXTRA_ARGS", "--disable-mmap --disable-dynamic-vram").strip()
COMFY_FORCE_SAFE_START = str(os.environ.get("PZ_COMFY_FORCE_SAFE_START", "1")).strip().lower() in ("1", "true", "yes", "on")


def _extra_args_list() -> List[str]:
    return [x for x in COMFY_EXTRA_ARGS.split() if x.strip()]


def default_workflow_url() -> str:
    """Odvodí URL workflow JSONu ze stejné webové složky, kde je api.php."""
    base = API_BASE.split("?", 1)[0].rstrip()
    if base.endswith("/api.php"):
        return base + "?action=default_workflow"
    return "https://www.petrzavorka.cz/comfy/api.php?action=default_workflow"


# Hlavní novinka: workflow může být centrálně na webu/FTP.
# Oba počítače potom používají stejný soubor bez řešení lokálních cest.
# Když chceš webové workflow vypnout, nastav PZ_COMFY_WORKFLOW_URL=off
WORKFLOW_URL  = os.environ.get("PZ_COMFY_WORKFLOW_URL", default_workflow_url()).strip()


def resolve_workflow_path() -> str:
    """Lokální fallback. Priorita: env var, Petrova domácí cesta, workflow v balíčku."""
    candidates = [
        os.environ.get("PZ_COMFY_WORKFLOW"),
        r"C:\Users\USERNAME\Documents\ComfyUI\workflows\ltx23_i2v_template.json",
        str(SCRIPT_DIR / "workflows" / "ltx23_i2v_template.json.json"),
        str(SCRIPT_DIR / "workflows" / "ltx23_i2v_template.json"),
    ]
    for c in candidates:
        if c and Path(c).exists():
            return c
    return candidates[0] or candidates[1]

WORKFLOW_PATH = resolve_workflow_path()
WORKER_VERSION = '2026-06-10-v7-polish-diagnostics'
WORKER_ID     = os.environ.get("PZ_WORKER_ID", f"{socket.gethostname()}-{uuid.uuid4().hex[:6]}")
POLL_INTERVAL = float(os.environ.get("PZ_COMFY_POLL", "3"))
LONGPOLL_SECONDS = float(os.environ.get("PZ_COMFY_LONGPOLL", "25"))
STATS_INTERVAL = float(os.environ.get("PZ_COMFY_STATS", "180"))
API_BACKOFF_UNTIL = 0.0
RESTART_EXIT_CODE = int(os.environ.get("PZ_COMFY_RESTART_EXIT_CODE", "75"))
WORKER_WATCHDOG_SECONDS = float(os.environ.get("PZ_WORKER_WATCHDOG_SECONDS", "1200"))
WORKER_CONTROL_INTERVAL = float(os.environ.get("PZ_WORKER_CONTROL_INTERVAL", "30"))
ACTIVE_GPU_CONTROL_INTERVAL = float(os.environ.get("PZ_ACTIVE_GPU_CONTROL_INTERVAL", "10"))
IDLE_WORKER_CONTROL_INTERVAL = float(os.environ.get("PZ_IDLE_WORKER_CONTROL_INTERVAL", "20"))
ACTIVE_JOB_ID: Optional[int] = None
ACTIVE_COMFY_PROMPT_ID: Optional[str] = None
LAST_WORKER_ACTIVITY = time.time()

# Volitelné přesné patchování node ID. Nech prázdné, pokud používáš placeholdery v workflow JSONu.
# Příklad:
# WORKFLOW_PATCH = {
#   "positive_prompt": {"node_id": "12", "path": "inputs.text"},
#   "negative_prompt": {"node_id": "13", "path": "inputs.text"},
#   "image":           {"node_id": "5",  "path": "inputs.image"},
#   "width":           {"node_id": "20", "path": "inputs.width"},
# }
WORKFLOW_PATCH: Dict[str, Dict[str, str]] = {}

HEADERS = {"X-API-Token": API_TOKEN}

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    datefmt="%H:%M:%S",
    handlers=[logging.StreamHandler(sys.stdout)],
)
log = logging.getLogger("pz-comfy-worker")

class Cancelled(Exception):
    pass

class RestartRequested(Exception):
    pass

def mark_worker_activity() -> None:
    global LAST_WORKER_ACTIVITY
    LAST_WORKER_ACTIVITY = time.time()

def request_self_restart(reason: str, job_id: Optional[int] = None, interrupt: bool = False) -> None:
    """Ukončí worker speciálním kódem. START_WORKER.bat ho znovu spustí.

    Důležité: defaultně NEposíláme /interrupt do ComfyUI. Restartujeme jen worker proces,
    aby se omylem nesestřelil dlouhý render, který na lokálním ComfyUI serveru pořád běží.
    Přerušení ComfyUI necháváme jen pro explicitní STOP jobu.
    """
    global API_BACKOFF_UNTIL
    API_BACKOFF_UNTIL = 0.0
    log.warning(f"RESTART WORKER: {reason}")
    if interrupt:
        try:
            interrupt_comfy()
        except Exception:
            pass
    if job_id:
        try:
            update_job(
                int(job_id),
                status="error",
                current_node="worker_restart",
                error="Worker byl restartován: " + reason,
                message="Worker restartován. ComfyUI render nebyl automaticky přerušen.",
            )
        except Exception:
            pass
    os._exit(RESTART_EXIT_CODE)

def comfy_prompt_health(prompt_id: Optional[str]) -> dict:
    """Krátká pojistka pro watchdog: zjistí, jestli lokální ComfyUI server žije
    a jestli náš prompt ještě běží / čeká / už má výsledek.

    Důležité: pro watchdog nesmíme použít tichý fallback {}. Když /queue selže,
    musí se to brát jako offline/nejistý stav, ne jako online_idle. Jinak by mohl
    worker zbytečně restartovat během dlouhého renderu jen kvůli krátkému výpadku HTTP.
    """
    state = {"online": False, "state": "unknown", "prompt_id": prompt_id}
    try:
        q = get_queue(raise_errors=True)
        state["online"] = True
        if prompt_id:
            running, pending, pending_count = prompt_in_queue(prompt_id, q)
            state["pending_count"] = pending_count
            if running:
                state["state"] = "running"
                return state
            if pending:
                state["state"] = "pending"
                return state
            try:
                hist = get_history(prompt_id, allow_empty=True)
                if hist:
                    state["state"] = "finished"
                    return state
            except Exception as hist_error:
                state["history_error"] = str(hist_error)[:200]
        state["state"] = "online_idle"
        return state
    except Exception as e:
        state["online"] = False
        state["state"] = "offline"
        state["error"] = str(e)[:200]
        return state

def _existing_path(s: str) -> Optional[Path]:
    try:
        if not s:
            return None
        p = Path(os.path.expandvars(os.path.expanduser(s.strip().strip('"'))))
        return p if p.exists() else None
    except Exception:
        return None

def _common_comfy_start_scripts() -> List[Path]:
    home = Path.home()
    candidates = [
        SCRIPT_DIR / "START_COMFY.bat",
        SCRIPT_DIR / "start_comfy.bat",
        Path(os.path.expandvars(COMFY_EXE_PATH)) if COMFY_EXE_PATH else Path("__disabled_old_comfy_exe__"),
        Path(os.environ.get("LOCALAPPDATA", "")) / "Programs" / "ComfyUI" / "ComfyUI.exe",
        Path(r"C:\Users\USERNAME\AppData\Local\Programs\ComfyUI\ComfyUI.exe"),
        home / "Documents" / "ComfyUI" / "run_nvidia_gpu.bat",
        home / "Documents" / "ComfyUI" / "run.bat",
        home / "Desktop" / "ComfyUI" / "run_nvidia_gpu.bat",
        home / "Desktop" / "ComfyUI" / "run.bat",
        home / "Downloads" / "ComfyUI" / "run_nvidia_gpu.bat",
        home / "Downloads" / "ComfyUI" / "run.bat",
        home / "Downloads" / "ComfyUI_windows_portable" / "run_nvidia_gpu.bat",
        home / "Desktop" / "ComfyUI_windows_portable" / "run_nvidia_gpu.bat",
        Path(r"C:\ComfyUI") / "run_nvidia_gpu.bat",
        Path(r"C:\ComfyUI") / "run.bat",
        Path(r"C:\ComfyUI_windows_portable") / "run_nvidia_gpu.bat",
    ]
    return [p for p in candidates if p.exists()]

def start_comfy_from_worker(reason: str = "start z webu") -> dict:
    """Spustí lokální ComfyUI z workeru.

    Nová priorita: nejdřív START_COMFY.bat z worker ZIPu, ne staré ComfyUI.exe.
    Důvod: Comfy Desktop EXE nepředává spolehlivě --disable-mmap/--disable-dynamic-vram
    a může spustit jiný backend, než který používá nová Comfy Desktop instalace.
    """
    try:
        online_now = bool(comfy_online())
    except Exception:
        online_now = False

    try:
        creationflags = getattr(subprocess, "CREATE_NEW_CONSOLE", 0)

        # 1) Bezpečný start z worker ZIPu má absolutní prioritu.
        #    START_COMFY.bat sám ukončí starý proces na portu 8000 a spustí správný backend main.py.
        if COMFY_START_CMD:
            if online_now and not COMFY_FORCE_SAFE_START:
                log.info("Start ComfyUI: ComfyUI už běží.")
                return {"success": True, "already_online": True, "message": "ComfyUI už běží"}
            if online_now and COMFY_FORCE_SAFE_START:
                log.warning("Start ComfyUI: ComfyUI už běží, ale FORCE_SAFE_START=1, proto restartuji přes START_COMFY.bat")
            cwd = COMFY_START_CWD or str(SCRIPT_DIR)
            log.warning(f"Start ComfyUI: spouštím bezpečný PZ_COMFY_START_CMD ({COMFY_START_CMD})")
            subprocess.Popen(COMFY_START_CMD, shell=True, cwd=cwd, creationflags=creationflags)
            return {"success": True, "started": True, "cmd": "PZ_COMFY_START_CMD", "force_safe": COMFY_FORCE_SAFE_START}

        # 2) Když není START_COMFY.bat nastavený, a ComfyUI už běží, necháme ho být.
        if online_now:
            log.info("Start ComfyUI: ComfyUI už běží.")
            return {"success": True, "already_online": True, "message": "ComfyUI už běží"}

        # 3) Nouzový fallback na běžné portable instalace. EXE až úplně nakonec.
        scripts = _common_comfy_start_scripts()
        if scripts:
            script = scripts[0]
            log.warning(f"Start ComfyUI: fallback spouštím {script}")
            extra = _extra_args_list()
            if os.name == "nt" and script.suffix.lower() == ".exe":
                subprocess.Popen([str(script)] + extra, cwd=str(script.parent), creationflags=creationflags)
            elif os.name == "nt":
                subprocess.Popen(["cmd.exe", "/c", str(script)] + extra, cwd=str(script.parent), creationflags=creationflags)
            else:
                subprocess.Popen([str(script)] + extra, cwd=str(script.parent))
            return {"success": True, "started": True, "script": str(script), "extra_args": COMFY_EXTRA_ARGS}

        msg = "Nenalezen START_COMFY.bat ani běžná instalace ComfyUI. Stáhni nový worker nebo zkontroluj PZ_COMFY_START_CMD."
        log.error("Start ComfyUI: " + msg)
        return {"success": False, "error": msg}
    except Exception as e:
        log.error(f"Start ComfyUI selhal: {e}")
        return {"success": False, "error": str(e)[:500]}

def handle_worker_command(cmd: dict) -> None:
    action = str(cmd.get("command") or "").strip().lower()
    if action == "start_comfy":
        result = start_comfy_from_worker(str(cmd.get("reason") or "start z webu"))
        if result.get("already_online"):
            report_comfy_state(True, "ready", "ComfyUI už běží")
        elif result.get("success"):
            wait_for_comfy_ready_after_start(150)
        else:
            report_comfy_state(False, "start_failed", str(result.get("error") or "Start ComfyUI selhal"))
    elif action:
        log.warning(f"Neznámý příkaz z webu: {action}")

def worker_control_loop() -> None:
    """Vedlejší watchdog: přijímá ruční restart z webu, hlídá zaseknutí a posílá live GPU metriky."""
    global ACTIVE_JOB_ID, LAST_WORKER_ACTIVITY
    cached_health: dict = {"online": False, "state": "unknown"}
    while True:
        # Když se renderuje, posílej heartbeat/GPU častěji. V idle režimu stále kontroluj ComfyUI dost často,
        # aby zelené „ComfyUI ready“ z webu zmizelo krátce po ručním vypnutí ComfyUI na PC.
        sleep_s = ACTIVE_GPU_CONTROL_INTERVAL if ACTIVE_JOB_ID else IDLE_WORKER_CONTROL_INTERVAL
        sleep_s = max(8.0 if ACTIVE_JOB_ID else 20.0, float(sleep_s or WORKER_CONTROL_INTERVAL))
        time.sleep(sleep_s)
        try:
            active = ACTIVE_JOB_ID
            prompt_id = ACTIVE_COMFY_PROMPT_ID
            idle_s = time.time() - LAST_WORKER_ACTIVITY

            # PZ FIX: ComfyUI stav nesmí být dlouho cachovaný.
            # Lokální dotaz na 127.0.0.1 nezatěžuje FORPSI, ale udrží web pravdivý.
            if active and prompt_id:
                cached_health = comfy_prompt_health(prompt_id)
            else:
                online_now = comfy_online()
                cached_health = {"online": online_now, "state": "ready" if online_now else "offline"}

            if active and idle_s > WORKER_WATCHDOG_SECONDS:
                health = comfy_prompt_health(prompt_id)
                cached_health = health
                last_health_check = time.time()
                if health.get("state") in ("running", "pending"):
                    log.warning(
                        "Watchdog: worker je dlouho bez web updatu, ale ComfyUI prompt pořád běží/čeká "
                        f"({health.get('state')}, prompt_id={prompt_id}). Restart odkládám."
                    )
                    LAST_WORKER_ACTIVITY = time.time()
                elif health.get("online") is False:
                    # Když si nejsme jistí stavem ComfyUI, restartujeme jen worker, ne ComfyUI render.
                    request_self_restart(
                        f"lokální watchdog: {int(idle_s)} s bez aktivity; ComfyUI={health.get('state')}",
                        active,
                        interrupt=False,
                    )
                else:
                    request_self_restart(
                        f"lokální watchdog: {int(idle_s)} s bez aktivity; ComfyUI={health.get('state')}",
                        active,
                        interrupt=False,
                    )
            res = api(
                "worker_control",
                "POST",
                json_body={
                    "worker_id": WORKER_ID,
                    "active_job_id": active,
                    "active_prompt_id": prompt_id,
                    "seconds_since_activity": int(idle_s),
                    "comfy": {"online": bool(cached_health.get("online")), "base": COMFY_BASE, "state": cached_health.get("state"), "prompt_id": prompt_id},
                    # GPU/RAM metriky posíláme v existujícím control heartbeat requestu, bez dalšího zatížení FORPSI.
                    "gpu": get_nvidia_stats(active=bool(active)),
                    "ram": get_ram_stats(),
                },
                timeout=15,
            )
            if res.get("restart"):
                req = res.get("request") or {}
                request_self_restart(str(req.get("reason") or "restart z webu"), active, interrupt=False)
            cmd = res.get("command") or {}
            if isinstance(cmd, dict) and cmd.get("command"):
                handle_worker_command(cmd)
        except SystemExit:
            raise
        except Exception as e:
            log.debug(f"worker_control/watchdog chyba: {e}")

# ─── SERVER API ──────────────────────────────────────────────
def api(action: str, method: str = "GET", json_body: Optional[dict] = None,
        files: Optional[dict] = None, data: Optional[dict] = None, timeout: int = 30) -> dict:
    """Volání webového API s ochranou proti FORPSI 429.
    Když hosting vrátí HTML stránku místo JSONu, worker se nezacyklí a zpomalí.
    """
    global API_BACKOFF_UNTIL
    url = f"{API_BASE}?action={action}"

    wait = API_BACKOFF_UNTIL - time.time()
    if wait > 0:
        log.warning(f"Forpsi/backoff: pauza {int(wait)} s před dalším API požadavkem")
        time.sleep(wait)

    if method == "GET":
        r = requests.get(url, headers=HEADERS, timeout=timeout)
    else:
        if files:
            r = requests.post(url, headers=HEADERS, files=files, data=data or {}, timeout=timeout)
        else:
            r = requests.post(url, headers={**HEADERS, "Content-Type": "application/json"}, json=json_body or {}, timeout=timeout)

    if r.status_code == 429:
        retry = r.headers.get("Retry-After")
        try:
            retry_s = int(float(retry)) if retry else 180
        except Exception:
            retry_s = 180
        retry_s = max(120, min(600, retry_s))
        API_BACKOFF_UNTIL = max(API_BACKOFF_UNTIL, time.time() + retry_s)
        log.warning(f"FORPSI 429: moc požadavků. Pauza {retry_s} s. Odpověď: {r.text[:160]}")
        return {"success": False, "rate_limited": True, "retry_after": retry_s, "error": "FORPSI 429"}

    if r.status_code >= 400:
        r.raise_for_status()

    try:
        return r.json()
    except Exception:
        txt = (r.text or "")[:200]
        if txt.lstrip().startswith("<"):
            API_BACKOFF_UNTIL = max(API_BACKOFF_UNTIL, time.time() + 180)
            raise RuntimeError(f"API vrátilo HTML místo JSONu, pravděpodobně FORPSI ochrana: {txt}")
        raise

_last_job_update: Dict[int, Dict[str, Any]] = {}

def update_job(job_id: int, status: Optional[str] = None, progress: Optional[int] = None,
               current_node: Optional[str] = None, message: Optional[str] = None,
               error: Optional[str] = None, comfy_prompt_id: Optional[str] = None,
               data: Optional[dict] = None) -> bool:
    body: Dict[str, Any] = {"id": job_id, "worker_id": WORKER_ID}
    if status is not None: body["status"] = status
    if progress is not None: body["progress"] = int(max(0, min(100, progress)))
    if current_node is not None: body["current_node"] = str(current_node)
    if message is not None: body["message"] = message
    if error is not None: body["error"] = error
    if comfy_prompt_id is not None: body["comfy_prompt_id"] = comfy_prompt_id
    if data is not None: body["data"] = data

    # Prázdný update už neposíláme na web. Dřív se používal jako kontrola cancelu,
    # ale zbytečně generoval požadavky a mohl spouštět Forpsi 429.
    if set(body.keys()) == {"id", "worker_id"}:
        return False

    now = time.time()
    last = _last_job_update.get(job_id, {})
    critical = bool(error or comfy_prompt_id or status in ("done", "error", "cancelled", "processing", "uploading", "downloading"))
    status_changed = status is not None and status != last.get("status")
    node_changed = current_node is not None and current_node != last.get("current_node")
    prev_progress = last.get("progress")
    progress_changed = progress is not None and (prev_progress is None or abs(int(progress) - int(prev_progress)) >= 10)

    # Běžné progress eventy posíláme hodně úsporně kvůli FORPSI DoS ochraně.
    if not critical and not status_changed:
        if now - float(last.get("time", 0)) < 20:
            return False
        if progress is not None and not progress_changed and not node_changed:
            return False

    try:
        res = api("update_job", "POST", json_body=body, timeout=20)
        mark_worker_activity()
        _last_job_update[job_id] = {
            "time": now,
            "status": status if status is not None else last.get("status"),
            "progress": progress if progress is not None else last.get("progress"),
            "current_node": current_node if current_node is not None else last.get("current_node"),
        }
        return bool(res.get("cancelled"))
    except requests.HTTPError as e:
        if e.response is not None and e.response.status_code == 429:
            log.warning("Forpsi 429: worker zpomaluje požadavky na 60 s")
            time.sleep(60)
        else:
            log.warning(f"update_job selhal: {e}")
        return False
    except Exception as e:
        log.warning(f"update_job selhal: {e}")
        return False

def claim_job() -> Optional[dict]:
    try:
        res = api("worker_claim", "POST", json_body={"worker_id": WORKER_ID, "wait_seconds": int(max(0, min(25, LONGPOLL_SECONDS)))}, timeout=int(max(20, LONGPOLL_SECONDS + 10)))
        if res.get("rate_limited"):
            time.sleep(float(res.get("retry_after") or 180))
            return None
        if res.get("success"):
            mark_worker_activity()
            return res.get("job")
    except requests.HTTPError as e:
        log.error(f"API HTTP chyba: {e.response.status_code} {e.response.text[:200]}")
    except Exception as e:
        log.warning(f"Nelze načíst job: {e}")
    return None

def upload_result(job_id: int, path: Path) -> dict:
    with path.open("rb") as f:
        res = api("upload_result", "POST", files={"video": (path.name, f, "application/octet-stream")}, data={"id": str(job_id)}, timeout=600)
    if not res.get("success"):
        raise RuntimeError(res.get("error") or "Upload výsledku na web selhal")
    return res

# ─── STATS ──────────────────────────────────────────────────
def _parse_nvidia_line(line: str) -> Optional[dict]:
    parts = [p.strip() for p in (line or "").split(",")]
    if len(parts) < 5:
        return None
    try:
        return {
            "name": parts[0],
            "mem_used_mb": int(float(parts[1])),
            "mem_total_mb": int(float(parts[2])),
            "util_pct": int(float(parts[3])),
            "temp_c": int(float(parts[4])),
            "sampled_at": time.strftime("%Y-%m-%d %H:%M:%S"),
        }
    except Exception:
        return None


def _read_nvidia_smi_once() -> Optional[dict]:
    """Jedno čtení NVIDIA metrik. Při více GPU vybere tu opravdu vytíženou, ne první řádek."""
    try:
        out = subprocess.run([
            "nvidia-smi",
            "--query-gpu=name,memory.used,memory.total,utilization.gpu,temperature.gpu",
            "--format=csv,noheader,nounits"
        ], capture_output=True, text=True, timeout=6)
        if out.returncode != 0 or not out.stdout.strip():
            return None
        rows = []
        for line in out.stdout.strip().splitlines():
            row = _parse_nvidia_line(line)
            if row:
                rows.append(row)
        if not rows:
            return None
        # Primárně nejvyšší GPU util, potom VRAM. Tím se nesplete karta, pokud je v PC víc NVIDIA GPU.
        return sorted(rows, key=lambda r: (int(r.get("util_pct") or 0), int(r.get("mem_used_mb") or 0)), reverse=True)[0]
    except Exception:
        return None


def get_nvidia_stats(active: bool = False) -> Optional[dict]:
    """
    Live metrika pro web.
    utilization.gpu je okamžitý vzorek a při renderu může pulzovat, proto při aktivním jobu bereme víc
    krátkých vzorků a posíláme maximum. Web tak nebude ukazovat 0–3 %, když ComfyUI zrovna běží naplno.
    """
    samples = []
    sample_count = 8 if active else 3
    delay = 0.28 if active else 0.35
    for i in range(sample_count):
        s = _read_nvidia_smi_once()
        if s:
            samples.append(s)
        if i < sample_count - 1:
            time.sleep(delay)
    if not samples:
        return None
    # Pro název/total VRAM vezmeme vzorek s nejvyšší utilitou, pro hodnoty maximum za okno.
    base = sorted(samples, key=lambda r: (int(r.get("util_pct") or 0), int(r.get("mem_used_mb") or 0)), reverse=True)[0]
    util = max(int(s.get("util_pct") or 0) for s in samples)
    mem_used = max(int(s.get("mem_used_mb") or 0) for s in samples)
    mem_total = max(int(s.get("mem_total_mb") or 0) for s in samples)
    temp = max(int(s.get("temp_c") or 0) for s in samples)
    return {
        "name": base.get("name") or "GPU",
        "mem_used_mb": mem_used,
        "mem_total_mb": mem_total,
        "util_pct": util,
        "temp_c": temp,
        "sample_count": len(samples),
        "sample_window_s": round((sample_count - 1) * delay, 2),
        "active_job": bool(active),
        "sampled_at": base.get("sampled_at") or time.strftime("%Y-%m-%d %H:%M:%S"),
    }

def get_ram_stats() -> Optional[dict]:
    try:
        import psutil
        m = psutil.virtual_memory()
        return {"used_mb": int(m.used/1024/1024), "total_mb": int(m.total/1024/1024), "percent": float(m.percent)}
    except Exception:
        return None

def comfy_online() -> bool:
    try:
        r = requests.get(f"{COMFY_BASE}/system_stats", timeout=5)
        return r.status_code == 200
    except Exception:
        return False

def sync_stats(extra: Optional[dict] = None) -> None:
    online_now = comfy_online()
    data = {
        "gpu": get_nvidia_stats(),
        "ram": get_ram_stats(),
        "comfy": {"online": online_now, "base": COMFY_BASE, "state": "ready" if online_now else "offline"},
        "worker": {
            "id": WORKER_ID,
            "version": WORKER_VERSION,
            "workflow_url": WORKFLOW_URL if WORKFLOW_URL.lower() not in ("", "0", "false", "off", "no") else None,
            "workflow_fallback": WORKFLOW_PATH,
            "time": time.strftime("%Y-%m-%d %H:%M:%S"),
        },
    }
    if extra:
        # Zpětná kompatibilita: staré volání sync_stats({"last_job": id}) pořád patří do worker sekce.
        for key in ("gpu", "ram", "disk"):
            if key in extra:
                data[key] = extra[key]
        if isinstance(extra.get("comfy"), dict):
            data["comfy"].update(extra["comfy"])
        if isinstance(extra.get("worker"), dict):
            data["worker"].update(extra["worker"])
        worker_extra = {k: v for k, v in extra.items() if k not in ("gpu", "ram", "disk", "comfy", "worker")}
        if worker_extra:
            data["worker"].update(worker_extra)
    try:
        api("sync_stats", "POST", json_body=data, timeout=15)
        mark_worker_activity()
    except Exception as e:
        log.debug(f"sync_stats chyba: {e}")

def report_comfy_state(online: bool, state: str, message: str = "") -> None:
    payload = {"online": bool(online), "base": COMFY_BASE, "state": state}
    if message:
        payload["message"] = message[:300]
    sync_stats({"comfy": payload})

def wait_for_comfy_ready_after_start(timeout_s: int = 150) -> bool:
    report_comfy_state(False, "starting", "Čekám na nastartování ComfyUI")
    deadline = time.time() + max(5, timeout_s)
    while time.time() < deadline:
        if comfy_online():
            log.info("ComfyUI je ready.")
            report_comfy_state(True, "ready", "ComfyUI připraveno")
            return True
        time.sleep(5)
    log.warning("ComfyUI po start požadavku nenaběhlo v limitu.")
    report_comfy_state(False, "start_timeout", "ComfyUI nenaběhlo v limitu")
    return False

# ─── COMFY HELPERS ──────────────────────────────────────────
def download_file(url: str, dst: Path) -> Path:
    with requests.get(url, headers=HEADERS, stream=True, timeout=120) as r:
        r.raise_for_status()
        with dst.open("wb") as f:
            for chunk in r.iter_content(chunk_size=1024*1024):
                if chunk:
                    f.write(chunk)
    return dst

IMAGE_SUFFIXES = {".png", ".jpg", ".jpeg", ".webp", ".bmp", ".gif"}

def safe_image_suffix_from_url(url: str) -> str:
    # api.php?action=job_file má suffix .php, ale do ComfyUI se musí nahrát skutečný obrázek.
    suffix = Path(urlparse(str(url)).path).suffix.lower()
    return suffix if suffix in IMAGE_SUFFIXES else ".png"

def sniff_image_suffix(path: Path) -> str:
    try:
        head = path.read_bytes()[:32]
        if head.startswith(b"\x89PNG\r\n\x1a\n"):
            return ".png"
        if head.startswith(b"\xff\xd8\xff"):
            return ".jpg"
        if head[:4] == b"RIFF" and head[8:12] == b"WEBP":
            return ".webp"
        if head.startswith(b"GIF87a") or head.startswith(b"GIF89a"):
            return ".gif"
        if head.startswith(b"BM"):
            return ".bmp"
    except Exception:
        pass
    return path.suffix.lower() if path.suffix.lower() in IMAGE_SUFFIXES else ".png"

def normalize_local_image_suffix(path: Path) -> Path:
    real_suffix = sniff_image_suffix(path)
    if path.suffix.lower() == real_suffix and path.suffix.lower() in IMAGE_SUFFIXES:
        return path
    new_path = path.with_suffix(real_suffix)
    if new_path.exists():
        new_path = path.with_name(path.stem + "_img" + real_suffix)
    try:
        path.rename(new_path)
        log.info(f"Input image suffix fix: {path.name} -> {new_path.name}")
        return new_path
    except Exception as e:
        log.warning(f"Input image suffix fix se nepovedl ({path.name}): {e}")
        return path

def upload_image_to_comfy(path: Path) -> str:
    with path.open("rb") as f:
        files = {"image": (path.name, f, "application/octet-stream")}
        data = {"overwrite": "true", "type": "input"}
        r = requests.post(f"{COMFY_BASE}/upload/image", files=files, data=data, timeout=120)
        r.raise_for_status()
        j = r.json()
        return j.get("name") or path.name

_COMFY_OBJECT_INFO_CACHE: Optional[dict] = None

def get_comfy_object_info() -> dict:
    global _COMFY_OBJECT_INFO_CACHE
    if _COMFY_OBJECT_INFO_CACHE is not None:
        return _COMFY_OBJECT_INFO_CACHE
    try:
        r = requests.get(f"{COMFY_BASE}/object_info", timeout=20)
        r.raise_for_status()
        data = r.json()
        _COMFY_OBJECT_INFO_CACHE = data if isinstance(data, dict) else {}
    except Exception as e:
        log.warning(f"Comfy object_info nejde načíst, model autofix bude omezený: {e}")
        _COMFY_OBJECT_INFO_CACHE = {}
    return _COMFY_OBJECT_INFO_CACHE

def get_comfy_combo_options(class_type: str, input_name: str) -> List[str]:
    try:
        info = get_comfy_object_info().get(class_type) or {}
        required = ((info.get("input") or {}).get("required") or {})
        optional = ((info.get("input") or {}).get("optional") or {})
        cfg = required.get(input_name, optional.get(input_name))
        if isinstance(cfg, (list, tuple)) and cfg:
            first = cfg[0]
            if isinstance(first, list):
                return [str(x) for x in first]
        if isinstance(cfg, dict) and isinstance(cfg.get("options"), list):
            return [str(x) for x in cfg.get("options")]
    except Exception:
        pass
    return []

def _wildcard_match(name: str, pattern: str) -> bool:
    import fnmatch
    return fnmatch.fnmatchcase(name.lower(), pattern.lower())

def resolve_comfy_combo_value(class_type: str, input_name: str, current: str,
                              exact_preferred: List[str], wildcard_preferred: List[str],
                              throw_if_no_match: bool = False) -> str:
    options = get_comfy_combo_options(class_type, input_name)
    if not options:
        return current
    for o in options:
        if o.lower() == str(current).lower():
            return o
    for pref in exact_preferred:
        for o in options:
            if o.lower() == str(pref).lower():
                return o
    for pat in wildcard_preferred:
        for o in options:
            if _wildcard_match(o, pat):
                return o
    if throw_if_no_match:
        raise RuntimeError(
            f"Nenalezen vhodný model pro {class_type}.{input_name}. "
            f"Ve workflow je {current!r}, ale Comfy ho nemá. Dostupné: {', '.join(options[:30])}"
        )
    return current

def repair_ltx_model_names(wf: dict) -> None:
    """Autofix názvů LTX modelů podle toho, co ComfyUI opravdu nabízí."""
    preferred_ckpt = [
        "ltx-2.3-22b-dev-fp8.safetensors",
        "ltx-2-19b-distilled.safetensors",
        "ltx-2-19b-dev-fp8.safetensors",
    ]
    preferred_ckpt_wild = [
        "*ltx-2.3*dev*fp8*.safetensors",
        "*ltx-2.3*.safetensors",
        "*ltx-2*dev*fp8*.safetensors",
        "*ltx-2*distilled*.safetensors",
        "*ltx*.safetensors",
    ]
    preferred_text = ["gemma_3_12B_it_fp4_mixed.safetensors"]
    preferred_text_wild = ["*gemma*12b*fp4*.safetensors", "*gemma*.safetensors", "*.safetensors"]
    for node_id, node in list(wf.items()):
        if not isinstance(node, dict) or not isinstance(node.get("inputs"), dict):
            continue
        inputs = node["inputs"]
        cls = str(node.get("class_type") or "")
        if "ckpt_name" in inputs and isinstance(inputs.get("ckpt_name"), str):
            cur = str(inputs.get("ckpt_name") or "")
            is_ltx = cls in {"CheckpointLoaderSimple", "LTXVAudioVAELoader", "LTXAVTextEncoderLoader"} and cur.lower().startswith("ltx-")
            if is_ltx:
                new = resolve_comfy_combo_value(cls, "ckpt_name", cur, preferred_ckpt, preferred_ckpt_wild, True)
                if new != cur:
                    log.info(f"Model autofix: node {node_id} {cls} ckpt_name {cur} -> {new}")
                    inputs["ckpt_name"] = new
        if "text_encoder" in inputs and isinstance(inputs.get("text_encoder"), str):
            cur = str(inputs.get("text_encoder") or "")
            new = resolve_comfy_combo_value(cls, "text_encoder", cur, preferred_text, preferred_text_wild, False)
            if new != cur:
                log.info(f"Text encoder autofix: node {node_id} {cls} text_encoder {cur} -> {new}")
                inputs["text_encoder"] = new

def set_node_input(wf: dict, node_id: str, input_name: str, value: Any) -> bool:
    node = _get_node(wf, node_id)
    if isinstance(node, dict) and isinstance(node.get("inputs"), dict):
        node["inputs"][input_name] = value
        return True
    return False

def get_node_input(wf: dict, node_id: str, input_name: str) -> Any:
    node = _get_node(wf, node_id)
    if isinstance(node, dict) and isinstance(node.get("inputs"), dict):
        return node["inputs"].get(input_name)
    return None

def restore_ltx_official_image_hold(wf: dict) -> None:
    """Vrátí oficiální image-hold větev LTX 2.3 i2v, která drží vloženou fotku i za prvním framem.
    Nejdřív použije přesné ID ze staré funkční šablony. Když se workflow znovu exportuje
    a ID se změní, použije opatrný fallback podle class_type LTXVImgToVideoInplace.
    """
    fixed_ok = set_node_input(wf, "320:288", "strength", 1.0) and set_node_input(wf, "320:296", "strength", 0.85)
    if fixed_ok:
        if _get_node(wf, "320:302") is not None:
            set_node_input(wf, "320:288", "bypass", ["320:302", 0])
            set_node_input(wf, "320:296", "bypass", ["320:302", 0])
        else:
            set_node_input(wf, "320:288", "bypass", False)
            set_node_input(wf, "320:296", "bypass", False)
        return

    ltx_nodes = []
    for nid, node in wf.items():
        if isinstance(node, dict) and str(node.get("class_type") or "") == "LTXVImgToVideoInplace" and isinstance(node.get("inputs"), dict):
            ltx_nodes.append((str(nid), node))
    if len(ltx_nodes) >= 2:
        ltx_nodes.sort(key=lambda x: x[0])
        ltx_nodes[0][1]["inputs"]["strength"] = 1.0
        ltx_nodes[1][1]["inputs"]["strength"] = 0.85
        for _, node in ltx_nodes[:2]:
            if node["inputs"].get("bypass") is True:
                node["inputs"]["bypass"] = False
        log.info("LTX image-hold fallback: nastaveny první dva LTXVImgToVideoInplace nody na strength 1.0 / 0.85")

def assert_ltx_frame_hold_protected(wf: dict, comfy_image_name: str) -> None:
    if not (_get_node(wf, "320:288") and _get_node(wf, "320:296")):
        return
    if comfy_image_name and not workflow_contains_value(wf, comfy_image_name):
        raise RuntimeError(f"LTX ochrana: vstupní obrázek není ve workflow. Comfy image={comfy_image_name}")
    s1 = get_node_input(wf, "320:288", "strength")
    s2 = get_node_input(wf, "320:296", "strength")
    try:
        ok_strength = (float(s1) == 1.0 and abs(float(s2) - 0.85) < 0.001)
    except Exception:
        ok_strength = False
    if not ok_strength:
        raise RuntimeError(f"LTX ochrana: image-hold strength byl přepsán. 320:288={s1} 320:296={s2}")
    if _get_node(wf, "320:302") is not None:
        b1 = get_node_input(wf, "320:288", "bypass")
        b2 = get_node_input(wf, "320:296", "bypass")
        ok = isinstance(b1, list) and isinstance(b2, list) and len(b1) >= 2 and len(b2) >= 2 and b1[0] == "320:302" and int(b1[1]) == 0 and b2[0] == "320:302" and int(b2[1]) == 0
        if not ok:
            raise RuntimeError("LTX ochrana: image-hold bypass není napojen na 320:302 jako ve staré funkční verzi.")


def _node_title(node: dict) -> str:
    meta = node.get("_meta") if isinstance(node.get("_meta"), dict) else {}
    return str(meta.get("title") or node.get("title") or "")

def patch_ltx_prompt_enhance(wf: dict, enable: bool, tokens: int, seed: int = 0) -> List[str]:
    """Dynamicky patchuje LTX Prompt Enhance bez pevného node ID.
    Funguje pro 1 PICT šablonu. U 2 PICT/FLF2V, kde node není, se tiše přeskočí.
    """
    patched: List[str] = []
    tokens = max(64, min(512, int(tokens or 128)))
    enable = bool(enable)
    for node_id, node in wf.items():
        if not isinstance(node, dict) or not isinstance(node.get("inputs"), dict):
            continue
        cls = str(node.get("class_type") or "")
        title = _node_title(node).lower()
        inputs = node["inputs"]
        label = f"{node_id}:{cls}"
        if cls == "TextGenerateLTX2Prompt":
            old = inputs.get("max_length")
            inputs["max_length"] = tokens
            patched.append(f"prompt tokens {label}.max_length: {old} -> {tokens}")
            if seed and "sampling_mode.seed" in inputs and isinstance(inputs.get("sampling_mode.seed"), (int, float)):
                old_seed = inputs.get("sampling_mode.seed")
                inputs["sampling_mode.seed"] = int(seed)
                patched.append(f"prompt enhance seed {label}.sampling_mode.seed: {old_seed} -> {seed}")
        if cls == "PrimitiveBoolean" and "value" in inputs:
            title_hit = (
                "prompt enhance" in title or
                "enable prompt enhance" in title or
                ("enhance" in title and "prompt" in title)
            )
            if title_hit:
                old = inputs.get("value")
                inputs["value"] = enable
                patched.append(f"prompt enhance {label}.value: {old} -> {enable}")
    return patched

def set_flf2v_images(wf: dict, first_image: str, last_image: str) -> None:
    # Nový LTX 2.3 first-last-frame workflow z lokální verze: 31 = první frejm, 39 = poslední frejm.
    if _get_node(wf, "31") and _get_node(wf, "39"):
        set_node_input(wf, "31", "image", first_image)
        set_node_input(wf, "39", "image", last_image)
        return
    # Fallback pro podobné workflowy: první dva LoadImage nody.
    load_nodes = []
    for node_id, node in wf.items():
        if isinstance(node, dict) and str(node.get("class_type") or "").lower() == "loadimage":
            load_nodes.append(str(node_id))
    if len(load_nodes) >= 2:
        set_node_input(wf, load_nodes[0], "image", first_image)
        set_node_input(wf, load_nodes[1], "image", last_image)
        return
    raise RuntimeError("2 PICT workflow nemá dva LoadImage nody pro první a poslední frejm.")

def node_stage_label(wf: Optional[dict], node_id: str) -> str:
    node = _get_node(wf or {}, node_id) if wf else None
    cls = str((node or {}).get("class_type") or "")
    title = ""
    try:
        meta = (node or {}).get("_meta") or {}
        title = str(meta.get("title") or "")
    except Exception:
        title = ""
    c = cls
    if not node_id:
        return "Čekám na Comfy"
    if c == "SaveVideo": return "Ukládám video"
    if c == "CreateVideo": return "Skládám video"
    if re_search(c, "VAEDecode|AudioVAEDecode|SeparateAVLatent|CropGuides|LatentUpsampler"): return "Dekóduji výstup"
    if re_search(c, "SamplerCustomAdvanced|KSampler|SamplerEuler|ManualSigmas|RandomNoise|CFGGuider"): return "Generuji snímky"
    if re_search(c, "ImgToVideo|AddGuide|EmptyLatent|EmptyLTXV|ConcatAVLatent|LTXVConditioning"): return "Připravuji latent"
    if re_search(c, "CLIPTextEncode|TextGenerate|PrimitiveString|ComfySwitch"): return "Kóduji prompt"
    if re_search(c, "Preprocess|Resize|GetImageSize|LoadImage"): return "Zpracovávám obrázek"
    if re_search(c, "Checkpoint|TextEncoder|AudioVAELoader|LoraLoader|ModelLoader"): return "Načítám model"
    return title or cls or str(node_id)

def re_search(text: str, pattern: str) -> bool:
    import re
    return re.search(pattern, text or "") is not None

def sanitize_workflow(wf: Any, source: str) -> dict:
    if isinstance(wf, dict) and wf.get("_template_marker") == "REPLACE_WITH_EXPORTED_COMFYUI_API_WORKFLOW":
        raise RuntimeError(
            "Workflow je jen instalační šablona: " + source + ". "
            "Na FTP nahraj reálný ComfyUI workflow export ve formátu API jako workflows/ltx23_i2v_template.json."
        )
    # Odstraň meta klíče, ComfyUI chce jen API workflow nodes.
    if isinstance(wf, dict):
        wf = dict(wf)
        for k in list(wf.keys()):
            if str(k).startswith("_"):
                wf.pop(k, None)
    if not isinstance(wf, dict) or not wf:
        raise ValueError("Workflow JSON je prázdný nebo neplatný: " + source)
    return wf


def load_workflow_from_web() -> Optional[dict]:
    """Stáhne workflow z webu/FTP, aby oba stroje používaly stejný JSON."""
    if WORKFLOW_URL.lower() in ("", "0", "false", "off", "no"):
        return None
    try:
        r = requests.get(WORKFLOW_URL, timeout=30, headers={**HEADERS, "Cache-Control": "no-cache"})
        r.raise_for_status()
        wf = r.json()
        cache_path = TMP_DIR / "ltx23_i2v_template.web-cache.json"
        cache_path.write_text(json.dumps(wf, ensure_ascii=False, indent=2), encoding="utf-8")
        log.info(f"Workflow stažen z webu: {WORKFLOW_URL}")
        return sanitize_workflow(wf, WORKFLOW_URL)
    except Exception as e:
        log.warning(f"Workflow z webu nejde stáhnout ({WORKFLOW_URL}): {e}. Zkusím lokální fallback.")
        return None



def load_workflow_for_project(project_id: int) -> Optional[dict]:
    """Stáhne workflow pro konkrétní projekt z API (s workerovým tokenem)."""
    if not project_id:
        return None
    url = f"{API_BASE}?action=project_workflow&id={project_id}"
    try:
        r = requests.get(url, headers=HEADERS, timeout=30)
        if r.status_code == 404:
            log.warning(f"Projekt #{project_id} nemá workflow soubor — používám výchozí workflow.")
            return None
        r.raise_for_status()
        wf = r.json()
        log.info(f"Workflow stažen pro projekt #{project_id}")
        return sanitize_workflow(wf, f"project:{project_id}")
    except Exception as e:
        log.warning(f"Nelze stáhnout workflow pro projekt #{project_id}: {e} — používám výchozí.")
        return None


def load_workflow() -> dict:
    wf = load_workflow_from_web()
    if wf is not None:
        return wf

    p = Path(WORKFLOW_PATH)
    if not p.exists():
        raise FileNotFoundError(
            f"Workflow neexistuje lokálně: {p}. "
            f"A webové workflow není dostupné: {WORKFLOW_URL}"
        )
    with p.open("r", encoding="utf-8") as f:
        wf = json.load(f)
    log.info(f"Workflow načten lokálně: {p}")
    return sanitize_workflow(wf, str(p))

def deep_replace(obj: Any, repl: Dict[str, Any]) -> Any:
    if isinstance(obj, dict):
        return {k: deep_replace(v, repl) for k, v in obj.items()}
    if isinstance(obj, list):
        return [deep_replace(v, repl) for v in obj]
    if isinstance(obj, str):
        if obj in repl:
            return repl[obj]
        s = obj
        for key, val in repl.items():
            if isinstance(val, (str, int, float)):
                s = s.replace(key, str(val))
        return s
    return obj

def set_by_path(d: dict, dotted: str, value: Any) -> None:
    cur = d
    parts = dotted.split(".")
    for p in parts[:-1]:
        if p not in cur or not isinstance(cur[p], dict):
            cur[p] = {}
        cur = cur[p]
    cur[parts[-1]] = value

def apply_node_patch(wf: dict, values: Dict[str, Any]) -> dict:
    for key, spec in WORKFLOW_PATCH.items():
        if key not in values:
            continue
        node_id = spec.get("node_id")
        path = spec.get("path")
        if node_id and path and node_id in wf:
            set_by_path(wf[node_id], path, values[key])
    return wf

def workflow_contains_value(obj: Any, needle: str) -> bool:
    """Ověří, že se nový název nahraného obrázku opravdu dostal do API workflow."""
    if isinstance(obj, dict):
        return any(workflow_contains_value(v, needle) for v in obj.values())
    if isinstance(obj, list):
        return any(workflow_contains_value(v, needle) for v in obj)
    if isinstance(obj, str):
        return obj == needle or needle in obj
    return False


def _get_node(wf: dict, node_id: Any) -> Optional[dict]:
    """API workflow mívá ID jako string; někdy se do linku dostane int."""
    if isinstance(node_id, (list, tuple)) and node_id:
        node_id = node_id[0]
    return wf.get(str(node_id)) or wf.get(node_id)

def _set_linked_numeric(wf: dict, link_value: Any, value: Any, label: str, patched: List[str], kind: str) -> bool:
    """Když je input node napojený přes Primitive/Integer node, přepiš zdrojový node.
    U LTX workflowu je délka často napojená linkem, např. EmptyLTXVLatentVideo.length -> PrimitiveInt.
    """
    if not isinstance(link_value, (list, tuple)) or not link_value:
        return False
    src = _get_node(wf, link_value[0])
    if not isinstance(src, dict):
        return False
    src_inputs = src.get("inputs")
    if not isinstance(src_inputs, dict):
        return False
    preferred = (
        "value", "int", "integer", "number", "float",
        "frame_count", "frames_number", "num_frames", "length", "video_length",
        "fps", "frame_rate", "duration", "seconds"
    )
    numeric_keys = [k for k in preferred if k in src_inputs and isinstance(src_inputs.get(k), (int, float))]
    if not numeric_keys:
        numeric_keys = [k for k, v in src_inputs.items() if isinstance(v, (int, float))]
    if not numeric_keys:
        return False
    key = numeric_keys[0]
    old = src_inputs[key]
    src_inputs[key] = int(value) if kind in ("frames", "fps", "width", "height", "seed", "steps") else value
    src_type = str(src.get("class_type") or "")
    patched.append(f"{kind} linked {label} -> {link_value[0]}:{src_type}.{key}: {old} -> {src_inputs[key]}")
    return True

def auto_patch_workflow_nodes(wf: dict, values: Dict[str, Any]) -> List[str]:
    """Automaticky přepíše běžné ComfyUI API workflow vstupy.

    Opraveno pro LTX:
    - délka videa bývá jako linkovaný PrimitiveInt, ne přímá hodnota v node
    - první správné framy + následný střih většinou znamená, že se přepsal jen preview/concat image,
      ale ne skutečný prompt nebo skutečný I2V image branch
    - negative prompt se nepřepisuje, pokud je na webu prázdný
    """
    patched: List[str] = []
    new_image = str(values.get("image") or "")
    prompt = str(values.get("positive_prompt") or "")
    negative = str(values.get("negative_prompt") or "").strip()
    width = int(values.get("width") or 0)
    height = int(values.get("height") or 0)
    seed = int(values.get("seed") or 0)
    steps = int(values.get("steps") or 0)
    cfg = float(values.get("cfg") or 0)
    fps = int(values.get("fps") or 0)
    duration = float(values.get("duration") or 0)
    frame_count = int(values.get("frame_count") or 0)
    image_exts = (".png", ".jpg", ".jpeg", ".webp", ".bmp", ".tif", ".tiff")

    text_candidates: List[tuple] = []
    positive_patched = False
    negative_patched = False

    for node_id, node in list(wf.items()):
        if not isinstance(node, dict):
            continue
        inputs = node.get("inputs")
        if not isinstance(inputs, dict):
            continue
        class_type = str(node.get("class_type") or "")
        cls = class_type.lower()
        meta = node.get("_meta") if isinstance(node.get("_meta"), dict) else {}
        title = str(meta.get("title") or node.get("title") or "").lower()
        label = f"{node_id}:{class_type}"

        # 1) VSTUPNÍ OBRÁZEK: patchni všechny skutečné image loadery a image filename stringy.
        is_image_loader = (
            ("load" in cls and "image" in cls) or
            "image" in cls and any(x in cls for x in ("input", "file", "path")) or
            "load image" in title or
            "input image" in title or
            "image input" in title or
            title.strip() in ("image", "input", "start image", "source image")
        )
        if new_image:
            for key in ("image", "image_path", "filename", "file", "path"):
                if key in inputs and isinstance(inputs.get(key), str):
                    old = inputs.get(key)
                    if is_image_loader or str(old).lower().endswith(image_exts):
                        if old != new_image:
                            inputs[key] = new_image
                            patched.append(f"image {label}.{key}: {old} -> {new_image}")
            # Extra pojistka: jakýkoliv image soubor v inputech se přepíše na aktuální upload.
            for key, val in list(inputs.items()):
                if isinstance(val, str) and val.lower().endswith(image_exts) and val != new_image:
                    inputs[key] = new_image
                    patched.append(f"image global {label}.{key}: {val} -> {new_image}")

        # 2) PROMPT / NEGATIVE PROMPT.
        # Pozor: u LTX 2.3 workflowu z Comfy bývá hlavní prompt často uložený jako
        # PrimitiveStringMultiline.inputs.value s title "Prompt" — ne jako CLIPTextEncode.inputs.text.
        # Proto musíme patchovat i input "value", ale jen u jasných text/prompt/string node.
        text_keys = [k for k in ("text", "prompt", "caption", "positive", "negative") if k in inputs and isinstance(inputs.get(k), str)]
        if "value" in inputs and isinstance(inputs.get("value"), str):
            value_is_prompt_text = (
                ("primitive" in cls and "string" in cls) or
                "string" in cls or
                any(x in title for x in ("prompt", "positive", "negative", "caption", "text"))
            )
            if value_is_prompt_text and "value" not in text_keys:
                # U node s title Prompt dej value na začátek, aby se patchnul právě zdrojový prompt.
                if "prompt" in title or "caption" in title or "positive" in title or "negative" in title:
                    text_keys.insert(0, "value")
                else:
                    text_keys.append("value")
        if text_keys:
            key = text_keys[0]
            current_text = str(inputs.get(key) or "")
            is_text_node = (
                any(x in cls for x in ("text", "prompt", "encode", "gemma", "clip", "string")) or
                any(x in title for x in ("prompt", "text", "caption", "positive", "negative"))
            )
            if is_text_node:
                negative_hint = (
                    "negative" in title or "negative" in cls or key == "negative" or
                    any(x in current_text.lower() for x in ("low quality", "ugly", "deformed", "blur", "flicker", "watermark", "cartoon"))
                )
                positive_hint = (not negative_hint) and (
                    "positive" in title or key == "positive" or "prompt" in title or "caption" in title or
                    ("primitive" in cls and "string" in cls and key == "value")
                )
                text_candidates.append((node_id, node, key, label, negative_hint, positive_hint))
                if negative_hint:
                    if negative:
                        old = str(inputs[key])
                        inputs[key] = negative
                        patched.append(f"negative {label}.{key}: {old[:40]!r} -> custom")
                        negative_patched = True
                    # když negative prompt na webu není, necháme workflow default beze změny
                elif positive_hint and prompt:
                    old = str(inputs[key])
                    inputs[key] = prompt
                    patched.append(f"positive {label}.{key}: {old[:40]!r} -> web prompt")
                    positive_patched = True

        # 3) ROZMĚRY / SEED / STEPS / CFG / FPS / DÉLKA.
        def set_num(keys, value, kind, cast_int=False):
            if not value:
                return False
            for k in keys:
                if k in inputs:
                    v = inputs.get(k)
                    if isinstance(v, (int, float)):
                        old = v
                        inputs[k] = int(value) if cast_int else value
                        patched.append(f"{kind} {label}.{k}: {old} -> {inputs[k]}")
                        return True
                    if _set_linked_numeric(wf, v, int(value) if cast_int else value, f"{label}.{k}", patched, kind):
                        return True
            return False

        set_num(("width", "W", "w"), width, "width", True)
        set_num(("height", "H", "h"), height, "height", True)
        set_num(("seed", "noise_seed", "random_seed"), seed, "seed", True)
        if seed:
            for seed_key in ("sampling_mode.seed",):
                if seed_key in inputs and isinstance(inputs.get(seed_key), (int, float)):
                    old_seed = inputs.get(seed_key)
                    inputs[seed_key] = int(seed)
                    patched.append(f"seed {label}.{seed_key}: {old_seed} -> {inputs[seed_key]}")
        set_num(("steps",), steps, "steps", True)
        set_num(("cfg", "guidance", "guidance_scale"), cfg, "cfg", False)
        set_num(("fps", "frame_rate"), fps, "fps", True)
        set_num(("duration", "seconds", "sec", "length_seconds", "video_duration"), duration, "duration", False)
        set_num(("frame_count", "frames", "frames_number", "num_frames", "length", "video_length"), frame_count, "frames", True)

        # LTX workflow často používá PrimitiveInt node s title "Duration", "Frame Rate", "Width", "Height".
        # Ty nemají key duration/fps, ale jen inputs.value, takže je patchujeme podle title.
        if "value" in inputs and isinstance(inputs.get("value"), (int, float)):
            primitive_value = inputs.get("value")
            if width and title in ("width", "w") or ("width" in title and "height" not in title):
                old = primitive_value; inputs["value"] = int(width); patched.append(f"width primitive {label}.value: {old} -> {inputs['value']}")
            elif height and title in ("height", "h") or ("height" in title and "width" not in title):
                old = primitive_value; inputs["value"] = int(height); patched.append(f"height primitive {label}.value: {old} -> {inputs['value']}")
            elif fps and any(x in title for x in ("frame rate", "framerate", "fps")):
                old = primitive_value; inputs["value"] = int(fps); patched.append(f"fps primitive {label}.value: {old} -> {inputs['value']}")
            elif duration and "duration" in title:
                old = primitive_value; inputs["value"] = duration; patched.append(f"duration primitive {label}.value: {old} -> {inputs['value']}")
            elif frame_count and any(x in title for x in ("frame count", "frames", "num frames", "length")) and "rate" not in title:
                old = primitive_value; inputs["value"] = int(frame_count); patched.append(f"frames primitive {label}.value: {old} -> {inputs['value']}")

    # 4) Fallback pro pozitivní prompt, když export nemá title/_meta.
    if prompt and not positive_patched and text_candidates:
        candidates = [c for c in text_candidates if not c[4]] or text_candidates
        node_id, node, key, label, negative_hint, positive_hint = candidates[0]
        old = node["inputs"][key]
        node["inputs"][key] = prompt
        patched.append(f"positive fallback {label}.{key}: {str(old)[:40]!r} -> web prompt")
        positive_patched = True

    # 5) Fallback pro custom negative, jen když ho uživatel vyplnil.
    if negative and not negative_patched and text_candidates:
        candidates = [c for c in text_candidates if c[4]]
        if candidates:
            node_id, node, key, label, negative_hint, positive_hint = candidates[0]
            old = node["inputs"][key]
            node["inputs"][key] = negative
            patched.append(f"negative fallback {label}.{key}: {str(old)[:40]!r} -> custom")
            negative_patched = True

    # 6) Diagnostika: pokud zůstane starý image filename v inputech, vypiš ho do logu jako warning.
    if new_image:
        leftovers = []
        for node_id, node in wf.items():
            if not isinstance(node, dict) or not isinstance(node.get("inputs"), dict):
                continue
            for key, val in node["inputs"].items():
                if isinstance(val, str) and val.lower().endswith(image_exts) and val != new_image:
                    leftovers.append(f"{node_id}:{node.get('class_type','')}.{key}={val}")
        if leftovers:
            log.warning("Ve workflow zůstaly jiné image soubory: " + "; ".join(leftovers[:8]) + (" …" if len(leftovers) > 8 else ""))

    if not positive_patched:
        log.warning("Nepodařilo se najít positive prompt node; zkontroluj API workflow nebo přidej placeholder __POSITIVE_PROMPT__.")
    if negative:
        if not negative_patched:
            log.warning("Custom negative prompt byl zadán, ale nepodařilo se najít negative node; přidej placeholder __NEGATIVE_PROMPT__ nebo node title 'Negative'.")
    else:
        log.info("Negative prompt je prázdný: ponechávám default z Comfy workflow.")

    return patched

CAMERA_PRESETS = {
    "Decentní nájezd dopředu": "the camera pushes in only slightly toward the subject in a restrained and minimal slow dolly forward, the framing tightens just a touch over the duration, smooth, stabilized and continuous",
    "Pomalý nájezd dopředu": "the camera slowly pushes in toward the subject in a smooth dolly forward, gradually tightening the framing, stabilized and continuous",
    "Pomalý odjezd dozadu": "the camera slowly pulls back from the subject in a smooth dolly out, gradually revealing more of the surrounding environment, stabilized and continuous",
    "Obíhání kolem objektu": "the camera circles slowly around the subject in a smooth orbital motion, the subject stays centered in frame, steady continuous parallax",
    "Půlkruhový oblouk": "the camera arcs around the subject in a controlled half-circle, smooth and stabilized, gradually revealing the subject from a new angle",
    "Stoupání kamery (dron nahoru)": "the camera rises upward in a smooth aerial drone movement, gradually revealing the wider landscape below, stabilized and continuous",
    "Klesání kamery (pohled dolů)": "the camera descends slowly from a high overhead view looking straight down at the scene, smooth aerial motion, stabilized",
    "Jeřáb nahoru": "the camera cranes upward in a slow controlled vertical rise, the subject remains in frame, smooth and continuous",
    "Jeřáb dolů": "the camera cranes downward in a slow controlled vertical descent, smooth and stabilized, gradually framing the subject from a lower angle",
    "Pomalý posun do strany": "the camera tracks slowly to the side in a smooth horizontal dolly parallel to the subject, stabilized and continuous",
    "Statická kamera (stativ)": "the camera holds completely still on a locked-off tripod, no camera movement, only the subject and the environment evolve over time",
    "Jemný posun (drobný drift)": "the camera drifts with very subtle, almost imperceptible motion, minimal parallax, breathing-like and stabilized",
    "Z ruky (dokumentární)": "the camera follows in a natural handheld documentary style, slight organic motion, observational and credible, lightly stabilized but not locked",
}

def camera_preset_text(preset: str) -> str:
    return CAMERA_PRESETS.get(str(preset or "").strip(), "")

def _norm_prompt_part(text: str) -> str:
    return " ".join(str(text or "").lower().replace(";", ",").split())

def join_prompt_parts_once(*parts: str) -> str:
    out: List[str] = []
    for part in parts:
        p = str(part or "").strip().strip(",")
        if not p:
            continue
        p_norm = _norm_prompt_part(p)
        joined_norm = _norm_prompt_part(", ".join(out))
        if p_norm and p_norm in joined_norm:
            continue
        out.append(p)
    return ", ".join(out)

def build_workflow(job: dict, comfy_image_name: str, comfy_image_name_2: Optional[str] = None) -> dict:
    settings = job.get("settings") or {}
    fps = int(settings.get("fps", 25))
    duration = float(settings.get("duration", 5))
    frame_count = int(settings.get("frame_count") or round(fps * duration))
    width = int(settings.get("width", 1280))
    height = int(settings.get("height", 720))
    seed = int(settings.get("seed") or 1)
    steps = int(settings.get("steps", 30))
    cfg = float(settings.get("cfg", 3.5))
    motion_strength = float(settings.get("motion_strength", 0.75))
    prompt_enhance = bool(settings.get("prompt_enhance", False))
    enhance_tokens = max(64, min(512, int(settings.get("enhance_tokens") or 128)))
    camera_motion = str(settings.get("camera_motion", "")).strip()
    if not camera_motion:
        preset_name = str(job.get("preset") or "Statická kamera (stativ)")
        camera_motion = camera_preset_text(preset_name)
    style = str(settings.get("style", "")).strip()
    user_prompt = str(job.get("prompt") or "").strip()
    # PHOTO EDIT: workflow, který ukládá obrázek a nemá žádné video nody
    # (Flux.2 edit, FireRed/Qwen edit…). Kamerové texty a video tech-suffix
    # by editační instrukci jen kazily.
    wf = load_workflow()
    is_photo_edit = workflow_is_photo_edit(wf)
    # LTX-2.3 best practice (Lightricks docs + community): pomoct stabilitě fixním
    # technickým suffixem o stabilitě a motion blur. Přidáváme vždy.
    tech_quality = "smooth motion, stable footage, sharp details, high quality, natural motion blur, 180-degree shutter"
    # Pořadí: user prompt -> camera motion -> style -> technical quality
    # Důvod: LTX-2.3 dává vyšší váhu začátku promptu. Hlavní děj / akce proto musí
    # být úplně vpředu. Camera motion je hned za tím jako druhá nejdůležitější vrstva.
    # Style je estetika scény. Tech quality jsou stabilizační vodítka.
    if is_photo_edit:
        camera_motion = ""
        prompt = join_prompt_parts_once(user_prompt, style)
    else:
        prompt = join_prompt_parts_once(user_prompt, camera_motion, style, tech_quality)
    negative = str(job.get("negative_prompt") or "").strip()
    use_custom_negative = bool(negative)

    # Diagnostika: ukaž v logu, co se reálně sestavilo a posílá do LTX. Při potížích
    # ("kamera nereaguje", "styl chybí") je tohle první místo, kam se podívat.
    log.info(
        f"Job #{job['id']} prompt assembly: "
        f"camera_motion={'YES' if camera_motion else 'no'} ({len(camera_motion)} chars), "
        f"style={'YES' if style else 'no'} ({len(style)} chars), "
        f"user_prompt={len(user_prompt)} chars, "
        f"final={len(prompt)} chars"
    )
    log.info(f"Job #{job['id']} FINAL PROMPT to LTX: {prompt[:250]!r}{' …' if len(prompt) > 250 else ''}")

    values = {
        "positive_prompt": prompt,
        "negative_prompt": negative,
        "image": comfy_image_name,
        "image2": comfy_image_name_2 or "",
        "width": width,
        "height": height,
        "fps": fps,
        "duration": duration,
        "frame_count": frame_count,
        "seed": seed,
        "steps": steps,
        "cfg": cfg,
        "motion_strength": motion_strength,
        "camera_motion": camera_motion,
        "output_prefix": f"pz_job_{job['id']}",
    }
    log.info(
        f"Job #{job['id']} timing: UI duration={duration}s, fps={fps}, frame_count={frame_count}; "
        + (f"negative=custom ({len(negative)} chars)" if use_custom_negative else "negative=workflow-default")
    )
    repl = {
        "__POSITIVE_PROMPT__": prompt,
        "__IMAGE_FILENAME__": comfy_image_name,
        "__WIDTH__": width,
        "__HEIGHT__": height,
        "__FPS__": fps,
        "__DURATION__": duration,
        "__FRAME_COUNT__": frame_count,
        "__SEED__": seed,
        "__STEPS__": steps,
        "__CFG__": cfg,
        "__GUIDANCE__": cfg,
        "__MOTION_STRENGTH__": motion_strength,
        "__CAMERA_MOTION__": camera_motion,
        "__OUTPUT_PREFIX__": f"pz_job_{job['id']}",
    }
    if use_custom_negative:
        repl["__NEGATIVE_PROMPT__"] = negative
    wf = deep_replace(wf, repl)

    is_two_pict = bool(comfy_image_name_2) or str(settings.get("input_mode") or "").lower() in ("2pict", "2 pict", "flf2v")
    patch_values = dict(values)
    if is_two_pict:
        # U 2 PICT se nesmí globálně přepsat všechny LoadImage nody na první obrázek.
        patch_values["image"] = ""
    wf = apply_node_patch(wf, patch_values)
    patched = auto_patch_workflow_nodes(wf, patch_values)

    if is_two_pict:
        if not comfy_image_name_2:
            raise RuntimeError("Režim 2 PICT potřebuje druhý obrázek / poslední frejm.")
        set_flf2v_images(wf, comfy_image_name, comfy_image_name_2)
        if not workflow_contains_value(wf, comfy_image_name) or not workflow_contains_value(wf, comfy_image_name_2):
            raise RuntimeError("2 PICT ochrana: první nebo poslední frejm se nedostal do workflow.")
    else:
        restore_ltx_official_image_hold(wf)
        if comfy_image_name and not workflow_contains_value(wf, comfy_image_name):
            raise RuntimeError(
                "Nový obrázek se nepodařilo vložit do workflow. "
                "Worker nahrál obrázek do ComfyUI, ale v API workflow jsem nenašel LoadImage node ani placeholder __IMAGE_FILENAME__. "
                "Zkontroluj, že export je API workflow a obsahuje LoadImage / vstupní obrázek."
            )
        assert_ltx_frame_hold_protected(wf, comfy_image_name)

    enhance_patched = patch_ltx_prompt_enhance(wf, prompt_enhance, enhance_tokens, seed)
    repair_ltx_model_names(wf)
    flux2_patched = patch_flux2_edit(wf, steps)
    if patched:
        log.info("Workflow auto-patch: " + "; ".join(patched[:12]) + (" …" if len(patched) > 12 else ""))
    if enhance_patched:
        log.info("Prompt Enhance patch: " + "; ".join(enhance_patched[:8]) + (" …" if len(enhance_patched) > 8 else ""))
    if flux2_patched:
        log.info("Flux2 PHOTO EDIT patch: " + "; ".join(flux2_patched[:8]))
    return wf

def workflow_is_photo_edit(wf: dict) -> bool:
    """Photo-edit workflow = ukládá obrázek (SaveImage) a nemá žádné video nody.
    Platí pro Flux.2 edit, FireRed/Qwen edit a podobné obrázkové editory."""
    classes = {str(n.get("class_type") or "") for n in wf.values() if isinstance(n, dict)}
    has_image_out = "SaveImage" in classes
    has_video = any(("Video" in c) or c.startswith("LTXV") or c.startswith("LTXA") for c in classes)
    return has_image_out and not has_video

def patch_flux2_edit(wf: dict, steps: int) -> List[str]:
    """PHOTO EDIT: nastaví kroky výpočtu a turbo/lightning LoRA přepínač.
    Turbo se zapne automaticky při steps <= 10 (turbo LoRA jsou dělané na 8 kroků).
    Funguje pro Flux.2 edit ('Enable 8 steps lora') i FireRed ('Enable Lightning LoRA?').
    Mimo photo-edit workflow nic nedělá."""
    if not workflow_is_photo_edit(wf):
        return []
    patched: List[str] = []
    turbo = int(steps) <= 10
    for nid, node in wf.items():
        if not isinstance(node, dict) or not isinstance(node.get("inputs"), dict):
            continue
        cls = str(node.get("class_type") or "")
        meta = node.get("_meta") if isinstance(node.get("_meta"), dict) else {}
        title = str(meta.get("title") or "").lower()
        if cls == "PrimitiveBoolean" and ("lora" in title or "lightning" in title or "turbo" in title):
            old = node["inputs"].get("value")
            node["inputs"]["value"] = bool(turbo)
            patched.append(f"turbo {nid}: {old} -> {turbo}")
        if cls == "PrimitiveInt" and "steps" in title:
            old = node["inputs"].get("value")
            node["inputs"]["value"] = int(steps)
            patched.append(f"steps {nid}: {old} -> {steps}")
    return patched

def submit_prompt(workflow: dict, client_id: str) -> str:
    r = requests.post(f"{COMFY_BASE}/prompt", json={"prompt": workflow, "client_id": client_id}, timeout=60)
    if r.status_code >= 400:
        raise RuntimeError(f"ComfyUI /prompt chyba {r.status_code}: {r.text[:2000]}")
    data = r.json()
    pid = data.get("prompt_id")
    if not pid:
        raise RuntimeError(f"ComfyUI nevrátil prompt_id: {data}")
    return pid

def interrupt_comfy() -> None:
    try:
        requests.post(f"{COMFY_BASE}/interrupt", timeout=5)
    except Exception:
        pass

def check_cancel(job_id: int) -> None:
    try:
        res = api("check_cancel", "POST", json_body={"id": job_id, "worker_id": WORKER_ID}, timeout=15)
        mark_worker_activity()
        if res.get("rate_limited"):
            return
        if bool(res.get("cancelled")):
            interrupt_comfy()
            raise Cancelled()
    except Cancelled:
        raise
    except requests.HTTPError as e:
        if e.response is not None and e.response.status_code == 429:
            log.warning("Forpsi 429 při kontrole cancelu, pauza 60 s")
            time.sleep(60)
        else:
            log.warning(f"check_cancel selhal: {e}")
    except Exception as e:
        log.warning(f"check_cancel selhal: {e}")

def get_queue(raise_errors: bool = False) -> dict:
    try:
        r = requests.get(f"{COMFY_BASE}/queue", timeout=15)
        r.raise_for_status()
        data = r.json()
        return data if isinstance(data, dict) else {}
    except Exception:
        if raise_errors:
            raise
        return {}

def prompt_in_queue(prompt_id: str, queue_data: Optional[dict] = None) -> tuple[bool, bool, int]:
    q = queue_data or {}
    running = False
    pending = False
    pending_count = 0
    try:
        for item in q.get("queue_running", []) or []:
            pid = ""
            if isinstance(item, (list, tuple)):
                pid = str(item[1] if len(item) > 1 else item[0])
            elif isinstance(item, dict):
                pid = str(item.get("prompt_id") or item.get("id") or "")
            if pid == prompt_id:
                running = True
        for item in q.get("queue_pending", []) or []:
            pending_count += 1
            pid = ""
            if isinstance(item, (list, tuple)):
                pid = str(item[1] if len(item) > 1 else item[0])
            elif isinstance(item, dict):
                pid = str(item.get("prompt_id") or item.get("id") or "")
            if pid == prompt_id:
                pending = True
    except Exception:
        pass
    return running, pending, pending_count

def watch_prompt_ws(job_id: int, prompt_id: str, client_id: str, expected_frames: int = 0, workflow: Optional[dict] = None) -> None:
    if websocket is None:
        log.warning("websocket-client není nainstalovaný, přecházím na polling historie/fronty.")
        watch_prompt_poll(job_id, prompt_id)
        return

    ws_url = COMFY_BASE.replace("http://", "ws://").replace("https://", "wss://") + "/ws?" + urlencode({"clientId": client_id})
    last_check = time.time()
    last_progress = 8
    last_signal = time.time()
    started = time.time()
    last_node = "queued"
    ws = None
    try:
        try:
            ws = websocket.create_connection(ws_url, timeout=5)
            ws.settimeout(1)
        except Exception as e:
            log.warning(f"WebSocket se nepřipojil ({e}), přecházím na polling.")
            watch_prompt_poll(job_id, prompt_id)
            return

        update_job(job_id, status="generating", progress=8, current_node="queued", message="ComfyUI generuje")
        while True:
            now = time.time()
            if now - last_check > 60:
                check_cancel(job_id)
                last_check = now

            hist = get_history(prompt_id, allow_empty=True)
            if hist:
                raise_if_history_failed(hist)
                update_job(job_id, status="generating", progress=max(last_progress, 93), current_node="history", message="ComfyUI dokončilo generování")
                return

            if now - last_signal >= 20:
                running, pending, pending_count = prompt_in_queue(prompt_id, get_queue())
                if running:
                    last_progress = max(last_progress, min(90, 15 + int((now - started) / 3)))
                    update_job(job_id, status="generating", progress=last_progress, current_node=last_node, message=f"{node_stage_label(workflow, last_node)}…")
                elif pending:
                    last_progress = max(last_progress, min(20, 8 + pending_count))
                    update_job(job_id, status="queued", progress=last_progress, current_node="queue", message=f"Ve frontě ComfyUI ({pending_count} čeká)")
                else:
                    last_progress = min(92, max(last_progress, last_progress + 1))
                    update_job(job_id, status="generating", progress=last_progress, current_node=last_node, message=f"Čekám na dokončení: {node_stage_label(workflow, last_node)}")
                last_signal = now

            try:
                raw = ws.recv()
            except socket.timeout:
                continue
            except Exception as e:
                log.warning(f"WS recv chyba ({e}), pokračuji pollingem.")
                watch_prompt_poll(job_id, prompt_id, start_progress=last_progress)
                return
            if not isinstance(raw, str):
                continue
            try:
                msg = json.loads(raw)
            except Exception:
                continue
            typ = msg.get("type")
            data = msg.get("data") or {}
            pid = data.get("prompt_id")
            if pid and pid != prompt_id:
                continue
            last_signal = time.time()
            if typ == "executing":
                node = data.get("node")
                if node is None:
                    update_job(job_id, status="generating", progress=max(last_progress, 93), current_node="done", message="ComfyUI dokončilo graf")
                    return
                last_node = str(node)
                update_job(job_id, status="generating", progress=max(last_progress, 12), current_node=last_node, message=f"{node_stage_label(workflow, last_node)} – node {last_node}")
            elif typ == "progress":
                value = data.get("value") or 0
                maxv = data.get("max") or 1
                try:
                    ratio = min(1.0, float(value) / max(float(maxv), 1.0))
                except Exception:
                    ratio = 0.0
                pct = 12 + int(ratio * 78)
                last_progress = max(last_progress, pct)
                last_node = str(data.get("node") or last_node)
                update_job(job_id, status="generating", progress=last_progress, current_node=last_node, message=f"{node_stage_label(workflow, last_node)} – progress {value}/{maxv}")
            elif typ == "executed":
                node = data.get("node")
                if node is not None:
                    last_node = str(node)
                    last_progress = max(last_progress, 85)
                    update_job(job_id, status="generating", progress=last_progress, current_node=last_node, message=f"{node_stage_label(workflow, last_node)} dokončeno – node {last_node}")
            elif typ in ("execution_cached",):
                last_progress = max(last_progress, 70)
                update_job(job_id, status="generating", progress=last_progress, current_node="cache", message="Použit cache")
            elif typ in ("execution_error", "execution_interrupted"):
                raise RuntimeError(f"ComfyUI {typ}: {data}")
    finally:
        try:
            if ws is not None:
                ws.close()
        except Exception:
            pass

def watch_prompt_poll(job_id: int, prompt_id: str, timeout_s: int = 3600, start_progress: int = 8) -> None:
    start = time.time()
    pct = max(8, int(start_progress))
    last_cancel_check = 0.0
    while True:
        now = time.time()
        if now - last_cancel_check > 60:
            check_cancel(job_id)
            last_cancel_check = now
        if time.time() - start > timeout_s:
            raise TimeoutError("ComfyUI timeout při generování")
        hist = get_history(prompt_id, allow_empty=True)
        if hist:
            raise_if_history_failed(hist)
            update_job(job_id, status="generating", progress=max(93, pct), current_node="history", message="ComfyUI dokončilo generování")
            return
        running, pending, pending_count = prompt_in_queue(prompt_id, get_queue())
        if running:
            pct = min(92, pct + 2)
            update_job(job_id, status="generating", progress=pct, current_node="running", message="ComfyUI počítá…")
        elif pending:
            pct = max(pct, min(20, 8 + pending_count))
            update_job(job_id, status="queued", progress=pct, current_node="queue", message=f"Ve frontě ComfyUI ({pending_count} čeká)")
        else:
            pct = min(92, pct + 1)
            update_job(job_id, status="generating", progress=pct, current_node="polling", message="Čekám na dokončení v ComfyUI")
        time.sleep(20)

def get_history(prompt_id: str, allow_empty: bool = False) -> Optional[dict]:
    r = requests.get(f"{COMFY_BASE}/history/{prompt_id}", timeout=30)
    r.raise_for_status()
    data = r.json()
    if prompt_id in data:
        return data[prompt_id]
    if allow_empty:
        return None
    raise RuntimeError(f"History neobsahuje prompt_id {prompt_id}: {data}")

def _short(v: Any, limit: int = 1200) -> str:
    txt = str(v or "")
    return txt if len(txt) <= limit else txt[:limit] + "…"

def extract_history_error(history: dict) -> str:
    status = history.get("status") if isinstance(history, dict) else None
    if not isinstance(status, dict):
        return ""
    status_str = str(status.get("status_str") or "").lower()
    completed = status.get("completed")
    messages = status.get("messages") or []
    parts: List[str] = []
    for msg in reversed(messages if isinstance(messages, list) else []):
        typ = ""
        data = None
        if isinstance(msg, (list, tuple)) and len(msg) >= 2:
            typ, data = str(msg[0] or ""), msg[1]
        elif isinstance(msg, dict):
            typ, data = str(msg.get("type") or ""), msg.get("data") or msg
        if typ not in ("execution_error", "execution_interrupted", "error"):
            continue
        if isinstance(data, dict):
            node = data.get("node_id") or data.get("node")
            cls = data.get("class_type")
            exc = data.get("exception_message") or data.get("message") or data.get("exception_type") or ""
            tb = data.get("traceback") or data.get("traceback_message") or ""
            line = f"{typ}"
            if node or cls:
                line += f" na node {node or '?'} {cls or ''}".rstrip()
            if exc:
                line += f": {exc}"
            if tb and not exc:
                line += f": {tb}"
            parts.append(line)
        else:
            parts.append(f"{typ}: {data}")
        break
    if not parts and (completed is False or status_str in ("error", "failed", "interrupted")):
        parts.append(f"status={status_str or 'neznámý'}, completed={completed}")
    if not parts:
        return ""
    txt = _short(" | ".join(parts), 1800)
    low = txt.lower()
    if "modelmmap" in low and "get_file_handle" in low:
        txt += " | Rychlá oprava: zavři ComfyUI a spusť ho bez Dynamic VRAM / s parametrem --disable-dynamic-vram. Je to chyba při načítání modelu/VAE v aktuálním ComfyUI, ne chyba SaveVideo."
    return txt

def raise_if_history_failed(history: dict) -> None:
    err = extract_history_error(history)
    if err:
        raise RuntimeError("ComfyUI render spadl: " + err)

def find_output_files(history: dict) -> List[dict]:
    outputs = history.get("outputs") or {}
    found: List[dict] = []
    wanted_buckets = ["videos", "gifs", "images"]
    video_ext = {"mp4", "webm", "mov", "mkv", "gif"}
    for node_id, out in outputs.items():
        for bucket in wanted_buckets:
            for item in out.get(bucket, []) or []:
                fn = item.get("filename") or ""
                ext = fn.rsplit(".", 1)[-1].lower() if "." in fn else ""
                # Video buckety vždy; z images bucketu bereme i obrázkové výstupy (PHOTO EDIT / Flux.2).
                if bucket in ("videos", "gifs") or ext in video_ext or (bucket == "images" and ext in ("png", "jpg", "jpeg", "webp")):
                    found.append({
                        "filename": fn,
                        "subfolder": item.get("subfolder", ""),
                        "type": item.get("type", "output"),
                        "bucket": bucket,
                        "node_id": node_id,
                    })
    return found

def download_comfy_output(item: dict, dst_dir: Path) -> Path:
    params = {"filename": item["filename"], "subfolder": item.get("subfolder", ""), "type": item.get("type", "output")}
    url = f"{COMFY_BASE}/view?{urlencode(params)}"
    clean_name = Path(item["filename"]).name
    dst = dst_dir / clean_name
    with requests.get(url, stream=True, timeout=600) as r:
        r.raise_for_status()
        with dst.open("wb") as f:
            for chunk in r.iter_content(1024*1024):
                if chunk:
                    f.write(chunk)
    return dst

def ensure_comfy_online_for_job(job_id: int, timeout_s: int = 150) -> None:
    """Když ComfyUI neběží, worker ho zkusí spustit a počká.

    Bez toho by worker při vypnutém ComfyUI rychle shodil celou frontu do chyb.
    """
    try:
        if comfy_online():
            return
    except Exception:
        pass

    update_job(job_id, status="processing", progress=1, current_node="start_comfy", message="ComfyUI neběží – spouštím ho na PC")
    log.warning(f"ComfyUI neběží na {COMFY_BASE}; zkouším start před jobem #{job_id}.")
    result = start_comfy_from_worker("Auto start před jobem")
    log.warning(f"Auto start ComfyUI výsledek: {result}")

    deadline = time.time() + max(20, timeout_s)
    last_msg = 0.0
    while time.time() < deadline:
        time.sleep(3)
        try:
            if comfy_online():
                update_job(job_id, status="processing", progress=2, current_node="comfy_online", message="ComfyUI je online, pokračuji")
                log.info("ComfyUI je po auto-startu online.")
                return
        except Exception:
            pass
        if time.time() - last_msg > 12:
            remaining = int(max(0, deadline - time.time()))
            update_job(job_id, status="processing", progress=1, current_node="start_comfy", message=f"Čekám na start ComfyUI… {remaining}s")
            last_msg = time.time()

    raise RuntimeError(f"ComfyUI neběží na {COMFY_BASE}. Auto-start se nepovedl. Cesta: {COMFY_EXE_PATH}")

# ─── JOB PROCESS ────────────────────────────────────────────
def process_job(job: dict) -> None:
    global ACTIVE_JOB_ID, ACTIVE_COMFY_PROMPT_ID
    job_id = int(job["id"])
    ACTIVE_JOB_ID = job_id
    ACTIVE_COMFY_PROMPT_ID = None
    mark_worker_activity()
    client_id = str(uuid.uuid4())
    work_dir = TMP_DIR / f"job_{job_id}_{int(time.time())}"
    work_dir.mkdir(parents=True, exist_ok=True)
    log.info(f"→ Job #{job_id}: {job.get('prompt','')[:70]!r}")
    try:
        ensure_comfy_online_for_job(job_id, timeout_s=150)

        update_job(job_id, status="processing", progress=2, current_node="download", message="Stahuji vstupní obrázek")
        input_url = job.get("input_url")
        if not input_url:
            raise RuntimeError("Server nevrátil input_url")
        ext = safe_image_suffix_from_url(input_url)
        unique_input_name = f"pz_job_{job_id}_{uuid.uuid4().hex[:10]}{ext}"
        local_img = normalize_local_image_suffix(download_file(input_url, work_dir / unique_input_name))
        check_cancel(job_id)

        update_job(job_id, status="uploading", progress=5, current_node="upload_image", message=f"Nahrávám obrázek do ComfyUI: {local_img.name}")
        comfy_img_name = upload_image_to_comfy(local_img)
        check_cancel(job_id)

        settings = job.get("settings") or {}
        comfy_img_name_2 = None
        input2_url = job.get("input2_url") or settings.get("input2_url")
        if input2_url:
            update_job(job_id, status="uploading", progress=6, current_node="upload_image2", message="Nahrávám poslední frejm do ComfyUI")
            ext2 = safe_image_suffix_from_url(str(input2_url))
            local_img2 = normalize_local_image_suffix(download_file(str(input2_url), work_dir / f"pz_job_{job_id}_last_{uuid.uuid4().hex[:10]}{ext2}"))
            comfy_img_name_2 = upload_image_to_comfy(local_img2)
            check_cancel(job_id)
        elif str(settings.get("input_mode") or "").lower() in ("2pict", "2 pict", "flf2v"):
            raise RuntimeError("Job je 2 PICT, ale server nevrátil druhý obrázek / input2_url.")

        update_job(job_id, status="queued", progress=7, current_node="workflow", message="Sestavuji workflow", data={"comfy_image": comfy_img_name, "comfy_image_2": comfy_img_name_2})
        # Pokud job má project_id, stáhneme workflow pro ten projekt
        project_id = int(job.get("project_id") or 0)
        if project_id:
            project_wf = load_workflow_for_project(project_id)
            if project_wf is not None:
                # Přepsat globální workflow cache pro tento build
                import functools
                _orig_load = load_workflow
                try:
                    globals()['_project_wf_override'] = project_wf
                    def _patched_load():
                        return globals().get('_project_wf_override') or _orig_load()
                    globals()['load_workflow'] = _patched_load
                    workflow = build_workflow(job, comfy_img_name, comfy_img_name_2)
                finally:
                    globals()['load_workflow'] = _orig_load
                    globals().pop('_project_wf_override', None)
            else:
                workflow = build_workflow(job, comfy_img_name, comfy_img_name_2)
        else:
            workflow = build_workflow(job, comfy_img_name, comfy_img_name_2)
        update_job(job_id, status="queued", progress=7, current_node="workflow", message=f"Do workflow vložen nový obrázek: {comfy_img_name}", data={"comfy_image": comfy_img_name})
        prompt_id = submit_prompt(workflow, client_id)
        globals()["ACTIVE_COMFY_PROMPT_ID"] = prompt_id
        update_job(job_id, status="queued", progress=8, current_node="queued", comfy_prompt_id=prompt_id, message=f"ComfyUI prompt_id {prompt_id}")

        settings = job.get("settings") or {}
        watch_prompt_ws(job_id, prompt_id, client_id, int(settings.get("frame_count") or 0), workflow=workflow)
        check_cancel(job_id)

        update_job(job_id, status="downloading", progress=94, current_node="history", message="Načítám výsledek z ComfyUI")
        hist = get_history(prompt_id)
        raise_if_history_failed(hist)
        outputs = find_output_files(hist)
        if not outputs:
            raise RuntimeError("V ComfyUI history není video výstup. Render neskončil chybou, ale SaveVideo/CreateVideo nic neuložil. Zkontroluj napojení SaveVideo/CreateVideo ve workflow.")
        log.info(f"Výstupy: {outputs}")
        update_job(job_id, status="downloading", progress=95, current_node="download", message=f"Stahuji {len(outputs)} výstupů")

        chosen = None
        for item in outputs:
            out_path = download_comfy_output(item, work_dir)
            if out_path.suffix.lower() in [".mp4", ".webm", ".mov", ".mkv", ".gif"]:
                chosen = out_path
                break
        if chosen is None:
            chosen = download_comfy_output(outputs[0], work_dir)

        last_upload_error = None
        for attempt in range(1, 4):
            try:
                update_job(job_id, status="uploading", progress=min(99, 96 + attempt), current_node="upload_result", message=f"Nahrávám výsledek {chosen.name} ({attempt}/3)")
                upload_result(job_id, chosen)
                last_upload_error = None
                break
            except Exception as e:
                last_upload_error = e
                log.warning(f"Upload výsledku selhal ({attempt}/3): {e}")
                time.sleep(2)
        if last_upload_error is not None:
            raise RuntimeError(f"Výsledek se nepodařilo nahrát na web: {last_upload_error}")

        update_job(job_id, status="done", progress=100, current_node="done", message="Hotovo – video je dostupné na webu")
        log.info(f"✓ Job #{job_id} hotovo")

    except Cancelled:
        log.info(f"⏹ Job #{job_id} zrušen")
        update_job(job_id, status="cancelled", progress=0, current_node="cancelled", error="Zrušeno uživatelem", message="Job zrušen")
    except Exception as e:
        err = str(e)
        log.exception(f"✗ Job #{job_id} chyba: {err}")
        update_job(job_id, status="error", current_node="error", error=err, message=err)
    finally:
        ACTIVE_JOB_ID = None
        ACTIVE_COMFY_PROMPT_ID = None
        mark_worker_activity()
        try:
            shutil.rmtree(work_dir, ignore_errors=True)
        except Exception:
            pass

# ─── MAIN LOOP ──────────────────────────────────────────────
def main() -> None:
    log.info("="*70)
    log.info(" PZ COMFY VIDEO REMOTE — worker")
    log.info(f" API:       {API_BASE}")
    log.info(f" ComfyUI:   {COMFY_BASE}")
    log.info(f" Workflow:  {WORKFLOW_PATH}")
    log.info(f" Worker ID: {WORKER_ID}")
    log.info(f" Version:   {WORKER_VERSION}")
    log.info(f" Long-poll: {LONGPOLL_SECONDS}s + lokální pauza {POLL_INTERVAL}s")
    log.info(f" Watchdog:  {int(WORKER_WATCHDOG_SECONDS)} s bez aktivity + kontrola ComfyUI serveru + restart z webu")
    log.info("="*70)
    threading.Thread(target=worker_control_loop, daemon=True).start()
    last_stats = 0.0
    while True:
        now = time.time()
        if now - last_stats >= STATS_INTERVAL:
            sync_stats()
            last_stats = now
        job = claim_job()
        if job:
            process_job(job)
            sync_stats({"last_job": job.get("id")})
        else:
            time.sleep(POLL_INTERVAL)

if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        log.info("Ukončeno uživatelem.")
