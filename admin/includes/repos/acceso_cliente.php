<?php
declare(strict_types=1);

function acceso_cliente_publico(?array $u): array
{
    if ($u === null) {
        return ['estado' => 'sin_acceso', 'email' => null, 'ultimo_login' => null, 'invitacion_vigente' => false, 'invitacion_vence' => null];
    }
    $estado = !(int)$u['activo'] ? 'desactivado' : ($u['password_hash'] !== null ? 'activo' : 'invitado');
    $hayInvitacion = $u['token_tipo'] === 'invitacion' && $u['token_hash'] !== null;
    return [
        'estado' => $estado,
        'email' => $u['email'],
        'ultimo_login' => $u['ultimo_login'],
        'invitacion_vigente' => $hayInvitacion && (bool)$u['token_vigente'],
        'invitacion_vence' => $hayInvitacion ? $u['token_expira'] : null,
    ];
}

function acceso_cliente_get(int $clienteId): array
{
    crud_find('clientes', $clienteId);
    $u = q_one(
        'SELECT *, (token_expira IS NOT NULL AND token_expira > NOW()) AS token_vigente FROM usuarios_cliente WHERE cliente_id = ?',
        [$clienteId]
    );
    return acceso_cliente_publico($u);
}

/**
 * Crea (o renueva) la invitación del cliente. Invitar de nuevo invalida el link anterior, reactiva un
 * acceso desactivado y no toca la contraseña de quien ya la tenía. El link se devuelve siempre:
 * si el mail falla, el admin lo copia y lo manda por otro canal.
 */
function acceso_cliente_invitar(int $clienteId, array $input): array
{
    $cliente = crud_find('clientes', $clienteId);
    $datos = validate($input, ['email' => ['type' => 'email']], true);
    $email = cliente_email_normalizar((string)($datos['email'] ?? $cliente['email'] ?? ''));
    if ($email === '') {
        throw new HttpError(422, 'Revisá los datos marcados', ['email' => 'Cargá un email para invitar al cliente']);
    }
    $ajeno = q_one('SELECT cliente_id FROM usuarios_cliente WHERE email = ?', [$email]);
    if ($ajeno !== null && (int)$ajeno['cliente_id'] !== $clienteId) {
        throw new HttpError(409, 'Ese email ya tiene acceso al portal de otro cliente.');
    }
    $token = tx(function () use ($clienteId, $email): string {
        $actual = q_one('SELECT id FROM usuarios_cliente WHERE cliente_id = ?', [$clienteId]);
        if ($actual === null) {
            $usuarioId = crud_insert('usuarios_cliente', ['cliente_id' => $clienteId, 'email' => $email]);
        } else {
            $usuarioId = (int)$actual['id'];
            crud_update('usuarios_cliente', $usuarioId, ['email' => $email, 'activo' => 1]);
        }
        return cliente_token_emitir($usuarioId, 'invitacion');
    });
    $link = portal_url('/clientes/activar?token=' . $token);
    $enviado = mail_enviar('invitacion', $email, ['nombre' => $cliente['nombre'], 'link' => $link]);
    return acceso_cliente_get($clienteId) + ['link' => $link, 'mail_enviado' => $enviado];
}

function acceso_cliente_set_activo(int $clienteId, bool $activo): array
{
    crud_find('clientes', $clienteId);
    $u = q_one('SELECT id FROM usuarios_cliente WHERE cliente_id = ?', [$clienteId])
        ?? throw new HttpError(404, 'Este cliente todavía no tiene acceso al portal.');
    crud_update('usuarios_cliente', (int)$u['id'], ['activo' => $activo ? 1 : 0]);
    return acceso_cliente_get($clienteId);
}
