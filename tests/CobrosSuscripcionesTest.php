<?php
declare(strict_types=1);

final class CobrosSuscripcionesTest extends DbTestCase
{
    private int $cliente;
    private array $plan;
    /** @var list<array{0: string, 1: string, 2: ?array}> */
    private array $llamadas = [];

    protected function setUp(): void
    {
        parent::setUp();
        clock_set('2026-10-01');
        $this->cliente = clientes_create(['nombre' => 'Acme', 'email' => 'acme@sol.com'])['id'];
        $this->plan = planes_create(['nombre' => 'Mantenimiento', 'monto' => '25000', 'moneda' => 'ARS', 'frecuencia' => 'mensual']);
        $this->pasarela();
    }

    protected function tearDown(): void
    {
        MercadoPago::$transporte = null;
        parent::tearDown();
    }

    /** Pasarela falsa: registra las llamadas y devuelve una preferencia con un id distinto por llamada. */
    private function pasarela(): void
    {
        $this->llamadas = [];
        MercadoPago::$transporte = function (string $metodo, string $ruta, ?array $cuerpo): array {
            $this->llamadas[] = [$metodo, $ruta, $cuerpo];
            $n = count($this->llamadas);
            return ['id' => "pref-$n", 'init_point' => "https://mp.test/pagar/pref-$n"];
        };
    }

    private function pasarelaCaida(): void
    {
        $this->llamadas = [];
        MercadoPago::$transporte = function (string $metodo, string $ruta, ?array $cuerpo): array {
            $this->llamadas[] = [$metodo, $ruta, $cuerpo];
            throw new RuntimeException('Mercado Pago no respondió');
        };
    }

    private function susc(string $proximo, array $extra = []): int
    {
        return suscripciones_cliente_create($extra + [
            'cliente_id' => $this->cliente, 'plan_id' => $this->plan['id'], 'fecha_proximo_cobro' => $proximo,
        ])['id'];
    }

    private function cobros(int $suscId): array
    {
        return q_all('SELECT * FROM cobros WHERE suscripcion_cliente_id = ? ORDER BY periodo', [$suscId]);
    }

    private function proximo(int $suscId): string
    {
        return (string)crud_find('suscripciones_cliente', $suscId)['fecha_proximo_cobro'];
    }

    public function test_crea_el_cobro_cuando_faltan_5_dias_o_menos_y_no_antes(): void
    {
        $justo = $this->susc('2026-10-06');
        $lejos = $this->susc('2026-10-07');

        $r = cobros_generar_pendientes();

        $this->assertSame(['creados' => 1, 'errores' => 0], $r);
        $cobros = $this->cobros($justo);
        $this->assertCount(1, $cobros);
        $this->assertSame('2026-10', $cobros[0]['periodo']);
        $this->assertSame('2026-10-06', $cobros[0]['fecha_vencimiento']);
        $this->assertSame('Mantenimiento · octubre 2026', $cobros[0]['concepto']);
        $this->assertSame('pendiente', $cobros[0]['estado']);
        $this->assertNull($cobros[0]['proceso_id']);
        $this->assertSame([], $this->cobros($lejos));
    }

    public function test_acepta_la_fecha_de_hoy_como_argumento(): void
    {
        $id = $this->susc('2026-12-05');

        $this->assertSame(0, cobros_generar_pendientes()['creados']);
        $this->assertSame(1, cobros_generar_pendientes('2026-12-01')['creados']);
        $this->assertCount(1, $this->cobros($id));
    }

    public function test_avanza_fecha_proximo_cobro_un_mes_al_generar(): void
    {
        $id = $this->susc('2026-10-03');

        cobros_generar_pendientes();

        $this->assertSame('2026-11-03', $this->proximo($id));
    }

    public function test_correr_dos_veces_no_duplica(): void
    {
        $id = $this->susc('2026-10-03');

        $a = cobros_generar_pendientes();
        $b = cobros_generar_pendientes();

        $this->assertSame(1, $a['creados'] + $b['creados']);
        $this->assertCount(1, $this->cobros($id));
    }

    public function test_si_el_periodo_ya_tiene_cobro_solo_avanza_la_fecha(): void
    {
        $id = $this->susc('2026-10-03');
        crud_insert('cobros', [
            'cliente_id' => $this->cliente, 'suscripcion_cliente_id' => $id, 'periodo' => '2026-10',
            'concepto' => 'Hecho a mano', 'monto' => '25000', 'moneda' => 'ARS', 'fecha_vencimiento' => '2026-10-03',
        ]);

        $r = cobros_generar_pendientes();

        $this->assertSame(['creados' => 0, 'errores' => 0], $r);
        $this->assertCount(1, $this->cobros($id));
        $this->assertSame('2026-11-03', $this->proximo($id));
    }

    public function test_vencimientos_del_31_caen_en_el_ultimo_dia_del_mes_siguiente(): void
    {
        $enero = $this->susc('2026-01-31');
        $marzo = $this->susc('2026-03-31');

        clock_set('2026-01-27');
        cobros_generar_pendientes();
        $this->assertSame('2026-02-28', $this->proximo($enero));
        $this->assertSame('2026-03-31', $this->proximo($marzo));

        // El mes siguiente de febrero no se saltea (limitación conocida: el día queda en el 28).
        clock_set('2026-02-23');
        cobros_generar_pendientes();
        $this->assertSame('2026-03-28', $this->proximo($enero));
        $this->assertSame('2026-03-31', $this->proximo($marzo));

        clock_set('2026-03-27');
        cobros_generar_pendientes();
        $this->assertSame('2026-04-30', $this->proximo($marzo));
        $this->assertSame(['2026-01', '2026-02', '2026-03'], array_column($this->cobros($enero), 'periodo'));
    }

