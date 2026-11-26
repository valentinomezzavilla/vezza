<?php
declare(strict_types=1);

final class SuscripcionesPagosTest extends DbTestCase
{
    private int $cliente;
    private array $plan;

    protected function setUp(): void
    {
        parent::setUp();
        clock_set('2026-03-10');
        $this->cliente = clientes_create(['nombre' => 'Acme', 'email' => 'acme@sol.com'])['id'];
        $this->plan = planes_create(['nombre' => 'Mantenimiento', 'monto' => '25000', 'moneda' => 'ARS', 'frecuencia' => 'mensual']);
    }

    private function susc(string $proximoCobro = '2026-03-15', array $extra = []): array
    {
        return suscripciones_cliente_create($extra + [
            'cliente_id' => $this->cliente, 'plan_id' => $this->plan['id'], 'fecha_proximo_cobro' => $proximoCobro,
        ]);
    }

    private function pagoMp(string $paymentId, array $extra = []): array
    {
        return $extra + [
            'meses' => 1, 'monto' => '25000.00', 'moneda' => 'ARS', 'metodo_pago' => 'Mercado Pago · débito',
            'mp_payment_id' => $paymentId,
        ];
    }

    private function proximoCobro(int $id): string
    {
        return suscripciones_cliente_get($id)['fecha_proximo_cobro'];
    }

    // ---- sumar_meses ----

    public function test_sumar_meses_recorta_el_dia_una_sola_vez(): void
    {
        $this->assertSame('2026-02-28', sumar_meses('2026-01-31', 1));
        $this->assertSame('2026-03-31', sumar_meses('2026-01-31', 2));
        $this->assertSame('2026-04-30', sumar_meses('2026-03-31', 1));
        $this->assertSame('2027-01-15', sumar_meses('2026-12-15', 1));
        $this->assertSame('2027-02-28', sumar_meses('2026-11-30', 3));
        $this->assertSame('2028-02-29', sumar_meses('2028-01-31', 1));
        $this->assertSame('2026-03-10', sumar_meses('2026-03-10', 0));
    }

    public function test_el_concepto_lleva_el_plan_y_el_mes(): void
    {
        $this->assertSame('Mantenimiento · noviembre 2026', cobro_concepto_pago('Mantenimiento', '2026-11-05', 1));
        $this->assertSame('Mantenimiento · 3 meses desde enero 2027', cobro_concepto_pago('Mantenimiento', '2027-01-31', 3));
    }

    // ---- pago manual ----

    public function test_registrar_un_pago_crea_un_cobro_pagado_y_adelanta_un_mes(): void
    {
        $s = $this->susc('2026-03-15');

        $r = suscripcion_pago_manual($s['id'], []);

        $cobro = $r['cobro'];
        $this->assertSame('pagado', $cobro['estado']);
        $this->assertSame('2026-03-10', $cobro['fecha_pago']);
        $this->assertSame('2026-03-15', $cobro['fecha_vencimiento']);
        $this->assertSame($s['id'], $cobro['suscripcion_cliente_id']);
        $this->assertSame($this->cliente, $cobro['cliente_id']);
        $this->assertSame('Mantenimiento · marzo 2026', $cobro['concepto']);
        $this->assertSame('25000.00', $cobro['monto']);
        $this->assertSame('ARS', $cobro['moneda']);
        $this->assertSame('Transferencia', $cobro['metodo_pago']);
        $this->assertNull($cobro['mp_payment_id']);
        $this->assertSame('2026-04-15', $r['suscripcion']['fecha_proximo_cobro']);
    }

    public function test_el_monto_por_defecto_es_monto_por_meses_y_se_pueden_registrar_varios(): void
    {
        $s = $this->susc('2026-03-15');

        $r = suscripcion_pago_manual($s['id'], ['meses' => 3]);

        $this->assertSame('75000.00', $r['cobro']['monto']);
        $this->assertSame('Mantenimiento · 3 meses desde marzo 2026', $r['cobro']['concepto']);
        $this->assertSame('2026-06-15', $r['suscripcion']['fecha_proximo_cobro']);
    }

    public function test_se_puede_indicar_otro_monto_otro_medio_y_una_nota(): void
    {
        $s = $this->susc('2026-03-15');

        $r = suscripcion_pago_manual($s['id'], ['monto' => '20.000,50', 'metodo_pago' => 'Efectivo', 'nota' => 'Descuento por pago adelantado']);

        $this->assertSame('20000.50', $r['cobro']['monto']);
        $this->assertSame('Efectivo', $r['cobro']['metodo_pago']);
        $this->assertSame('Mantenimiento · marzo 2026 — Descuento por pago adelantado', $r['cobro']['concepto']);
    }

