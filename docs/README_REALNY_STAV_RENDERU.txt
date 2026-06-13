ComfyW LOCAL DIRECT v11 - realny stav renderu

Update:
- UI uz nezobrazuje jen obecne RENDERUJE.
- Pri odeslani jobu se otevira Comfy websocket na /ws?clientId=...
- Aplikace cte udalosti ComfyUI: execution_start, executing, progress, executed, execution_success, execution_error.
- Aktualni node id se preklada na lidsky stav:
  - Nacitam model
  - Koduji prompt
  - Zpracovavam obrazek
  - Pripravuji latent
  - Generuji snimky - krok X / Y
  - Dekoduji vystup
  - Skladam video
  - Ukladam video
  - Hledam vystupni soubor
- Detail jobu ukazuje i konkretni node/class_type.

Poznamka:
Presny realny stav funguje v aktivnim otevrenem prohlizeci, protoze se bere primo z Comfy websocketu. Serverovy fallback polling zustava zachovany pro frontu, historii, dohledani outputu a hotove joby.
