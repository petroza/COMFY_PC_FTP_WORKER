<?php
require_once __DIR__ . '/config.php';
pz_security_headers(false);
$file = __DIR__ . '/downloads/ComfyW_AUTOINSTALACE_CISTA.zip';
if (!is_file($file)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Autoinstalační balíček není na serveru.\n";
    exit;
}
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="ComfyW_AUTOINSTALACE_CISTA.zip"');
header('Content-Length: ' . filesize($file));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
readfile($file);
