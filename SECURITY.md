# Bezpečnostní poznámky / Security notes

Tento veřejný repozitář byl před publikací očištěn. / This public repository was cleaned before publishing.

## Co v repu NENÍ (a nikdy nesmí být)

- `config.php` s reálným heslem (bcrypt hash) a `WORKER_TOKEN_PEPPER` — v repu je jen vzor `config.example.php`
- SQLite databáze (`db.sqlite` + WAL/SHM soubory) — obsahuje uživatele, joby, hashe tokenů a IP adresy
- obsah `cache/` (dashboard snapshoty obsahují prompty a stavy workerů)
- vygenerovaná videa v `outputs/` a vstupní obrázky v `uploads/`
- generované instalační/worker ZIPy v `downloads/` — **pozor, obsahují ostrý `config.php` a `db.sqlite`!**
- jakékoliv worker tokeny (`pzwrk_…`) nebo pepper (`pzpep_…`)

## Po nasazení

1. Vyplň vlastní `config.php` podle `config.example.php` (nové heslo, nový pepper).
2. Spusť `install.php` a **hned ho smaž z FTP** — je nechráněný a každé spuštění
   resetuje admin účet a smaže ostatní uživatele.
3. Zkontroluj `security.php` — všechny kontroly citlivých cest musí hlásit 403/OK.
4. Worker tokeny vydávej jen přes „Stáhnout worker ZIP“; staré revokuj v `security.php`.

## Hlášení chyb

Pokud najdeš bezpečnostní problém, otevři issue bez technických detailů a domluvíme se na soukromém kanálu.
