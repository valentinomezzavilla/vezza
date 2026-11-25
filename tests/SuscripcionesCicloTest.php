<?php
declare(strict_types=1);

final class SuscripcionesCicloTest extends DbTestCase
{
    private int $cliente;
    private array $plan;
    /** @var list<array{0: string, 1: string, 2: ?array}> Lo que se le mandó a Mercado Pago: (método, ruta, cuerpo). */
    private array $llamadas = [];
    /** @var list<array{plantilla: string, destino: string, datos: array}> */
    private array $mails = [];
    private bool $mpCae = false;
    private bool $mailOk = true;
    private array $mpRespuesta = ['id' => 'pre-1', 'init_point' => 'https://mp.test/pre-1'];

    protected function setUp(): void
    {
        parent::setUp();
        clock_set('2026-03-10');
        env_set('MAIL_WEBHOOK_URL', 'https://n8n.test/hook');
        env_set('PORTAL_URL', 'https://demo.test');
        $this->cliente = clientes_create(['nombre' => 'Acme', 'email' => 'acme@sol.com'])['id'];
        $this->plan = planes_create(['nombre' => 'Mantenimiento', 'monto' => '25000', 'moneda' => 'ARS', 'frecuencia' => 'mensual']);
        MercadoPago::$transporte = function (string $metodo, string $ruta, ?array $cuerpo): array {
            $this->llamadas[] = [$metodo, $ruta, $cuerpo];
            if ($this->mpCae) {
                throw new RuntimeException('Mercado Pago no respondió');
            }
            return $this->mpRespuesta;
        };
        Mail::$transporte = function (string $url, string $secreto, array $payload): bool {
            $this->mails[] = $payload;
            return $this->mailOk;
        };
    }

    protected function tearDown(): void
    {
        MercadoPago::$transporte = null;
        Mail::$transporte = null;
        env_set('MAIL_WEBHOOK_URL', null);
        env_set('PORTAL_URL', null);
        env_set('MP_ACCESS_TOKEN', null);
        parent::tearDown();
    }

    private function susc(array $extra = []): array
    {
        return suscripciones_cliente_create($extra + [
            'cliente_id' => $this->cliente, 'plan_id' => $this->plan['id'], 'fecha_proximo_cobro' => '2026-04-01',
        ]);
    }

    /** Una suscripción que ya tiene su débito en Mercado Pago en el estado dado. */
    private function conDebito(string $estado = 'autorizada', array $extra = [], string $preapproval = 'pre-1'): array
    {
        $s = $this->susc($extra);
        $cambios = ['mp_preapproval_id' => $preapproval, 'mp_preapproval_estado' => $estado, 'mp_init_point' => "https://mp.test/$preapproval"];
        if (in_array($estado, ['autorizada', 'pausada'], true)) {
            $cambios['modalidad'] = 'debito';
        }
        crud_update('suscripciones_cliente', $s['id'], $cambios);
        return suscripciones_cliente_get($s['id']);
    }

    private function fila(int $id): array
    {
        return q_one('SELECT * FROM suscripciones_cliente WHERE id = ?', [$id]);
    }

    // ---- fecha_larga_es ----

    public function test_fecha_larga_en_espanol(): void
    {
        $this->assertSame('25 de noviembre de 2026', fecha_larga_es('2026-11-25'));
        $this->assertSame('1 de enero de 2027', fecha_larga_es('2027-01-01'));
    }

    // ---- pasar a débito ----

    public function test_pasar_a_debito_crea_el_preapproval_con_inicio_y_no_cambia_la_modalidad(): void
    {
        $s = $this->susc(['fecha_proximo_cobro' => '2026-04-01']);

        $r = suscripcion_pasar_a_debito($s['id']);

        $this->assertCount(1, $this->llamadas);
        [$metodo, $ruta, $cuerpo] = $this->llamadas[0];
        $this->assertSame(['POST', '/preapproval'], [$metodo, $ruta]);
        $this->assertSame('2026-04-01T12:00:00.000-03:00', $cuerpo['auto_recurring']['start_date']);
        $this->assertSame(25000.0, $cuerpo['auto_recurring']['transaction_amount']);
        $this->assertSame('acme@sol.com', $cuerpo['payer_email']);
        $this->assertSame('susc-' . $s['id'], $cuerpo['external_reference']);
        $this->assertSame('Mantenimiento', $cuerpo['reason']);
        $this->assertSame('pre-1', $r['mp_preapproval_id']);
        $this->assertSame('https://mp.test/pre-1', $r['mp_init_point']);
        $this->assertSame('pendiente', $r['mp_preapproval_estado']);
        $this->assertSame('transferencia', $r['modalidad']);
    }

