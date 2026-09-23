<?php
declare(strict_types=1);

final class AuthTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        env_set('ADMIN_USERNAME', 'valen');
        env_set('ADMIN_PASSWORD_HASH', password_hash('clave-secreta', PASSWORD_BCRYPT, ['cost' => 4]));
    }

    public function test_login_correcto_marca_la_sesion(): void
    {
        $this->assertSame('ok', auth_attempt('valen', 'clave-secreta', '1.1.1.1'));
        $this->assertTrue(auth_logged());
    }

    public function test_usuario_o_clave_incorrectos(): void
    {
        $this->assertSame('invalido', auth_attempt('valen', 'otra', '1.1.1.1'));
        $this->assertSame('invalido', auth_attempt('otro', 'clave-secreta', '1.1.1.1'));
        $this->assertFalse(auth_logged());
    }

    public function test_sexto_intento_queda_bloqueado_aunque_la_clave_sea_correcta(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame('invalido', auth_attempt('valen', 'mal', '2.2.2.2'));
        }
        $this->assertSame('bloqueado', auth_attempt('valen', 'clave-secreta', '2.2.2.2'));
        $this->assertFalse(auth_logged());
        $this->assertSame('ok', auth_attempt('valen', 'clave-secreta', '3.3.3.3'));
    }

    public function test_intentos_de_hace_mas_de_15_minutos_no_cuentan(): void
    {
        for ($i = 0; $i < 5; $i++) {
            db()->exec("INSERT INTO login_intentos (ip, creado_en) VALUES ('4.4.4.4', NOW() - INTERVAL 16 MINUTE)");
        }
        $this->assertSame('ok', auth_attempt('valen', 'clave-secreta', '4.4.4.4'));
    }

    public function test_login_exitoso_borra_los_intentos_de_esa_ip(): void
    {
        auth_attempt('valen', 'mal', '5.5.5.5');
        auth_attempt('valen', 'clave-secreta', '5.5.5.5');
        $this->assertSame(0, (int)q_val("SELECT COUNT(*) FROM login_intentos WHERE ip = '5.5.5.5'"));
    }

    public function test_sin_hash_o_usuario_configurado_nunca_entra(): void
    {
        env_set('ADMIN_PASSWORD_HASH', '');
        $this->assertSame('invalido', auth_attempt('valen', '', '6.6.6.6'));
        env_set('ADMIN_PASSWORD_HASH', password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]));
        env_set('ADMIN_USERNAME', '');
        $this->assertSame('invalido', auth_attempt('', 'x', '6.6.6.6'));
    }

    public function test_safe_next_solo_acepta_rutas_del_panel(): void
    {
        $this->assertSame('/admin/clientes/5', safe_next('/admin/clientes/5'));
        $this->assertSame('/admin/cobros?cliente_id=3', safe_next('/admin/cobros?cliente_id=3'));
        $this->assertSame('/admin', safe_next('/admin'));
        foreach ([null, '', '//evil.com', 'https://evil.com', '/admin-login', '/adminx', '/admin/../x', '/admin//evil.com', '/', "/admin\r\nX: y", "/admin\n"] as $malo) {
            $this->assertSame('/admin', safe_next($malo), 'Aceptó: ' . var_export($malo, true));
        }
    }

    public function test_logout_limpia_la_sesion(): void
    {
        auth_attempt('valen', 'clave-secreta', '7.7.7.7');
        auth_logout();
        $this->assertFalse(auth_logged());
    }

    public function test_require_admin_api_sin_sesion_da_401(): void
    {
        $this->assertHttp(401, fn() => require_admin_api());
        $_SESSION['admin'] = true;
        require_admin_api();
        $this->addToAssertionCount(1);
    }
}