    public function test_el_pago_manual_corre_el_vencimiento_un_mes_sin_mover_el_dia(): void
    {
        // Pagado antes de vencer: el próximo vence el mismo día del mes siguiente.
        $alDia = $this->susc('2026-03-25');
        $this->assertSame('2026-04-25', suscripcion_pago_manual($alDia['id'], [])['suscripcion']['fecha_proximo_cobro']);

        // Pagado tarde: cubre lo que venció el 1 de marzo y el próximo vence el 1 de abril, no un mes desde hoy.
        $vencida = $this->susc('2026-03-01');
        $r = suscripcion_pago_manual($vencida['id'], []);
        $this->assertSame('2026-04-01', $r['suscripcion']['fecha_proximo_cobro']);
        $this->assertSame('2026-03-01', $r['cobro']['fecha_vencimiento']);
        $this->assertSame('Mantenimiento · marzo 2026', $r['cobro']['concepto']);
    }

    public function test_quien_debe_varios_meses_y_paga_uno_sigue_debiendo_los_otros(): void
    {
        $s = $this->susc('2026-01-05');
        $this->assertSame('2026-02-05', suscripcion_pago_manual($s['id'], [])['suscripcion']['fecha_proximo_cobro']);
        $this->assertSame('vencido', suscripciones_cliente_get($s['id'])['cobro_estado']);
        $this->assertSame('2026-04-05', suscripcion_pago_manual($s['id'], ['meses' => 2])['suscripcion']['fecha_proximo_cobro']);
        $this->assertSame('al_dia', suscripciones_cliente_get($s['id'])['cobro_estado']);
    }

    public function test_sin_fecha_cubre_el_primer_vencimiento_desde_hoy(): void
    {
        $s = $this->susc('2026-03-15'); // vence los 15
        crud_update('suscripciones_cliente', $s['id'], ['fecha_proximo_cobro' => null]);

        $r = suscripcion_pago_manual($s['id'], []);

        $this->assertSame('2026-03-15', $r['cobro']['fecha_vencimiento']);
        $this->assertSame('2026-04-15', $r['suscripcion']['fecha_proximo_cobro']);
    }

    public function test_los_meses_y_el_monto_se_validan(): void
    {
        $s = $this->susc();
        $this->assertArrayHasKey('meses', $this->errores422(fn() => suscripcion_pago_manual($s['id'], ['meses' => 0])));
        $this->assertArrayHasKey('meses', $this->errores422(fn() => suscripcion_pago_manual($s['id'], ['meses' => 25])));
        $this->assertArrayHasKey('monto', $this->errores422(fn() => suscripcion_pago_manual($s['id'], ['monto' => '-5'])));
        $this->assertArrayHasKey('monto', $this->errores422(fn() => suscripcion_pago_manual($s['id'], ['monto' => '0'])));
        $this->assertSame('2026-03-15', $this->proximoCobro($s['id']));
        $this->assertSame(0, (int)q_val('SELECT COUNT(*) FROM cobros WHERE suscripcion_cliente_id = ?', [$s['id']]));
    }

    public function test_no_se_registra_a_mano_en_una_suscripcion_cancelada(): void
    {
        $s = $this->susc();
        crud_update('suscripciones_cliente', $s['id'], ['estado' => 'cancelada', 'fecha_baja' => '2026-03-01']);

        $this->assertHttp(409, fn() => suscripcion_pago_manual($s['id'], []));
        $this->assertSame(0, (int)q_val('SELECT COUNT(*) FROM cobros WHERE suscripcion_cliente_id = ?', [$s['id']]));
    }

    public function test_una_suscripcion_inexistente_da_404(): void
    {
        $this->assertHttp(404, fn() => suscripcion_pago_manual(999999, []));
        $this->assertHttp(404, fn() => suscripcion_registrar_pago(999999, $this->pagoMp('pago-x')));
    }

    public function test_el_cobro_registrado_aparece_en_la_lista_de_pagados(): void
    {
        $s = $this->susc();
        $id = suscripcion_pago_manual($s['id'], [])['cobro']['id'];

        $pagados = cobros_list(['estado' => 'pagado']);

        $this->assertSame([$id], array_column($pagados, 'id'));
        $this->assertSame('Acme', $pagados[0]['cliente_nombre']);
        $this->assertSame('pagado', $pagados[0]['estado_efectivo']);
    }

    // ---- pago de Mercado Pago ----

