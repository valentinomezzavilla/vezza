<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

api_run(function (): void {
    $id = route_id() ?? throw new HttpError(404, 'No encontrado');
    switch ($_SERVER['REQUEST_METHOD'] ?? 'GET') {
        case 'GET':
            [$ruta, $mime, $ext] = comprobante_archivo($id);
            header_remove('Content-Security-Policy');
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($ruta));
            header('Content-Disposition: inline; filename="comprobante-' . $id . '.' . $ext . '"');
            readfile($ruta);
            return;
        case 'POST':
            $f = $_FILES['archivo'] ?? null;
            $error = is_array($f) ? ($f['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
            if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
                throw new HttpError(422, 'El archivo supera el máximo permitido', ['archivo' => 'Muy pesado']);
            }
            if ($error !== UPLOAD_ERR_OK || !is_string($f['tmp_name'] ?? null) || !is_uploaded_file($f['tmp_name'])) {
                throw new HttpError(422, 'No llegó ningún archivo', ['archivo' => 'Elegí un archivo']);
            }
            json_out(200, ['data' => comprobante_guardar($id, $f['tmp_name'], (int)$f['size'])]);
            return;
        case 'DELETE':
            comprobante_quitar($id);
            json_out(204);
            return;
    }
    throw new HttpError(405, 'Método no permitido');
});