    public function test_una_suscripcion_pausada_o_cancelada_no_genera(): void
    {
        $pausada = $this->susc('2026-10-03');
        $cancelada = $this->susc('2026-10-03');
        $sinFecha = $this->susc('2026-10-03');
        crud_update('suscripciones_cliente', $pausada, ['pausada' => 1]);
        crud_update('suscripciones_cliente', $cancelada, ['estado' => 'cancelada']);
        db()->prepare('UPDATE suscripciones_cliente SET fecha_proximo_cobro = NULL WHERE id = ?')->execute([$sinFecha]);

        $r = cobros_generar_pendientes();

        $this->assertSame(['creados' => 0, 'errores' => 0], $r);
        $this->assertSame([], $this->cobros($pausada));
        $this->assertSame([], $this->cobros($cancelada));
        $this->assertSame([], $this->cobros($sinFecha));
        $this->assertSame('2026-10-03', $this->proximo($pausada));
    }

    public function test_si_no_se_paga_el_mes_siguiente_genera_su_propio_cobro(): void
    {
        $id = $this->susc('2026-10-03');

        cobros_generar_pendientes();
        clock_set('2026-11-01');
        $r = cobros_generar_pendientes();

        $this->assertSame(1, $r['creados']);
        $cobros = $this->cobros($id);
        $this->assertSame(['2026-10', '2026-11'], array_column($cobros, 'periodo'));
        $this->assertSame(['pendiente', 'pendiente'], array_column($cobros, 'estado'));
        $this->assertSame('Mantenimiento · noviembre 2026', $cobros[1]['concepto']);
        $this->assertSame('2026-12-03', $this->proximo($id));
    }

    public function test_pone_un_tope_de_12_periodos_por_corrida(): void
    {
        $id = $this->susc('2025-01-01');

        $r = cobros_generar_pendientes();

        $this->assertSame(12, $r['creados']);
        $this->assertCount(12, $this->cobros($id));
        $this->assertSame('2026-01-01', $this->proximo($id));
        $this->assertSame('enero 2025', substr((string)$this->cobros($id)[0]['concepto'], strlen('Mantenimiento · ')));
    }

    public function test_modalidad_link_crea_el_link_con_la_pasarela_falsa(): void
    {
        $link = $this->susc('2026-10-03', ['modalidad' => 'link']);
        $transf = $this->susc('2026-10-03', ['modalidad' => 'transferencia']);
        $debito = $this->susc('2026-10-03');
        crud_update('suscripciones_cliente', $debito, ['modalidad' => 'debito']);

        $r = cobros_generar_pendientes();

        $this->assertSame(['creados' => 3, 'errores' => 0], $r);
        $this->assertCount(1, $this->llamadas);
        [$metodo, $ruta, $cuerpo] = $this->llamadas[0];
        $this->assertSame('POST', $metodo);
        $this->assertSame('/checkout/preferences', $ruta);
        $this->assertSame('acme@sol.com', $cuerpo['payer']['email']);
        $cobro = $this->cobros($link)[0];
        $this->assertSame('cobro-' . $cobro['id'], $cuerpo['external_reference']);
        $this->assertSame('https://mp.test/pagar/pref-1', $cobro['link_pago']);
        $this->assertSame('pref-1', $cobro['mp_preference_id']);
        $this->assertNull($this->cobros($transf)[0]['link_pago']);
        $this->assertNull($this->cobros($debito)[0]['link_pago']);
        $this->assertNull($this->cobros($debito)[0]['mp_preference_id']);
    }

    public function test_si_la_pasarela_falla_el_cobro_se_crea_igual_y_el_link_se_reintenta(): void
    {
        $id = $this->susc('2026-10-03', ['modalidad' => 'link']);
        $this->pasarelaCaida();

        $r = cobros_generar_pendientes();

        $this->assertSame(['creados' => 1, 'errores' => 1], $r);
        $cobro = $this->cobros($id)[0];
        $this->assertNull($cobro['link_pago']);
        $this->assertSame('2026-11-03', $this->proximo($id));

        $this->pasarela();
        $r = cobros_completar_links();

        $this->assertSame(['completados' => 1, 'errores' => 0], $r);
        $cobro = $this->cobros($id)[0];
        $this->assertSame('https://mp.test/pagar/pref-1', $cobro['link_pago']);
        $this->assertSame('pref-1', $cobro['mp_preference_id']);
        $this->assertSame(['completados' => 0, 'errores' => 0], cobros_completar_links());
    }

    public function test_completar_links_ignora_los_cobros_pagados_y_las_otras_modalidades(): void
    {
        $pagado = $this->susc('2026-10-03', ['modalidad' => 'link']);
        $transf = $this->susc('2026-10-03');
        $this->pasarelaCaida();
        cobros_generar_pendientes();
        $this->pasarela();
        db()->prepare("UPDATE cobros SET estado = 'pagado', fecha_pago = '2026-10-02' WHERE suscripcion_cliente_id = ?")->execute([$pagado]);

        $r = cobros_completar_links();

        $this->assertSame(['completados' => 0, 'errores' => 0], $r);
        $this->assertSame([], $this->llamadas);
        $this->assertNull($this->cobros($transf)[0]['link_pago']);
    }

    public function test_el_cobro_hereda_cliente_monto_y_moneda_de_la_suscripcion(): void
    {
        $id = $this->susc('2026-10-03', ['monto' => '31000.50']);

        cobros_generar_pendientes();

        $cobro = $this->cobros($id)[0];
        $this->assertSame($this->cliente, (int)$cobro['cliente_id']);
        $this->assertSame('31000.50', $cobro['monto']);
        $this->assertSame('ARS', $cobro['moneda']);
        $this->assertNotSame($this->plan['monto'], $cobro['monto']);
    }
}
