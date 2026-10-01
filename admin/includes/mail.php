<?php
declare(strict_types=1);

final class Mail
{
    /** @var (callable(string, string, array): bool)|null Transporte de prueba: recibe (url, secreto, payload). */
    public static $transporte = null;
}

function portal_url(string $ruta): string
{
    return rtrim(env('PORTAL_URL', 'https://vezzadev.com'), '/') . $ruta;
}

/**
 * Manda un mail por el webhook de n8n. Nunca lanza: si falla devuelve false y el llamador
 * sigue (el admin siempre ve el link para copiarlo y mandarlo por otro canal).
 */
function mail_enviar(string $plantilla, string $destino, array $datos): bool
{
    $url = env('MAIL_WEBHOOK_URL');
    if ($url === null) {
        error_log('[portal] MAIL_WEBHOOK_URL no está configurada; no se mandó el mail');
        return false;
    }
    $payload = ['plantilla' => $plantilla, 'destino' => $destino, 'datos' => $datos];
    $secreto = (string)env('MAIL_WEBHOOK_SECRET', '');
    try {
        if (Mail::$transporte !== null) {
            return (bool)(Mail::$transporte)($url, $secreto, $payload);
        }
        return mail_post($url, $secreto, $payload);
    } catch (Throwable $e) {
        error_log('[portal] No se pudo mandar el mail: ' . $e->getMessage());
        return false;
    }
}

function mail_post(string $url, string $secreto, array $payload): bool
{
    $contexto = stream_context_create(['http' => [
        'method' => 'POST',
        // n8n responde recién cuando el SMTP terminó de enviar (~9-11 s); con menos corta y avisa error aunque el mail salga.
        'timeout' => 25,
        'ignore_errors' => true,
        'header' => "Content-Type: application/json\r\nX-Webhook-Secret: $secreto\r\n",
        'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ]]);
    $respuesta = @file_get_contents($url, false, $contexto);
    if ($respuesta === false) {
        return false;
    }
    $cabeceras = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?? [])
        : ($http_response_header ?? []);
    return (bool)preg_match('#^HTTP/\S+\s+2\d\d#', (string)($cabeceras[0] ?? ''));
}
