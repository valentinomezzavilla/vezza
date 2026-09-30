<?php
declare(strict_types=1);

final class AuthClienteTest extends DbTestCase
{
    private int $clienteId;
    private int $usuarioId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clienteId = $this->crearCliente('Panadería Sol');
        $this->usuarioId = $this->crearUsuarioCliente($this->clienteId, 'ana@sol.com', 'secreta1');
    }

    public function test_login_correcto_abre_la_sesion_de_cliente(): void
    {
        $this->assertSame('ok', cliente_auth_attempt('ana@sol.com', 'secreta1', '1.1.1.1'));
        $this->assertTrue(cliente_logged());
        $this->assertSame($this->clienteId, require_cliente_api());
        $this->assertNotNull(q_val('SELECT ultimo_login FROM usuarios_cliente WHERE id = ?', [$this->usuarioId]));
    }

    public function test_el_email_se_normaliza_al_ingresar(): void
    {
        $this->assertSame('ok', cliente_auth_attempt('  Ana@Sol.COM ', 'secreta1', '1.1.1.1'));
    }

    public function test_clave_incorrecta_email_inexistente_sin_activar_o_desactivado_son_invalidos(): void
    {
        $this->crearUsuarioCliente($this->crearCliente('B'), 'sin@x.com', null);
        $this->crearUsuarioCliente($this->crearCliente('C'), 'off@x.com', 'secreta1', false);
        $this->assertSame('invalido', cliente_auth_attempt('ana@sol.com', 'otra-clave', '1.1.1.1'));
        $this->assertSame('invalido', cliente_auth_attempt('nadie@x.com', 'secreta1', '1.1.1.1'));
        $this->assertSame('invalido', cliente_auth_attempt('sin@x.com', '', '1.1.1.1'));
        $this->assertSame('invalido', cliente_auth_attempt('off@x.com', 'secreta1', '1.1.1.1'));
        $this->assertFalse(cliente_logged());
    }

    public function test_la_clave_se_respeta_tal_cual_sin_recortar_espacios(): void
    {
        $this->crearUsuarioCliente($this->crearCliente('D'), 'esp@x.com', ' abc123 ');
        $this->assertSame('invalido', cliente_auth_attempt('esp@x.com', 'abc123', '1.1.1.1'));
        $this->assertSame('ok', cliente_auth_attempt('esp@x.com', ' abc123 ', '1.1.1.1'));
    }

    public function test_cinco_fallos_desde_una_ip_bloquean_aunque_la_clave_sea_correcta(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame('invalido', cliente_auth_attempt("otro$i@x.com", 'mal', '2.2.2.2'));
        }
        $this->assertSame('bloqueado', cliente_auth_attempt('ana@sol.com', 'secreta1', '2.2.2.2'));
        $this->assertFalse(cliente_logged());
        $this->assertSame('ok', cliente_auth_attempt('ana@sol.com', 'secreta1', '3.3.3.3'));
    }

    public function test_cinco_fallos_contra_una_cuenta_la_bloquean_desde_cualquier_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            cliente_auth_attempt('ana@sol.com', 'mal', "10.0.0.$i");
        }
        $this->assertSame('bloqueado', cliente_auth_attempt('ana@sol.com', 'secreta1', '10.0.0.99'));
        $this->crearUsuarioCliente($this->crearCliente('E'), 'otra@x.com', 'secreta1');
        $this->assertSame('ok', cliente_auth_attempt('otra@x.com', 'secreta1', '10.0.0.99'));
    }

    public function test_los_intentos_de_hace_mas_de_15_minutos_no_cuentan(): void
    {
        for ($i = 0; $i < 5; $i++) {
            db()->exec("INSERT INTO login_intentos (ip, ambito, clave, creado_en) VALUES ('4.4.4.4', 'cliente', 'ana@sol.com', NOW() - INTERVAL 16 MINUTE)");
        }
        $this->assertSame('ok', cliente_auth_attempt('ana@sol.com', 'secreta1', '4.4.4.4'));
    }

    public function test_el_login_exitoso_borra_los_fallos_de_esa_cuenta(): void
    {
        cliente_auth_attempt('ana@sol.com', 'mal', '5.5.5.5');
        cliente_auth_attempt('ana@sol.com', 'mal', '5.5.5.5');
        cliente_auth_attempt('ana@sol.com', 'secreta1', '5.5.5.5');
        $this->assertSame(0, (int)q_val("SELECT COUNT(*) FROM login_intentos WHERE ambito = 'cliente' AND clave = 'ana@sol.com'"));
    }

    public function test_los_intentos_de_cliente_y_de_admin_no_se_mezclan(): void
    {
        for ($i = 0; $i < 5; $i++) {
            db()->exec("INSERT INTO login_intentos (ip) VALUES ('6.6.6.6')");
        }
        $this->assertSame('ok', cliente_auth_attempt('ana@sol.com', 'secreta1', '6.6.6.6'));
        $this->assertSame(5, (int)q_val("SELECT COUNT(*) FROM login_intentos WHERE ip = '6.6.6.6' AND ambito = 'admin'"));
    }

    public function test_las_sesiones_de_admin_y_de_cliente_no_se_cruzan(): void
    {
        $_SESSION['cliente'] = ['usuario_id' => $this->usuarioId];
        $this->assertFalse(auth_logged());
        $this->assertHttp(401, fn() => require_admin_api());

        $_SESSION = ['admin' => true];
        $this->assertFalse(cliente_logged());
        $this->assertNull(cliente_actual());
        $this->assertHttp(401, fn() => require_cliente_api());
    }

    public function test_sin_sesion_el_guard_de_api_da_401(): void
    {
        $this->assertHttp(401, fn() => require_cliente_api());
    }

    public function test_desactivar_el_acceso_corta_la_sesion_abierta(): void
    {
        cliente_auth_attempt('ana@sol.com', 'secreta1', '7.7.7.7');
        $this->assertSame($this->clienteId, require_cliente_api());
        db()->prepare('UPDATE usuarios_cliente SET activo = 0 WHERE id = ?')->execute([$this->usuarioId]);
        $this->assertHttp(401, fn() => require_cliente_api());
        $this->assertFalse(cliente_logged());
    }

    public function test_logout_limpia_la_sesion(): void
    {
        cliente_auth_attempt('ana@sol.com', 'secreta1', '8.8.8.8');
        cliente_logout();
        $this->assertFalse(cliente_logged());
    }

    public function test_la_clave_tiene_que_tener_al_menos_6_caracteres(): void
    {
        $campos = $this->errores422(fn() => cliente_validar_clave('12345'));
        $this->assertArrayHasKey('clave', $campos);
        cliente_validar_clave('123456');
        cliente_validar_clave(str_repeat('a', 72));
        $this->addToAssertionCount(2);
    }

    public function test_una_clave_de_mas_de_72_bytes_se_rechaza_sin_truncar(): void
    {
        $campos = $this->errores422(fn() => cliente_validar_clave(str_repeat('a', 73)));
        $this->assertStringContainsString('72', $campos['clave']);
    }
}
