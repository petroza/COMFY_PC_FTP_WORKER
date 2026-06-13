ComfyW FTP v8 — oprava importu LTX projektů

Tento update řeší hlášku:
"2 PICT projekt ještě není v databázi. Spusť update/import projektů."

Příčina:
Bezpečný update nepřepisuje db.sqlite, takže na běžícím FTP mohla zůstat stará databáze bez 2 PICT projektu.

Oprava:
api.php při startu automaticky doplní/aktualizuje projekty:
- LTX 2.3 nový model i2v / 1 PICT
- LTX 2.3 první + poslední frejm / 2 PICT

Fronta, videa, uploady a nastavení se nemažou.
Po nahrání stačí obnovit stránku, případně Ctrl+F5.
