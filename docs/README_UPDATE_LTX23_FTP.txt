PZ COMFY FTP UPDATE — LTX 2.3 / 1 PICT + 2 PICT

POSTUP:
1) Na FTP nahraj obsah složky ComfyW přes svoji stávající složku ComfyW.
2) Nepřepisuj svůj db.sqlite, uploads, outputs ani cache, pokud už máš na FTP ostrá data. Tento update zip je schválně neobsahuje.
3) V prohlížeči jednou otevři: https://TVUJDOME/ComfyW/UPDATE_LTX23_FTP.php
4) Po úspěchu soubor UPDATE_LTX23_FTP.php z FTP smaž.
5) Na webu stáhni nový worker ZIP a spusť nový START_WORKER.bat na počítači s ComfyUI. Starý worker neumí 2 PICT a model autofix.

CO JE NOVÉ:
- LTX 2.3 nový model i2v / 1 PICT workflow.
- LTX 2.3 první + poslední frejm / 2 PICT workflow.
- Ochrana držení vstupní fotky u 1 PICT workflowu: image-hold nody 320:288 a 320:296.
- Model autofix: když Comfy nemá model uvedený ve workflowu, worker zkusí vybrat dostupný LTX model.
- Reálnější status renderu podle Comfy node tříd: model, prompt, obrázek, latent, snímky, dekódování, ukládání.

POZNÁMKA:
Modely .safetensors se na FTP nenahrávají. Musí být fyzicky v ComfyUI na lokálním PC ve správných složkách modelů.
