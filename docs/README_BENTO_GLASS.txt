==========================================================
  ComfyW · LTX 2.3 — BENTO GLASS redesign (FTP balíček)
==========================================================

CO JE NOVÉ
----------
Vzhled celé webové části přepsán do jednotného stylu "Bento Glass"
(skleněné panely, violet→cyan gradient, jednotná typografie).
ŽÁDNÁ funkce se neměnila — backend, fronta, worker, překlad promptu,
crop, výběr fronty, přepínání témat i jazyka zůstávají stejné.

Změněné soubory (jen CSS / vzhled):
  • index.php   — landing
  • app.php     — hlavní aplikace
  • admin.php   — admin panel (login i hlavní)

Vše ostatní (api.php, config.php, security.php, comfy.php, worker,
workflows, .htaccess, …) je beze změny.

NAHRÁNÍ NA FTP
--------------
Varianta A — máš živá data (joby, uživatele) na serveru:
  Nahraj POUZE tyto tři soubory (přepiš stávající):
      index.php
      app.php
      admin.php
  NENAHRÁVEJ db.sqlite — přepsal bys živou databázi.

Varianta B — čistá / nová instalace:
  Nahraj celý obsah složky ComfyW/ na server.

Tip: před přepisem si starý app.php / admin.php / index.php zazálohuj.

POZNÁMKA K FONTŮM
-----------------
Skin tahá fonty (Inter / Space Grotesk / JetBrains Mono) z Google Fonts
přes @import v CSS. Když je prohlížeč/server nenačte, padá to na
systémové fonty a appka funguje dál. Chceš-li nulovou externí závislost,
stačí říct a fonty zašiju lokálně.

db.sqlite v tomto balíčku je snapshot z tvého nahraného ZIPu (nemusí být
nejnovější). Viz Varianta A výše.