    public function test_el_inicio_solo_se_manda_si_es_futuro(): void
    {
        $vencida = $this->susc(['fecha_proximo_cobro' => '2026-03-05']);
        suscripcion_pasar_a_debito($vencida['id']);
        $this->assertArrayNotHasKey('start_date', $this->llamadas[0][2]['auto_recurring']);

        $this->llamadas = [];
        $this->mpRespuesta = ['id' => 'pre-2', 'init_point' => 'https://mp.test/pre-2'];
        $otra = $this->susc(['fecha_proximo_cobro' => '2026-04-01']);
        suscripcion_pasar_a_debito($otra['id'], '2026-05-01');
        $this->assertSame('2026-05-01T12:00:00.000-03:00', $this->llamadas[0][2]['auto_recurring']['start_date']);

        $this->llamadas = [];
        $this->mpRespuesta = ['id' => 'pre-3', 'init_point' => 'https://mp.test/pre-3'];
        $pasada = $this->susc(['fecha_proximo_cobro' => '2026-04-01']);
        suscripcion_pasar_a_debito($pasada['id'], '2026-03-01');
        $this->assertArrayNotHasKey('start_date', $this->llamadas[0][2]['auto_recurring']);
    }

    public function test_una_fecha_de_inicio_invalida_da_422(): void
    {
        $s = $this->susc();
        $this->assertArrayHasKey('inicio', $this->errores422(fn() => suscripcion_pasar_a_debito($s['id'], '2026-13-45')));
        $this->assertSame([], $this->llamadas);
    }

    public function test_pasar_a_debito_en_usd_o_sin_mail_da_422_sin_llamar_a_mercado_pago(): void
    {
        $usd = planes_create(['nombre' => 'Dólares', 'monto' => '100', 'moneda' => 'USD', 'frecuencia' => 'mensual']);
        $enUsd = $this->susc(['plan_id' => $usd['id']]);
        $this->assertArrayHasKey('modalidad', $this->errores422(fn() => suscripcion_pasar_a_debito($enUsd['id'])));

        $sinMail = clientes_create(['nombre' => 'Sin mail'])['id'];
        $s = $this->susc(['cliente_id' => $sinMail]);
        $this->assertArrayHasKey('modalidad', $this->errores422(fn() => suscripcion_pasar_a_debito($s['id'])));

        $this->assertSame([], $this->llamadas);
    }

    public function test_con_un_debito_en_curso_da_409(): void
    {
        foreach (['pendiente', 'autorizada', 'pausada'] as $estado) {
            $s = $this->conDebito($estado, [], "pre-$estado");
            $this->assertHttp(409, fn() => suscripcion_pasar_a_debito($s['id']));
        }
        $this->assertSame([], $this->llamadas);
    }

    public function test_pasar_a_debito_una_suscripcion_cancelada_da_409(): void
    {
        $s = $this->susc();
        crud_update('suscripciones_cliente', $s['id'], ['estado' => 'cancelada', 'fecha_baja' => '2026-03-01']);
        $this->assertHttp(409, fn() => suscripcion_pasar_a_debito($s['id']));
    }

    public function test_sin_mercado_pago_configurado_da_409(): void
    {
        MercadoPago::$transporte = null;
        env_set('MP_ACCESS_TOKEN', null);
        $s = $this->susc();
        $this->assertHttp(409, fn() => suscripcion_pasar_a_debito($s['id']));
        $this->assertNull($this->fila($s['id'])['mp_preapproval_id']);
    }

    public function test_si_mercado_pago_falla_al_pasar_a_debito_no_se_guarda_nada(): void
    {
        $s = $this->susc();
        $this->mpCae = true;

        $this->assertHttp(502, fn() => suscripcion_pasar_a_debito($s['id']));

        $fila = $this->fila($s['id']);
        $this->assertNull($fila['mp_preapproval_id']);
        $this->assertNull($fila['mp_preapproval_estado']);
        $this->assertNull($fila['mp_init_point']);
    }

    // ---- pausar y reanudar ----