    public function test_el_pago_de_debito_avanza_siempre_un_mes_desde_la_fecha(): void
    {
        $s = $this->susc('2026-03-07'); // venció hace 3 días y el aviso llega recién hoy

        $r = suscripcion_registrar_pago($s['id'], $this->pagoMp('pago-1'));

        $this->assertSame('registrado', $r['resultado']);
        $this->assertSame('2026-04-07', $r['fecha_proximo_cobro']);
        $this->assertSame('2026-04-07', $this->proximoCobro($s['id']));
        $cobro = cobros_get($r['cobro_id']);
        $this->assertSame('pago-1', $cobro['mp_payment_id']);
        $this->assertSame('Mercado Pago · débito', $cobro['metodo_pago']);
        $this->assertSame('2026-03-07', $cobro['fecha_vencimiento']);
        $this->assertSame('2026-03-10', $cobro['fecha_pago']);
    }

    public function test_el_mismo_mp_payment_id_no_suma_dos_veces(): void
    {
        $s = $this->susc('2026-03-15');

        $primero = suscripcion_registrar_pago($s['id'], $this->pagoMp('pago-1'));
        $segundo = suscripcion_registrar_pago($s['id'], $this->pagoMp('pago-1'));

        $this->assertSame('registrado', $primero['resultado']);
        $this->assertSame('repetido', $segundo['resultado']);
        $this->assertSame($primero['cobro_id'], $segundo['cobro_id']);
        $this->assertSame('2026-04-15', $this->proximoCobro($s['id']));
        $this->assertSame(1, (int)q_val('SELECT COUNT(*) FROM cobros WHERE mp_payment_id = ?', ['pago-1']));
    }

    public function test_dos_pagos_distintos_suman_dos_meses(): void
    {
        $s = $this->susc('2026-03-15');

        suscripcion_registrar_pago($s['id'], $this->pagoMp('pago-1'));
        suscripcion_registrar_pago($s['id'], $this->pagoMp('pago-2'));

        $this->assertSame('2026-05-15', $this->proximoCobro($s['id']));
        $this->assertSame(2, (int)q_val('SELECT COUNT(*) FROM cobros WHERE suscripcion_cliente_id = ?', [$s['id']]));
    }

    public function test_un_pago_de_mercado_pago_se_registra_aunque_la_suscripcion_este_cancelada(): void
    {
        $s = $this->susc('2026-03-15');
        crud_update('suscripciones_cliente', $s['id'], ['estado' => 'cancelada', 'fecha_baja' => '2026-03-12']);

        $r = suscripcion_registrar_pago($s['id'], $this->pagoMp('pago-ultimo'));

        $this->assertSame('registrado', $r['resultado']);
        $this->assertSame('pagado', cobros_get($r['cobro_id'])['estado']);
    }

    public function test_un_alta_de_fin_de_mes_vence_los_28(): void
    {
        clock_set('2026-01-31');
        $s = suscripciones_cliente_create(['cliente_id' => $this->cliente, 'plan_id' => $this->plan['id'], 'fecha_inicio' => '2026-01-31']);
        $this->assertSame(28, (int)$s['dia_vencimiento']);
        $this->assertSame('2026-02-28', $s['fecha_proximo_cobro']);
        $this->assertSame('2026-03-28', suscripcion_registrar_pago($s['id'], $this->pagoMp('pago-1'))['fecha_proximo_cobro']);
        $this->assertSame('2026-04-28', suscripcion_registrar_pago($s['id'], $this->pagoMp('pago-2'))['fecha_proximo_cobro']);
    }

    public function test_un_debito_atrasado_por_meses_sigue_el_calendario(): void
    {
        // Hoy es 10 mar y el vencimiento quedó en enero: el cobro cubre enero y el próximo vence en febrero. Sigue vencido
        // porque debe febrero: con vencimiento fijo nunca se cuenta desde hoy.
        $s = $this->susc('2026-01-15');

        $r = suscripcion_registrar_pago($s['id'], $this->pagoMp('pago-1'));

        $this->assertSame('2026-02-15', $r['fecha_proximo_cobro']);
        $this->assertSame('vencido', suscripciones_cliente_get($s['id'])['cobro_estado']);
        $this->assertSame('2026-01-15', cobros_get($r['cobro_id'])['fecha_vencimiento'], 'El cobro conserva la fecha que venció');
    }

    public function test_un_debito_atrasado_justo_un_mes_conserva_el_calendario(): void
    {
        // Un mes después de la fecha cae exactamente hoy: no está atrasado por meses, sigue el calendario de Mercado Pago.
        $s = $this->susc('2026-02-10');

        $this->assertSame('2026-03-10', suscripcion_registrar_pago($s['id'], $this->pagoMp('pago-1'))['fecha_proximo_cobro']);
    }
}
