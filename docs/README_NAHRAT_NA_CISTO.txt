ComfyW FTP LTX 2.3 — čistý kompletní balíček

Postup:
1) Na FTP smaž/starou složku ComfyW nejdřív zazálohuj.
2) Nahraj celou složku ComfyW z tohoto ZIPu.
3) Otevři /ComfyW/ nebo root index.html.
4) Přihlas se stejným admin účtem jako ve staré verzi.
5) Stáhni nový worker z webu a spusť ho u lokálního ComfyUI.

Tento balíček obsahuje:
- db.sqlite čistě připravenou bez starých jobů, eventů, cache a worker tokenů
- config.php ze staré FTP verze, aby zůstalo stejné přihlášení
- nové LTX 2.3 workflowy pro 1 PICT i 2 PICT
- nový worker_comfy.py
- index.html / index.php

Poznámka:
UPDATE_LTX23_FTP.php je ponechaný jako nouzový update skript. U čisté databáze už není potřeba. Po nahrání ho můžeš z FTP smazat.
