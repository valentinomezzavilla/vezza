<?php
declare(strict_types=1);

const COMPROBANTE_MAX_BYTES = 5 * 1024 * 1024;
const COMPROBANTE_TIPOS = [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];

function uploads_dir(): string
{
    $dir = env('UPLOADS_DIR') ?? dirname(__DIR__, 3) . '/vezza_uploads/comprobantes';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException("No se pudo crear la carpeta de comprobantes: $dir");
    }
    return rtrim($dir, '/\\');
}

function comprobante_borrar_archivo(?string $nombre): void
{
    if ($nombre === null || !preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png|webp)$/', $nombre)) {
        return;
    }
    $ruta = uploads_dir() . '/' . $nombre;
    if (is_file($ruta)) {
        unlink($ruta);
    }
}

function comprobante_guardar(int $cobroId, string $rutaTemporal, int $bytes): array
{
    $cobro = crud_find('cobros', $cobroId);
    if ($bytes <= 0) {
        throw new HttpError(422, 'El archivo está vacío', ['archivo' => 'Está vacío']);
    }
    if ($bytes > COMPROBANTE_MAX_BYTES) {
        throw new HttpError(422, 'El archivo supera los 5 MB', ['archivo' => 'Máximo 5 MB']);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($rutaTemporal);
    $ext = COMPROBANTE_TIPOS[$mime] ?? null;
    if ($ext === null) {
        throw new HttpError(422, 'Formato no permitido. Usá PDF, JPG, PNG o WEBP.', ['archivo' => 'Formato no permitido']);
    }
    $nombre = bin2hex(random_bytes(16)) . '.' . $ext;
    $destino = uploads_dir() . '/' . $nombre;
    $ok = is_uploaded_file($rutaTemporal) ? move_uploaded_file($rutaTemporal, $destino) : copy($rutaTemporal, $destino);
    if (!$ok) {
        throw new RuntimeException('No se pudo guardar el comprobante');
    }
    db()->prepare('UPDATE cobros SET comprobante_archivo = ? WHERE id = ?')->execute([$nombre, $cobroId]);
    comprobante_borrar_archivo($cobro['comprobante_archivo']);
    return cobros_get($cobroId);
}

function comprobante_quitar(int $cobroId): void
{
    $cobro = crud_find('cobros', $cobroId);
    db()->prepare('UPDATE cobros SET comprobante_archivo = NULL WHERE id = ?')->execute([$cobroId]);
    comprobante_borrar_archivo($cobro['comprobante_archivo']);
}

/** @return array{0:string,1:string,2:string} ruta, mime, extensión */
function comprobante_archivo(int $cobroId): array
{
    $cobro = crud_find('cobros', $cobroId);
    $nombre = $cobro['comprobante_archivo'];
    if ($nombre === null || !preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png|webp)$/', $nombre, $m)) {
        throw new HttpError(404, 'Este cobro no tiene archivo');
    }
    $ruta = uploads_dir() . '/' . $nombre;
    if (!is_file($ruta)) {
        throw new HttpError(404, 'No se encontró el archivo');
    }
    return [$ruta, (string)array_search($m[1], COMPROBANTE_TIPOS, true), $m[1]];
}