    public function test_pausar_y_reanudar_sincronizan_el_preapproval(): void
    {
        $s = $this->conDebito('autorizada');

        $pausada = suscripcion_cliente_pausar($s['id'], true);
        $this->assertSame(['PUT', '/preapproval/pre-1', ['status' => 'paused']], $this->llamadas[0]);
        $this->assertSame(1, (int)$pausada['pausada']);
        $this->assertSame('pausada', $pausada['mp_preapproval_estado']);

        $reanudada = suscripcion_cliente_pausar($s['id'], false);
        $this->assertSame(['PUT', '/preapproval/pre-1', ['status' => 'authorized']], $this->llamadas[1]);
        $this->assertSame(0, (int)$reanudada['pausada']);
        $this->assertSame('autorizada', $reanudada['mp_preapproval_estado']);
    }

    public function test_pausar_sin_debito_solo_cambia_la_marca_local(): void
    {
        $s = $this->susc();
        $this->assertSame(1, (int)suscripcion_cliente_pausar($s['id'], true)['pausada']);
        $this->assertSame([], $this->llamadas);
    }

    public function test_si_mercado_pago_falla_al_pausar_no_cambia_el_estado_local(): void
    {
        $s = $this->conDebito('autorizada');
        $this->mpCae = true;

        $this->assertHttp(502, fn() => suscripcion_cliente_pausar($s['id'], true));

        $fila = $this->fila($s['id']);
        $this->assertSame(0, (int)$fila['pausada']);
        $this->assertSame('autorizada', $fila['mp_preapproval_estado']);
    }

    public function test_pausar_una_suscripcion_cancelada_da_409(): void
    {
        $s = $this->susc();
        crud_update('suscripciones_cliente', $s['id'], ['estado' => 'cancelada', 'fecha_baja' => '2026-03-01']);
        $this->assertHttp(409, fn() => suscripcion_cliente_pausar($s['id'], true));
    }

    // ---- cancelar ----

    public function test_cancelar_cancela_en_mercado_pago_marca_la_baja_y_vuelve_a_transferencia(): void
    {
        $s = $this->conDebito('autorizada');

        $r = suscripcion_cliente_cancelar($s['id']);

        $this->assertSame(['PUT', '/preapproval/pre-1', ['status' => 'cancelled']], $this->llamadas[0]);
        $this->assertSame('cancelada', $r['suscripcion']['estado']);
        $this->assertSame('2026-03-10', $r['suscripcion']['fecha_baja']);
        $this->assertSame('transferencia', $r['suscripcion']['modalidad']);
        $this->assertSame('cancelada', $r['suscripcion']['mp_preapproval_estado']);
        $this->assertSame(0, (int)$r['suscripcion']['pausada']);
        $this->assertSame('enviado', $r['aviso']);
        $this->assertCount(1, $this->mails);
        $this->assertSame('suscripcion_cancelada', $this->mails[0]['plantilla']);
        $this->assertSame('acme@sol.com', $this->mails[0]['destino']);
        $this->assertSame(
            ['nombre' => 'Acme', 'plan' => 'Mantenimiento', 'motivo' => 'baja', 'acceso_hasta' => '1 de abril de 2026', 'vencida' => false],
            $this->mails[0]['datos']
        );
    }

    public function test_cancelar_sin_debito_no_llama_a_mercado_pago(): void
    {
        $s = $this->susc();
        $r = suscripcion_cliente_cancelar($s['id']);
        $this->assertSame([], $this->llamadas);
        $this->assertSame('cancelada', $r['suscripcion']['estado']);
        $this->assertNull($r['suscripcion']['mp_preapproval_estado']);
    }

    public function test_si_mercado_pago_falla_al_cancelar_no_cambia_nada(): void
    {
        $s = $this->conDebito('autorizada');
        $this->mpCae = true;

        $this->assertHttp(502, fn() => suscripcion_cliente_cancelar($s['id']));

        $fila = $this->fila($s['id']);
        $this->assertSame('activa', $fila['estado']);
        $this->assertSame('debito', $fila['modalidad']);
        $this->assertSame('autorizada', $fila['mp_preapproval_estado']);
        $this->assertNull($fila['fecha_baja']);
        $this->assertSame([], $this->mails);
    }

    public function test_cancelar_dos_veces_da_409(): void
    {
        $s = $this->susc();
        suscripcion_cliente_cancelar($s['id']);
        $this->assertHttp(409, fn() => suscripcion_cliente_cancelar($s['id']));
        $this->assertCount(1, $this->mails);
    }

