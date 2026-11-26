<?php
declare(strict_types=1);

final class PagosInformadosTest extends DbTestCase
{
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private string $dir;
    private int $cliente;
    private array $plan;
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();
        clock_set('2026-12-03');
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vezza_up_' . bin2hex(random_bytes(4));
        env_set('UPLOADS_DIR', $this->dir);
        env_set('TRANSFERENCIA_ALIAS', 'vezza.dev.mp');
        env_set('TRANSFERENCIA_TITULAR', 'Valentino Mezzavilla');
        env_set('MAIL_WEBHOOK_URL', 'https://n8n.test/mail');
        $this->mails = [];
        Mail::$transporte = function (string $url, string $secreto, array $payload): bool {
            $this->mails[] = $payload;
            return true;
        };
        $this->cliente = clientes_create(['nombre' => 'Pescadería Valle Viejo', 'email' => 'pesca@sol.com'])['id'];
        $this->plan = planes_create(['nombre' => 'Mantenimiento', 'monto' => '45000', 'moneda' => 'ARS', 'frecuencia' => 'mensual']);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
        Mail::$transporte = null;
        foreach (['UPLOADS_DIR', 'TRANSFERENCIA_ALIAS', 'TRANSFERENCIA_TITULAR', 'MAIL_WEBHOOK_URL'] as $k) {
            env_set($k, null);
        }
        parent::tearDown();
    }

    private function susc(int $cliente = 0): array
    {
        return suscripciones_cliente_create(['cliente_id' => $cliente ?: $this->cliente, 'plan_id' => $this->plan['id'], 'fecha_proximo_cobro' => '2026-12-05']);
    }

    private function archivo(string $contenido = ''): array
    {
        $ruta = tempnam(sys_get_temp_dir(), 'vz');
        file_put_contents($ruta, $contenido !== '' ? $contenido : base64_decode(self::PNG_1X1));
        return ['tmp' => $ruta, 'bytes' => filesize($ruta)];
    }

    private function informar(int $suscId, array $input = ['fecha' => '2026-12-02'], ?array $archivo = null): int
    {
        return portal_informar_transferencia($this->cliente, $suscId, $input, $archivo ?? $this->archivo());
    }

    // ---- el cliente informa ----

    public function test_informar_deja_un_aviso_pendiente_y_no_registra_nada(): void
    {
        $s = $this->susc();
        $id = $this->informar($s['id'], ['fecha' => '2026-12-02', 'nota' => 'Desde Galicia']);
        $p = q_one('SELECT * FROM pagos_informados WHERE id = ?', [$id]);
        $this->assertSame('pendiente', $p['estado']);
        $this->assertSame('45000.00', $p['monto'], 'Sin monto, el del plan');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}\.png$/', $p['archivo']);
        $this->assertSame([], q_all('SELECT id FROM cobros WHERE suscripcion_cliente_id = ?', [$s['id']]));
        $this->assertSame('2026-12-05', suscripciones_cliente_get($s['id'])['fecha_proximo_cobro']);
        $this->assertSame('2026-12-02', portal_suscripcion($this->cliente, $s['id'])['transferencia_pendiente']);
    }

    public function test_valida_fecha_archivo_y_monto(): void
    {
        $s = $this->susc();
        $this->assertArrayHasKey('fecha', $this->errores422(fn() => $this->informar($s['id'], [])));
        $this->assertArrayHasKey('fecha', $this->errores422(fn() => $this->informar($s['id'], ['fecha' => '2026-12-04'])));
        $this->assertArrayHasKey('monto', $this->errores422(fn() => $this->informar($s['id'], ['fecha' => '2026-12-02', 'monto' => '0'])));
        $this->assertArrayHasKey('archivo', $this->errores422(fn() => portal_informar_transferencia($this->cliente, $s['id'], ['fecha' => '2026-12-02'], null)));
        $this->assertArrayHasKey('archivo', $this->errores422(fn() => $this->informar($s['id'], ['fecha' => '2026-12-02'], $this->archivo('<?php echo 1;'))));
    }

    public function test_una_suscripcion_ajena_da_404(): void
    {
        $ajena = $this->susc(clientes_create(['nombre' => 'Otro'])['id']);
        $this->assertHttp(404, fn() => $this->informar($ajena['id']));
    }

    public function test_sin_datos_de_la_cuenta_no_se_ofrece(): void
    {
        env_set('TRANSFERENCIA_ALIAS', null);
        $s = $this->susc();
        $this->assertHttp(409, fn() => $this->informar($s['id']));
        $this->assertStringNotContainsString('/clientes/transferencia', portal_acciones_pago(portal_suscripcion($this->cliente, $s['id'])));
    }

    public function test_el_boton_aparece_con_los_datos_cargados(): void
    {
        $s = $this->susc();
        $this->assertStringContainsString('/clientes/transferencia?s=' . $s['id'], portal_acciones_pago(portal_suscripcion($this->cliente, $s['id'])));
    }

    public function test_limita_los_avisos_por_dia(): void
    {
        $s = $this->susc();
        for ($i = 0; $i < PORTAL_INFORMES_POR_DIA; $i++) {
            $this->informar($s['id']);
        }
        $this->assertHttp(429, fn() => $this->informar($s['id']));
    }

    // ---- el admin confirma o rechaza ----

    public function test_confirmar_registra_el_pago_con_la_fecha_informada_y_manda_el_comprobante(): void
    {
        $s = $this->susc();
        $id = $this->informar($s['id'], ['fecha' => '2026-12-02', 'monto' => '45000']);
        $this->assertCount(1, pagos_informados_list([]));

        $r = pago_informado_confirmar($id);
        $this->assertSame('enviado', $r['aviso']);
        $this->assertSame('2026-12-02', $r['cobro']['fecha_pago']);
        $this->assertSame('Transferencia', $r['cobro']['metodo_pago']);
        $this->assertTrue($r['cobro']['tiene_archivo'], 'El archivo del cliente queda como comprobante del cobro');
        $this->assertNotNull($r['cobro']['comprobante_pago']);
        $this->assertSame('2027-01-05', suscripciones_cliente_get($s['id'])['fecha_proximo_cobro']);
        $this->assertSame(['comprobante_pago'], array_column($this->mails, 'plantilla'));
        $this->assertSame([], pagos_informados_list([]));
        $this->assertSame((int)$r['cobro']['id'], (int)q_val('SELECT cobro_id FROM pagos_informados WHERE id = ?', [$id]));
    }

    public function test_confirmar_dos_veces_no_registra_dos_pagos(): void
    {
        $s = $this->susc();
        $id = $this->informar($s['id']);
        pago_informado_confirmar($id);
        $this->assertHttp(409, fn() => pago_informado_confirmar($id));
        $this->assertHttp(409, fn() => pago_informado_rechazar($id, ['motivo' => 'x']));
        $this->assertCount(1, q_all('SELECT id FROM cobros WHERE suscripcion_cliente_id = ?', [$s['id']]));
    }

    public function test_rechazar_avisa_al_cliente_y_no_toca_la_suscripcion(): void
    {
        $s = $this->susc();
        $id = $this->informar($s['id']);
        $this->assertArrayHasKey('motivo', $this->errores422(fn() => pago_informado_rechazar($id, [])));
        $this->assertSame(['aviso' => 'enviado'], pago_informado_rechazar($id, ['motivo' => 'El comprobante no corresponde']));
        $this->assertSame('pago_rechazado', $this->mails[0]['plantilla']);
        $this->assertSame(['nombre' => 'Pescadería Valle Viejo', 'monto' => '$45.000', 'fecha' => '2/12/2026', 'motivo' => 'El comprobante no corresponde'], $this->mails[0]['datos']);
        $this->assertSame('2026-12-05', suscripciones_cliente_get($s['id'])['fecha_proximo_cobro']);
        $this->assertSame('rechazado', pagos_informados_list(['estado' => 'rechazado'])[0]['estado']);
        $this->assertNull(portal_suscripcion($this->cliente, $s['id'])['transferencia_pendiente']);
    }

    public function test_la_lista_filtra_por_cliente_y_no_expone_el_nombre_del_archivo(): void
    {
        $this->informar($this->susc()['id']);
        $otro = clientes_create(['nombre' => 'Otro'])['id'];
        $this->assertSame([], pagos_informados_list(['cliente_id' => (string)$otro]));
        $p = pagos_informados_list(['cliente_id' => (string)$this->cliente])[0];
        $this->assertTrue($p['tiene_archivo']);
        $this->assertArrayNotHasKey('archivo', $p);
        [$ruta] = pago_informado_archivo((int)$p['id']);
        $this->assertFileExists($ruta);
    }
}
