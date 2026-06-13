ComfyW LOCAL DIRECT – REAL STATUS WS 403 FIX

Oprava problému:
Comfy hlásilo: request with non matching host and origin 127.0.0.1:8000 != 127.0.0.1:8788, returning 403.

Příčina:
Prohlížeč běžel na portu 8788 a zkoušel se připojit přímo na Comfy websocket port 8000. Novější ComfyUI to blokuje kvůli Origin/Host kontrole.

Řešení v této verzi:
- prohlížeč už neotevírá WebSocket přímo do ComfyUI,
- PowerShell server se připojí na Comfy websocket server-side,
- UI čte reálný stav přes /api/jobs,
- v Comfy konzoli už nemá vznikat warning 403 kvůli Origin mismatch.

Zachováno:
- 1 PICT,
- 2 PICT,
- model autofix,
- output dohledávání,
- reálné stavy uzlů renderu.
