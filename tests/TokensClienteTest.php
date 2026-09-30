<?php
declare(strict_types=1);

final class TokensClienteTest extends DbTestCase
{
    private int $usuarioId;
    /** @var array<int, array> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->usuarioId = $this->crearUsuarioCliente($this->crearCliente(), 'ana@sol.com', null);
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

    private function minutosHastaVencer(): int
    {
        return (int)q_val('SELECT TIMESTAMPDIFF(MINUTE, NOW(), token_expira) FROM usuarios_cliente WHERE id = ?', [$this->usuarioId]);
    }

    public function test_emitir_devuelve_64_hex_y_guarda_solo_el_hash(): void
    {
        $t = cliente_token_emitir($this->usuarioId, 'invitacion');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $t);
        $fila = q_one('SELECT * FROM usuarios_cliente WHERE id = ?', [$this->usuarioId]);
        $this->assertSame(hash('sha256', $t), $fila['token_hash']);
        $this->assertNotSame($t, $fila['token_hash']);
        $this->assertSame('invitacion', $fila['token_tipo']);
    }

    public function test_la_invitacion_vence_a_las_72_horas_y_la_recuperacion_a_las_2(): void
    {
        cliente_token_emitir($this->usuarioId, 'invitacion');
        $this->assertGreaterThanOrEqual(72 * 60 - 2, $this->minutosHastaVencer());
        $this->assertLessThanOrEqual(72 * 60, $this->minutosHastaVencer());
        cliente_token_emitir($this->usuarioId, 'recuperacion');
        $this->assertGreaterThanOrEqual(2 * 60 - 2, $this->minutosHastaVencer());
        $this->assertLessThanOrEqual(2 * 60, $this->minutosHastaVencer());
    }

    public function test_tipo_de_token_desconocido_es_un_error_de_programacion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        cliente_token_emitir($this->usuarioId, 'otro');
    }

    public function test_consumir_fija_la_clave_y_el_token_no_sirve_de_nuevo(): void
    {
        $t = cliente_token_emitir($this->usuarioId, 'invitacion');
        $this->assertTrue(cliente_token_valido($t, 'invitacion'));
        $this->assertSame($this->usuarioId, cliente_token_consumir($t, 'invitacion', 'nueva123'));
        $hash = (string)q_val('SELECT password_hash FROM usuarios_cliente WHERE id = ?', [$this->usuarioId]);
        $this->assertTrue(password_verify('nueva123', $hash));
        $this->assertSame('ok', cliente_auth_attempt('ana@sol.com', 'nueva123', '1.1.1.1'));
        $this->assertFalse(cliente_token_valido($t, 'invitacion'));
        $this->assertHttp(410, fn() => cliente_token_consumir($t, 'invitacion', 'otra-clave'));
    }

    public function test_las_claves_nuevas_se_guardan_con_el_costo_fijo(): void
    {
        $t = cliente_token_emitir($this->usuarioId, 'invitacion');
        cliente_token_consumir($t, 'invitacion', 'nueva123');
        $hash = (string)q_val('SELECT password_hash FROM usuarios_cliente WHERE id = ?', [$this->usuarioId]);
        $this->assertSame(CLIENTE_BCRYPT_COST, password_get_info($hash)['options']['cost']);
    }

    public function test_activar_respeta_los_espacios_de_la_clave(): void
    {
        $t = cliente_token_emitir($this->usuarioId, 'invitacion');
        cliente_token_consumir($t, 'invitacion', '  clave con espacios  ');
        $this->assertSame('invalido', cliente_auth_attempt('ana@sol.com', 'clave con espacios', '1.1.1.1'));
        $this->assertSame('ok', cliente_auth_attempt('ana@sol.com', '  clave con espacios  ', '1.1.1.1'));
    }

    public function test_un_token_vencido_da_410(): void
    {
        $t = cliente_token_emitir($this->usuarioId, 'invitacion');
        db()->prepare('UPDATE usuarios_cliente SET token_expira = NOW() - INTERVAL 1 MINUTE WHERE id = ?')->execute([$this->usuarioId]);
        $this->assertFalse(cliente_token_valido($t, 'invitacion'));
        $this->assertHttp(410, fn() => cliente_token_consumir($t, 'invitacion', 'nueva123'));
    }

    public function test_un_token_de_otro_tipo_no_sirve(): void
    {
        $t = cliente_token_emitir($this->usuarioId, 'invitacion');
        $this->assertFalse(cliente_token_valido($t, 'recuperacion'));
        $this->assertHttp(410, fn() => cliente_token_consumir($t, 'recuperacion', 'nueva123'));
    }

    public function test_una_invitacion_nueva_invalida_la_anterior(): void
    {
        $viejo = cliente_token_emitir($this->usuarioId, 'invitacion');
        $nuevo = cliente_token_emitir($this->usuarioId, 'invitacion');
        $this->assertFalse(cliente_token_valido($viejo, 'invitacion'));
        $this->assertTrue(cliente_token_valido($nuevo, 'invitacion'));
    }

    public function test_una_clave_corta_da_422_y_no_gasta_el_token(): void
    {
        $t = cliente_token_emitir($this->usuarioId, 'invitacion');
        $campos = $this->errores422(fn() => cliente_token_consumir($t, 'invitacion', '12345'));
        $this->assertArrayHasKey('clave', $campos);
        $this->assertTrue(cliente_token_valido($t, 'invitacion'));
        cliente_token_consumir($t, 'invitacion', '123456');
        $this->assertFalse(cliente_token_valido($t, 'invitacion'));
    }

    public function test_los_tokens_mal_formados_son_invalidos_y_dan_410_no_500(): void
    {
        cliente_token_emitir($this->usuarioId, 'invitacion');
        foreach (['', 'abc', '  ', str_repeat('z', 64), str_repeat('A', 64), '../../etc/passwd', str_repeat('a', 65)] as $malo) {
            $this->assertFalse(cliente_token_valido($malo, 'invitacion'), 'Aceptó: ' . var_export($malo, true));
            $this->assertHttp(410, fn() => cliente_token_consumir($malo, 'invitacion', 'nueva123'));
        }
    }

    public function test_un_usuario_desactivado_no_puede_usar_su_token(): void
    {
        $t = cliente_token_emitir($this->usuarioId, 'invitacion');
        db()->prepare('UPDATE usuarios_cliente SET activo = 0 WHERE id = ?')->execute([$this->usuarioId]);
        $this->assertFalse(cliente_token_valido($t, 'invitacion'));
    }

    public function test_cambiar_la_clave_limpia_los_fallos_de_login_de_la_cuenta(): void
    {
        db()->exec("INSERT INTO login_intentos (ip, ambito, clave) VALUES ('1.1.1.1', 'cliente', 'ana@sol.com')");
        $t = cliente_token_emitir($this->usuarioId, 'invitacion');
        cliente_token_consumir($t, 'invitacion', 'nueva123');
        $this->assertSame(0, (int)q_val("SELECT COUNT(*) FROM login_intentos WHERE ambito = 'cliente' AND clave = 'ana@sol.com'"));
    }

    public function test_recuperar_manda_el_mail_solo_a_cuentas_activadas_y_activas(): void
    {
        $this->crearUsuarioCliente($this->crearCliente('B'), 'act@x.com', 'secreta1');
        cliente_recuperar_solicitar('  ACT@x.com ', '1.1.1.1');
        $this->assertCount(1, $this->mails);
        $this->assertSame('recuperacion', $this->mails[0]['plantilla']);
        $this->assertSame('act@x.com', $this->mails[0]['destino']);
        $link = $this->mails[0]['datos']['link'];
        $this->assertStringStartsWith('https://portal.test/clientes/recuperar?token=', $link);
        parse_str((string)parse_url($link, PHP_URL_QUERY), $q);
        $this->assertTrue(cliente_token_valido($q['token'], 'recuperacion'));
    }

    public function test_recuperar_no_hace_nada_ni_revela_nada_si_la_cuenta_no_califica(): void
    {
        $this->crearUsuarioCliente($this->crearCliente('B'), 'off@x.com', 'secreta1', false);
        cliente_recuperar_solicitar('nadie@x.com', '1.1.1.1');
        cliente_recuperar_solicitar('ana@sol.com', '1.1.1.1');
        cliente_recuperar_solicitar('off@x.com', '1.1.1.1');
        $this->assertSame([], $this->mails);
    }

    public function test_recuperar_se_limita_a_5_pedidos_por_ip_sin_avisar(): void
    {
        $this->crearUsuarioCliente($this->crearCliente('B'), 'act@x.com', 'secreta1');
        for ($i = 0; $i < 7; $i++) {
            cliente_recuperar_solicitar('act@x.com', '9.9.9.9');
        }
        $this->assertCount(5, $this->mails);
    }
}