    public function test_el_aviso_de_baja_sale_una_sola_vez(): void
    {
        $s = $this->susc();
        $this->assertSame('enviado', suscripcion_avisar_baja($s['id'], 'debito'));
        $this->assertSame('ya_enviado', suscripcion_avisar_baja($s['id'], 'debito'));
        $this->assertCount(1, $this->mails);
        $this->assertSame('debito', $this->mails[0]['datos']['motivo']);
    }

    public function test_si_el_mail_falla_se_libera_el_reclamo_y_se_puede_reintentar(): void
    {
        $s = $this->susc();
        $this->mailOk = false;
        $this->assertSame('no_enviado', suscripcion_avisar_baja($s['id'], 'baja'));
        $this->assertNull($this->fila($s['id'])['aviso_baja_enviado_en']);

        $this->mailOk = true;
        $this->assertSame('enviado', suscripcion_avisar_baja($s['id'], 'baja'));
        $this->assertNotNull($this->fila($s['id'])['aviso_baja_enviado_en']);
    }

    public function test_un_cliente_sin_mail_cancela_igual_y_no_reclama_el_aviso(): void
    {
        $sinMail = clientes_create(['nombre' => 'Sin mail'])['id'];
        $s = $this->susc(['cliente_id' => $sinMail]);

        $r = suscripcion_cliente_cancelar($s['id']);

        $this->assertSame('cancelada', $r['suscripcion']['estado']);
        $this->assertSame('sin_mail', $r['aviso']);
        $this->assertNull($this->fila($s['id'])['aviso_baja_enviado_en']);
        $this->assertSame([], $this->mails);
    }

    public function test_cancelar_con_el_pago_hasta_vencido_manda_vencida_y_sin_fecha(): void
    {
        $vencida = $this->susc(['fecha_proximo_cobro' => '2026-03-01']);
        suscripcion_cliente_cancelar($vencida['id']);
        $this->assertSame('', $this->mails[0]['datos']['acceso_hasta']);
        $this->assertTrue($this->mails[0]['datos']['vencida']);

        $hoy = $this->susc(['fecha_proximo_cobro' => '2026-03-10']);
        suscripcion_cliente_cancelar($hoy['id']);
        $this->assertSame('10 de marzo de 2026', $this->mails[1]['datos']['acceso_hasta']);
        $this->assertFalse($this->mails[1]['datos']['vencida']);
    }

    // ---- reactivar ----

    public function test_reactivar_sin_debito_vuelve_a_activa_y_limpia_el_aviso(): void
    {
        $s = $this->susc();
        suscripcion_cliente_cancelar($s['id']);
        $this->assertNotNull($this->fila($s['id'])['aviso_baja_enviado_en']);

        $r = suscripcion_cliente_reactivar($s['id']);

        $this->assertSame('activa', $r['estado']);
        $this->assertNull($r['fecha_baja']);
        $this->assertNull($this->fila($s['id'])['aviso_baja_enviado_en']);
        $this->assertSame([], $this->llamadas);
    }

    public function test_reactivar_con_debito_crea_un_preapproval_nuevo_y_queda_pendiente(): void
    {
        $s = $this->conDebito('autorizada');
        suscripcion_cliente_cancelar($s['id']);
        $this->llamadas = [];
        $this->mpRespuesta = ['id' => 'pre-2', 'init_point' => 'https://mp.test/pre-2'];

        $r = suscripcion_cliente_reactivar($s['id']);

        $this->assertSame('POST', $this->llamadas[0][0]);
        $this->assertSame('/preapproval', $this->llamadas[0][1]);
        $this->assertSame('2026-04-01T12:00:00.000-03:00', $this->llamadas[0][2]['auto_recurring']['start_date']);
        $this->assertSame('activa', $r['estado']);
        $this->assertSame('pre-2', $r['mp_preapproval_id']);
        $this->assertSame('https://mp.test/pre-2', $r['mp_init_point']);
        $this->assertSame('pendiente', $r['mp_preapproval_estado']);
        $this->assertSame('transferencia', $r['modalidad']);
    }

    public function test_si_mercado_pago_falla_al_reactivar_con_debito_no_se_reactiva(): void
    {
        $s = $this->conDebito('autorizada');
        suscripcion_cliente_cancelar($s['id']);
        $this->mpCae = true;

        $this->assertHttp(502, fn() => suscripcion_cliente_reactivar($s['id']));

        $this->assertSame('cancelada', $this->fila($s['id'])['estado']);
    }

    public function test_reactivar_una_suscripcion_activa_da_409(): void
    {
        $s = $this->susc();
        $this->assertHttp(409, fn() => suscripcion_cliente_reactivar($s['id']));
    }
}
