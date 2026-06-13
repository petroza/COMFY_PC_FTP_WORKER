MAX OCHRANA LTX i2v

Tato verze chrání dvě věci:

1) Vypadnutí fotky z timeline
- input image se před odesláním zapíše do všech image/file/path odkazů ve workflow
- LTX image-hold nody 320:288 a 320:296 jsou před odesláním znovu zamčené
- bypass je obnoven jako ve staré funkční aplikaci přes node 320:302
- když ochrana selže, job se raději neodešle a vypíše chybu

2) Funkční kamerové presety
- prompt používá explicitní řádek CAMERA DIRECTIVE
- camera directive se vkládá do promptu dvakrát, aby ji prompt enhancer nepřepsal
- statická kamera obsahuje i negativní instrukce: no pan, no tilt, no zoom, no dolly, no orbit
