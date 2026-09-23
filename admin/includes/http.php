<?php
declare(strict_types=1);

final class HttpError extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $mensaje,
        public readonly array $campos = [],
    ) {
        parent::__construct($mensaje, $status);
    }
}

final class Http
{
    public static int $ultimoStatus = 200;
    public static ?string $cuerpoPrueba = null;
}

function send_panel_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    header('Expires: 0');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
}

function json_out(int $status, mixed $cuerpo = null): void
{
    Http::$ultimoStatus = $status;
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    if ($status !== 204) {
        echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}

function request_json(): array
{
    $crudo = Http::$cuerpoPrueba ?? (string)file_get_contents('php://input');
    if (trim($crudo) === '') {
        return [];
    }
    $datos = json_decode($crudo, true);
    if (!is_array($datos)) {
        throw new HttpError(400, 'El cuerpo no es JSON válido');
    }
    return $datos;
}

function route_id(): ?int
{
    $id = $_GET['id'] ?? null;
    if ($id === null || $id === '') {
        return null;
    }
    if (!is_string($id) || !ctype_digit($id) || (int)$id < 1) {
        throw new HttpError(404, 'No encontrado');
    }
    return (int)$id;
}

/** En web nunca se muestran errores en pantalla: podrían exponer datos de la base. Quedan en el log. */
function configurar_errores(string $sapi): void
{
    if ($sapi === 'cli') {
        return;
    }
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    set_exception_handler('responder_error_interno');
}

function responder_error_interno(Throwable $e): void
{
    error_log('[panel] ' . $e);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'Algo salió mal en el panel. El detalle quedó en el log de errores de PHP.';
}

function e(mixed $texto): string
{
    return htmlspecialchars((string)$texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
