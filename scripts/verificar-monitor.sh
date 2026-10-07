#!/usr/bin/env bash
# Verifica rutas y bloqueos del monitor de servicios.
# Uso: scripts/verificar-monitor.sh <url-base>
set -uo pipefail
base="${1:?Pasá la URL base, ej. https://vezzadev.com}"
fallos=0

esperar() {
  local esperado="$1" ruta="$2" metodo="${3:-GET}" real
  real=$(curl -s -X "$metodo" -o /dev/null -w '%{http_code}' "$base$ruta")
  if [[ "$real" == "$esperado" ]]; then
    echo "OK    $real $metodo $ruta"
  else
    echo "FALLA $real (esperaba $esperado) $metodo $ruta"
    fallos=$((fallos + 1))
  fi
}

esperar 302 /admin/monitor
esperar 401 /api/monitor
esperar 401 /api/monitor/1
esperar 401 /api/monitor-eventos
esperar 401 /api/monitor/chequear POST
esperar 401 /api/monitor/1/chequear POST
for ruta in /scripts/monitor-run.php /admin/includes/repos/monitor.php /db/migrations/003_monitor.sql; do
  esperar 404 "$ruta"
done

echo
if [[ $fallos -eq 0 ]]; then echo "Todo OK"; else echo "$fallos verificaciones fallaron"; exit 1; fi
