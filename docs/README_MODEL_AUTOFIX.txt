ComfyW LOCAL DIRECT - LTX model autofix

Oprava pro chybu Comfy HTTP 400 / value_not_in_list:
Workflow FLF2V už nepoužívá natvrdo ltx-2.3-22b-distilled-fp8.safetensors.
Default je ltx-2.3-22b-dev-fp8.safetensors.

Server navíc před odesláním jobu čte Comfy /object_info a pokud název modelu není
v seznamu dostupných modelů, automaticky přepíše LTX checkpoint na dostupný LTX model.
Typicky vybere ltx-2.3-22b-dev-fp8.safetensors, pokud je v Comfy nainstalovaný.

Týká se nodů:
- CheckpointLoaderSimple
- LTXVAudioVAELoader
- LTXAVTextEncoderLoader
