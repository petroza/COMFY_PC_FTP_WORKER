SAFE WORKER START update

Tato verze opravuje generovani workeru:
- START_COMFY.bat uz nespousti stare ComfyUI.exe.
- Spousti primo Comfy Desktop backend main.py s cestami z logu.
- Pouziva --disable-mmap --disable-dynamic-vram.
- Pred startem ukonci stare Comfy procesy, port 8000 a python ComfyUI main.py, aby nevznikl comfyui.db lock.
- START_WORKER.bat pred spustenim workeru automaticky nastartuje spravny backend.
- Worker preferuje PZ_COMFY_START_CMD / START_COMFY.bat pred EXE.
