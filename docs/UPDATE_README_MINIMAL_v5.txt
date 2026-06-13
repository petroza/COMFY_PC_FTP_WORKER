ComfyW FTP minimal update v5 — založeno na poslední funkční záloze 10_6

Co se měnilo:
- UI zůstává z původního funkčního kódu.
- Zachován výběr LTX režimů 1 PICT / 2 PICT a projekt/workflow select.
- Přidán čistě zarovnaný Prompt Enhance (LTX) checkbox + Délka vylepšeného promptu 64–512, default 128.
- Prompt Enhance je defaultně OFF.
- Tokeny se ve workeru patchují dynamicky podle class_type TextGenerateLTX2Prompt, ne podle pevného node ID.
- Seed se patchuje i do noise_seed / random seed inputů, takže LTX opravdu dostane seed z webu.
- Batch více fotek používá seed + pořadí, aby všechny joby neměly stejný seed.
- Pending editor zachová seed, když se pole nevyplní, a umí uložit Prompt Enhance/tokeny.
- Použít nastavení z jobu obnoví také 1 PICT / 2 PICT projekt.
- download_worker.php má ochranu proti poškození ZIPu PHP warningem/outputem.

Bezpečné nahrání na FTP:
Nahraj obsah ZIPu přes stávající instalaci. Tento update neobsahuje config.php, db.sqlite, uploads, outputs, tmp ani cache.
Po nahrání stáhni z webu nový worker ZIP a spusť nový worker.
