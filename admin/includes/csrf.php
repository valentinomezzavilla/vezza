<?php
declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(?string $token): void
{
    $esperado = $_SESSION['csrf'] ?? '';
    if (!is_string($esperado) || $esperado === '' || !is_string($token) || !hash_equals($esperado, $token)) {
        throw new HttpError(403, 'Token de seguridad inválido. Recargá la página.');
    }
}
