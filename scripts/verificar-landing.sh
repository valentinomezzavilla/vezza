#!/usr/bin/env bash
# Guarda una "huella" de la landing pública: hash del HTML + headers relevantes.
# Uso: scripts/verificar-landing.sh <url-base> <archivo-salida>
# Comparar: diff antes.txt despues.txt  (no tiene que haber diferencias)
set -euo pipefail
base="${1:?Pasá la URL base, ej. http://localhost:8080}"
salida="${2:?Pasá el archivo de salida}"
{
  echo "## sha256 de /"
  curl -s --compressed "$base/" | sha256sum | cut -d' ' -f1
  for ruta in / /css/styles.css /js/main.js /robots.txt /sitemap.xml /assets/icons/favicon.svg; do
    echo "## $ruta"
    curl -sI -H 'Accept-Encoding: br, gzip' "$base$ruta" | tr -d '\r' \
      | grep -iE '^(HTTP/|cache-control|content-type|content-encoding|strict-transport-security|x-frame-options|x-content-type-options|referrer-policy|permissions-policy|location)' \
      | sort
  done
} > "$salida"
echo "Guardado en $salida"
