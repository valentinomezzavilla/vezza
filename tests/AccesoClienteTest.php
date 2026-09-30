<?php
declare(strict_types=1);

final class AccesoClienteTest extends DbTestCase
{
    private int $clienteId;
    /** @var array<int, array> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clienteId = crud_insert('clientes', ['nombre' => 'Panadería Sol', 'email' => 'cli@sol.com']);
        $this->mails = [];
        env_set('MAIL_WEBHOOK_URL', 'https://n8n.test/hook');
        env_set('PORTAL_URL', 'https://portal.test');
        Mail::$transporte = function (string $url, string $secreto, array $payload): bool {
            $this->mails[] = $payload;
            return true;
        };
    }

    protected function tearDown(): void
    {
        Mail::$transporte = null;
        env_set('MAIL_WEBHOOK_URL', null);
        env_set('PORTAL_URL', null);
        parent::tearDown();
    }

    private function tokenDe(array $resultado): string
    {
        parse_str((string)parse_url($resultado['link'], PHP_URL_QUERY), $q);
        return $q['token'];
    }

    public function test_un_cliente_sin_acceso(): void
    {
        $a = acceso_cliente_get($this->clienteId);
        $this->assertSame('sin_acceso', $a['estado']);
        $this->assertNull($a['email']);
        $this->assertFalse($a['invitacion_vigente']);
    }

    public function test_cliente_inexistente_da_404(): void
    {
        $this->assertHttp(404, fn() => acceso_cliente_get(999999));
        $this->assertHttp(404, fn() => acceso_cliente_invitar(999999, []));
    }

    public function test_invitar_usa_el_email_del_cliente_y_devuelve_un_link_que_sirve(): void
    {
        $r = acceso_cliente_invitar($this->clienteId, []);
        $this->assertSame('invitado', $r['estado']);
        $this->assertSame('cli@sol.com', $r['email']);
        $this->assertTrue($r['invitacion_vigente']);
        $this->assertTrue($r['mail_enviado']);
        $this->assertStringStartsWith('https://portal.test/clientes/activar?token=', $r['link']);
        $this->assertTrue(cliente_token_valido($this->tokenDe($r), 'invitacion'));
        $this->assertSame('invitacion', $this->mails[0]['plantilla']);
        $this->assertSame('cli@sol.com', $this->mails[0]['destino']);
        $this->assertSame('Panadería Sol', $this->mails[0]['datos']['nombre']);
        $this->assertSame($r['link'], $this->mails[0]['datos']['link']);
    }

    public function test_invitar_con_un_email_explicito_lo_normaliza_y_pisa_el_del_cliente(): void
    {
        $r = acceso_cliente_invitar($this->clienteId, ['email' => '  Otro@Sol.COM ']);
        $this->assertSame('otro@sol.com', $r['email']);
    }

    public function test_invitar_sin_ningun_email_da_422(): void
    {
        $sinMail = crud_insert('clientes', ['nombre' => 'Sin mail']);
        $campos = $this->errores422(fn() => acceso_cliente_invitar($sinMail, []));
        $this->assertArrayHasKey('email', $campos);
        $campos = $this->errores422(fn() => acceso_cliente_invitar($sinMail, ['email' => 'no-es-mail']));
        $this->assertArrayHasKey('email', $campos);
    }

    public function test_invitar_con_el_email_de_otro_cliente_da_409(): void
    {
        $otro = crud_insert('clientes', ['nombre' => 'Otro']);
        acceso_cliente_invitar($otro, ['email' => 'compartido@x.com']);
        $this->assertHttp(409, fn() => acceso_cliente_invitar($this->clienteId, ['email' => 'compartido@x.com']));
        $this->assertSame('sin_acceso', acceso_cliente_get($this->clienteId)['estado']);
    }

    public function test_reinvitar_invalida_el_link_anterior(): void
    {
        $uno = acceso_cliente_invitar($this->clienteId, []);
        $dos = acceso_cliente_invitar($this->clienteId, []);
        $this->assertFalse(cliente_token_valido($this->tokenDe($uno), 'invitacion'));
        $this->assertTrue(cliente_token_valido($this->tokenDe($dos), 'invitacion'));
        $this->assertSame(1, (int)q_val('SELECT COUNT(*) FROM usuarios_cliente WHERE cliente_id = ?', [$this->clienteId]));
    }

    public function test_reinvitar_a_un_cliente_activo_no_toca_su_contrasena(): void
    {
        $this->crearUsuarioCliente($this->clienteId, 'cli@sol.com', 'secreta1');
        $r = acceso_cliente_invitar($this->clienteId, []);
        $this->assertSame('activo', $r['estado']);
        $this->assertTrue($r['invitacion_vigente']);
        $this->assertSame('ok', cliente_auth_attempt('cli@sol.com', 'secreta1', '1.1.1.1'));
    }

    public function test_reinvitar_a_un_desactivado_lo_reactiva(): void
    {
        acceso_cliente_invitar($this->clienteId, []);
        acceso_cliente_set_activo($this->clienteId, false);
        $this->assertSame('desactivado', acceso_cliente_get($this->clienteId)['estado']);
        $r = acceso_cliente_invitar($this->clienteId, []);
        $this->assertSame('invitado', $r['estado']);
        $this->assertTrue(cliente_token_valido($this->tokenDe($r), 'invitacion'));
    }

    public function test_reinvitar_a_un_desactivado_con_clave_no_la_pisa(): void
    {
        $this->crearUsuarioCliente($this->clienteId, 'cli@sol.com', 'secreta1');
        acceso_cliente_set_activo($this->clienteId, false);
        acceso_cliente_invitar($this->clienteId, []);
        $hash = (string)q_val('SELECT password_hash FROM usuarios_cliente WHERE cliente_id = ?', [$this->clienteId]);
        $this->assertTrue(password_verify('secreta1', $hash));
    }

    public function test_si_el_mail_falla_la_invitacion_igual_devuelve_el_link(): void
    {
        Mail::$transporte = fn() => throw new RuntimeException('n8n caído');
        $r = acceso_cliente_invitar($this->clienteId, []);
        $this->assertFalse($r['mail_enviado']);
        $this->assertTrue(cliente_token_valido($this->tokenDe($r), 'invitacion'));
    }

    public function test_desactivar_y_reactivar(): void
    {
        $this->crearUsuarioCliente($this->clienteId, 'cli@sol.com', 'secreta1');
        $this->assertSame('activo', acceso_cliente_get($this->clienteId)['estado']);
        $this->assertSame('desactivado', acceso_cliente_set_activo($this->clienteId, false)['estado']);
        $this->assertSame('invalido', cliente_auth_attempt('cli@sol.com', 'secreta1', '1.1.1.1'));
        $this->assertSame('activo', acceso_cliente_set_activo($this->clienteId, true)['estado']);
        $this->assertSame('ok', cliente_auth_attempt('cli@sol.com', 'secreta1', '2.2.2.2'));
    }

    public function test_desactivar_a_un_cliente_sin_acceso_da_404(): void
    {
        $this->assertHttp(404, fn() => acceso_cliente_set_activo($this->clienteId, false));
    }

    public function test_la_respuesta_no_expone_hashes(): void
    {
        $r = acceso_cliente_invitar($this->clienteId, []);
        foreach (['password_hash', 'token_hash', 'token_tipo', 'id', 'cliente_id'] as $clave) {
            $this->assertArrayNotHasKey($clave, $r);
        }
    }
}
