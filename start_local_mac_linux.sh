#!/usr/bin/env bash
set -e
cd "$(dirname "$0")"
PORT="${PORT:-8788}"
# Bind na 0.0.0.0, aby na server šlo i z ostatních strojů v síti.
# Jen localhost dostaneš přes BIND=127.0.0.1.
BIND="${BIND:-0.0.0.0}"
URL="http://127.0.0.1:${PORT}/"
if ! command -v php >/dev/null 2>&1; then
  echo "PHP není v PATH. Nainstaluj PHP 8+ nebo nahraj složku na PHP hosting."
  exit 1
fi
open_url(){
  if command -v open >/dev/null 2>&1; then open "$URL" >/dev/null 2>&1 || true
  elif command -v xdg-open >/dev/null 2>&1; then xdg-open "$URL" >/dev/null 2>&1 || true
  fi
}
echo "ComfyW local server: $URL"
if [ "$BIND" = "0.0.0.0" ]; then
  echo "V síti: http://$(hostname -I 2>/dev/null | awk '{print $1}'):${PORT}/"
fi
open_url
php -S "${BIND}:${PORT}" -t .
