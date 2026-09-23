#!/usr/bin/env bash
# Verifica rutas, bloqueos y headers del panel.
# Uso: scripts/verificar-panel.sh <url-base>
set -uo pipefail
base="${1:?Pasá la URL base, ej. http://localhost:8080}"
fallos=0

esperar() {
  local esperado="$1" ruta="$2" real
  real=$(curl -s -o /dev/null -w '%{http_code}' "$base$ruta")
  if [[ "$real" == "$esperado" ]]; then
    echo "OK    $real $ruta"
  else
    echo "FALLA $real (esperaba $esperado) $ruta"
    fallos=$((fallos + 1))
  fi
}

esperar 200 /
esperar 200 /admin-login
esperar 302 /admin
esperar 302 /admin/clientes
esperar 302 /admin/clientes/1
esperar 401 /api/clientes
esperar 401 /api/clientes/1
esperar 401 /api/reportes/balance
for ruta in /.env /.env.example /.env.testing /composer.json /composer.lock /phpunit.xml \
            /db/migrations/001_inicial.sql /db/migrate.php /admin/includes/env.php \
            /admin/includes/repos/clientes.php /scripts/hash-password.php /tests/bootstrap.php \
            /vendor/autoload.php /docs/superpowers/specs/2026-09-22-panel-admin-design.md; do
  esperar 404 "$ruta"
done

cabeceras=$(curl -sI "$base/admin-login" | tr -d '\r')
for patron in '^x-robots-tag: noindex' '^cache-control: no-store' '^content-security-policy:'; do
  if grep -qi "$patron" <<<"$cabeceras"; then
    echo "OK    header $patron"
  else
    echo "FALLA falta header $patron en /admin-login"
    fallos=$((fallos + 1))
  fi
done

echo
if [[ $fallos -eq 0 ]]; then echo "Todo OK"; else echo "$fallos verificaciones fallaron"; exit 1; fi
