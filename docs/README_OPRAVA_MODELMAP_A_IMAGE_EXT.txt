OPRAVA 2026-06-09 — ModelMMAP/get_file_handle + přípona obrázků

Co bylo špatně:
1) ComfyUI spadlo na chybě:
   AttributeError: 'ModelMMAP' object has no attribute 'get_file_handle'
   To je chyba aktuálního ComfyUI při načítání modelu/VAE s Dynamic VRAM, ne chyba SaveVideo.

2) Worker si z URL api.php odvodil příponu .php a nahrával do ComfyUI vstup jako pz_job_xxx.php.
   Nově se obrázek po stažení otestuje podle skutečných bytů a do ComfyUI jde jako .png/.jpg/.webp.

Co je upravené:
- worker_comfy.py: kontroluje error stav v ComfyUI history a vypíše skutečnou chybu místo falešného SaveVideo problému.
- worker_comfy.py: opravuje příponu input obrázku z api.php na skutečný typ souboru.
- worker_comfy.py + download_worker.php: při auto-startu ComfyUI přidává --disable-dynamic-vram.

Důležité:
Pokud už ComfyUI běží ručně, zavři ho celé a spusť znovu s vypnutým Dynamic VRAM / s parametrem --disable-dynamic-vram.
