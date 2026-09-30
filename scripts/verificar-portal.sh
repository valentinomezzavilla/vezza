#!/usr/bin/env bash
# Verifica rutas, bloqueos y headers del portal de clientes.
# Uso: scripts/verificar-portal.sh <url-base>
set -uo pipefail
base="${1:?Pasá la URL base, ej. https://vezzadev.com}"
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
esperar 200 /clientes/login
esperar 200 /clientes/recuperar
esperar 200 /clientes/activar
esperar 302 /clientes
esperar 401 "/api/acceso-cliente?cliente_id=1"
for ruta in /admin/includes/auth_cliente.php /admin/includes/mail.php \
            /admin/includes/repos/acceso_cliente.php /db/migrations/002_portal.sql; do
  esperar 404 "$ruta"
done

cuerpo=$(curl -s "$base/clientes/activar?token=zzz")
if grep -q "venció o ya se usó" <<<"$cuerpo"; then
  echo "OK    /clientes/activar con token inválido muestra 'link vencido'"
else
  echo "FALLA /clientes/activar con token inválido no muestra 'link vencido'"
  fallos=$((fallos + 1))
fi
cuerpo=$(curl -s "$base/clientes/activar?token%5B%5D=x")
if grep -q "venció o ya se usó" <<<"$cuerpo"; then
  echo "OK    /clientes/activar con token como array muestra 'link vencido'"
else
  echo "FALLA /clientes/activar con token como array no muestra 'link vencido'"
  fallos=$((fallos + 1))
fi

cabeceras=$(curl -sI "$base/clientes/login" | tr -d '\r')
for patron in '^x-robots-tag: noindex' '^cache-control: no-store' '^content-security-policy:'; do
  if grep -qi "$patron" <<<"$cabeceras"; then
    echo "OK    header $patron"
  else
    echo "FALLA falta header $patron en /clientes/login"
    fallos=$((fallos + 1))
  fi
done

echo
if [[ $fallos -eq 0 ]]; then echo "Todo OK"; else echo "$fallos verificaciones fallaron"; exit 1; fi
