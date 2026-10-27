<?php
declare(strict_types=1);

// Webhook de Mercado Pago. Público: sin sesión ni CSRF (por eso no usa api_run); la autenticidad sale de la firma
// (x-signature) y de consultar cada pago en la API de Mercado Pago. Toda la lógica vive en pagos_mp.php.

require __DIR__ . '/../admin/includes/bootstrap.php';

send_panel_headers();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    json_out(405, ['resultado' => 'metodo_no_permitido']);
    return;
}

try {
    $r = mp_webhook_procesar(
        [
            'HTTP_X_SIGNATURE' => $_SERVER['HTTP_X_SIGNATURE'] ?? '',
            'HTTP_X_REQUEST_ID' => $_SERVER['HTTP_X_REQUEST_ID'] ?? '',
        ],
        $_GET,
        (string)file_get_contents('php://input')
    );
    json_out($r['http'], ['resultado' => $r['resultado']]);
} catch (Throwable $e) {
    error_log('[mp-webhook] ' . $e);
    json_out(500, ['resultado' => 'error']);
    return;
}

// Los mails de confirmación o rechazo salen después de contestarle a Mercado Pago (el SMTP tarda ~10 s y un reintento
// de Mercado Pago no tiene que esperar ni duplicarlos).
if ($r['http'] === 200) {
    responder_y_seguir();
    try {
        avisos_despachar_inmediatos();
    } catch (Throwable $e) {
        error_log('[mp-webhook] No se pudieron despachar los avisos: ' . $e);
    }
}
