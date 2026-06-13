PZ COMFY FTP v7 polish + diagnostics

Změny:
- zachovaný původní funkční základ a výběr LTX režimů 1 PICT / 2 PICT
- náhledy obrázků a video preview centrované přes object-fit/object-position center
- dodělané seed režimy: navyšovat seed v batchi, zamknout stejný seed, náhodný seed pro každý obrázek
- zopakovat job: se stejným seedem nebo s novým seedem
- u 2 PICT rerunu se kopíruje i druhý obrázek
- worker verze: web umí poznat starý/aktuální worker
- diagnostika v horní liště: PHP, SQLite, složky, workflow, token node, Prompt Enhance node, worker
- čistý worker ZIP: START_WORKER.bat v rootu, pracovní soubory ve _worker/
- Prompt Enhance i tokeny zůstávají defaultně vypnuté/bezpečné

Po nahrání na FTP stáhni nový worker ZIP z webu.


v8 doplnění: automatický import 1 PICT / 2 PICT projektů do staré databáze bez přepsání db.sqlite.
