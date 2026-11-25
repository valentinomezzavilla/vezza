<?php
declare(strict_types=1);

final class SuscripcionesClienteTest extends DbTestCase
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

    private function susc(array $extra = []): array
    {
        return suscripciones_cliente_create($extra + [
            'cliente_id' => $this->cliente, 'plan_id' => $this->plan['id'], 'fecha_proximo_cobro' => '2026-04-01',
        ]);
    }

    public function test_crear_copia_monto_y_moneda_del_plan_y_arranca_en_transferencia(): void
    {
        $s = $this->susc();
        $this->assertSame('25000.00', $s['monto']);
        $this->assertSame('ARS', $s['moneda']);
        $this->assertSame('transferencia', $s['modalidad']);
        $this->assertSame('activa', $s['estado']);
        $this->assertSame('2026-03-10', $s['fecha_inicio']);
        $this->assertSame('2026-04-01', $s['fecha_proximo_cobro']);
        $this->assertSame('Acme', $s['cliente_nombre']);
        $this->assertSame('Mantenimiento', $s['plan_nombre']);
        $this->assertNull($s['mp_preapproval_estado']);
        $this->assertSame(0, (int)$s['pausada']);
    }

    public function test_se_puede_dar_un_monto_propio_al_crear(): void
    {
        $s = $this->susc(['monto' => '18.000,50']);
        $this->assertSame('18000.50', $s['monto']);
        $this->assertSame('25000.00', planes_get($this->plan['id'])['monto']);
    }

    public function test_el_cambio_de_precio_del_plan_no_toca_la_suscripcion(): void
    {
        $s = $this->susc();
        planes_update($this->plan['id'], ['monto' => '40000']);
        $this->assertSame('25000.00', suscripciones_cliente_get($s['id'])['monto']);
    }

    public function test_el_debito_no_se_pide_al_crear(): void
    {
        $campos = $this->errores422(fn() => $this->susc(['modalidad' => 'debito']));
        $this->assertArrayHasKey('modalidad', $campos);
        $this->assertStringContainsString('Cambiar modalidad', $campos['modalidad']);
        $this->assertArrayHasKey('modalidad', $this->errores422(fn() => $this->susc(['modalidad' => 'cripto'])));
    }

    public function test_validar_modalidad_directo(): void
    {
        $s = $this->susc();
        suscripcion_validar_modalidad($s, 'transferencia');
        suscripcion_validar_modalidad($s, 'debito');

        $this->assertArrayHasKey('modalidad', $this->errores422(fn() => suscripcion_validar_modalidad($s, 'cripto')));

        $usd = $s;
        $usd['moneda'] = 'USD';
        suscripcion_validar_modalidad($usd, 'transferencia');
        $this->assertArrayHasKey('modalidad', $this->errores422(fn() => suscripcion_validar_modalidad($usd, 'debito')));

        clientes_update($this->cliente, ['email' => null]);
        suscripcion_validar_modalidad($s, 'transferencia');
        $this->assertArrayHasKey('modalidad', $this->errores422(fn() => suscripcion_validar_modalidad($s, 'debito')));
    }

    public function test_fecha_proximo_cobro_es_obligatoria_para_suscripciones_activas(): void
    {
        $this->assertArrayHasKey('fecha_proximo_cobro', $this->errores422(
            fn() => suscripciones_cliente_create(['cliente_id' => $this->cliente, 'plan_id' => $this->plan['id']])
        ));
        $s = $this->susc();
        $this->assertArrayHasKey('fecha_proximo_cobro', $this->errores422(fn() => suscripciones_cliente_update($s['id'], ['fecha_proximo_cobro' => null])));
        $this->assertSame('2026-05-01', suscripciones_cliente_update($s['id'], ['fecha_proximo_cobro' => '2026-05-01'])['fecha_proximo_cobro']);
    }

    public function test_obligatorios_de_cliente_y_plan(): void
    {
        $campos = $this->errores422(fn() => suscripciones_cliente_create(['fecha_proximo_cobro' => '2026-04-01']));
        $this->assertArrayHasKey('cliente_id', $campos);
        $this->assertArrayHasKey('plan_id', $campos);
    }

    public function test_actualizar_no_cambia_cliente_modalidad_pausa_ni_estado(): void
    {
        $otro = clientes_create(['nombre' => 'Otro'])['id'];
        $s = $this->susc();
        $u = suscripciones_cliente_update($s['id'], [
            'cliente_id' => $otro, 'modalidad' => 'debito', 'pausada' => 1, 'estado' => 'cancelada',
            'mp_preapproval_id' => 'abc', 'mp_preapproval_estado' => 'autorizada', 'mp_init_point' => 'https://mp.com/x',
            'monto' => '30000',
        ]);
        $this->assertSame($this->cliente, $u['cliente_id']);
        $this->assertSame('transferencia', $u['modalidad']);
        $this->assertSame(0, (int)$u['pausada']);
        $this->assertSame('activa', $u['estado']);
        $this->assertNull($u['mp_preapproval_id']);
        $this->assertNull($u['mp_preapproval_estado']);
        $this->assertNull($u['mp_init_point']);
    }

    public function test_editar_no_cambia_el_monto(): void
    {
        $s = $this->susc(['monto' => '18000']);
        $u = suscripciones_cliente_update($s['id'], ['monto' => '30000', 'fecha_proximo_cobro' => '2026-05-01']);
        $this->assertSame('18000.00', $u['monto']);
        $this->assertSame('2026-05-01', $u['fecha_proximo_cobro']);
    }

    public function test_cambiar_de_plan_conserva_monto_y_moneda(): void
    {
        $otro = planes_create(['nombre' => 'Premium', 'monto' => '90000', 'moneda' => 'ARS', 'frecuencia' => 'mensual']);
        $s = $this->susc(['monto' => '20000']);
        $u = suscripciones_cliente_update($s['id'], ['plan_id' => $otro['id']]);
        $this->assertSame($otro['id'], $u['plan_id']);
        $this->assertSame('20000.00', $u['monto']);
        $this->assertSame('ARS', $u['moneda']);
    }

    public function test_cambiar_a_un_plan_de_otra_moneda_da_422(): void
    {
        $usd = planes_create(['nombre' => 'Dólares', 'monto' => '100', 'moneda' => 'USD', 'frecuencia' => 'mensual']);
        $s = $this->susc();
        $this->assertArrayHasKey('plan_id', $this->errores422(fn() => suscripciones_cliente_update($s['id'], ['plan_id' => $usd['id']])));
        $actual = suscripciones_cliente_get($s['id']);
        $this->assertSame($this->plan['id'], $actual['plan_id']);
        $this->assertSame('ARS', $actual['moneda']);
    }

    public function test_la_modalidad_link_ya_no_existe(): void
    {
        $this->assertArrayHasKey('modalidad', $this->errores422(fn() => $this->susc(['modalidad' => 'link'])));
    }

    public function test_estado_cobro_en_los_bordes(): void
    {
        $hoy = '2026-03-10';
        $this->assertSame(['estado' => 'vencido', 'dias' => -1], suscripcion_estado_cobro('2026-03-09', $hoy));
        $this->assertSame(['estado' => 'por_vencer', 'dias' => 0], suscripcion_estado_cobro('2026-03-10', $hoy));
        $this->assertSame(['estado' => 'por_vencer', 'dias' => 7], suscripcion_estado_cobro('2026-03-17', $hoy));
        $this->assertSame(['estado' => 'al_dia', 'dias' => 8], suscripcion_estado_cobro('2026-03-18', $hoy));
        $this->assertSame(['estado' => 'sin_fecha', 'dias' => null], suscripcion_estado_cobro(null, $hoy));
    }

    public function test_el_estado_usa_hoy_del_reloj_si_no_se_lo_pasan(): void
    {
        $this->assertSame('vencido', suscripcion_estado_cobro('2026-03-09')['estado']);
        $this->assertSame('al_dia', suscripcion_estado_cobro('2026-04-01')['estado']);
    }

    public function test_listar_y_traer_incluyen_el_estado_calculado(): void
    {
        $vencida = $this->susc(['fecha_proximo_cobro' => '2026-03-09']);
        $this->assertSame('vencido', $vencida['cobro_estado']);
        $this->assertSame(-1, $vencida['cobro_dias']);
        $lista = suscripciones_cliente_list([]);
        $this->assertSame('vencido', $lista[0]['cobro_estado']);
        $this->assertSame(-1, $lista[0]['cobro_dias']);
        $this->assertSame('vencido', suscripciones_cliente_get($vencida['id'])['cobro_estado']);
    }

    public function test_no_se_borra_con_el_debito_en_curso(): void
    {
        foreach (['pendiente', 'autorizada', 'pausada'] as $estado) {
            $s = $this->susc();
            crud_update('suscripciones_cliente', $s['id'], ['mp_preapproval_id' => "pre-$estado", 'mp_preapproval_estado' => $estado]);
            $this->assertHttp(409, fn() => suscripciones_cliente_delete($s['id']));
            $this->assertSame($s['id'], suscripciones_cliente_get($s['id'])['id']);
        }
        $cancelada = $this->susc();
        crud_update('suscripciones_cliente', $cancelada['id'], ['mp_preapproval_id' => 'pre-cancelada', 'mp_preapproval_estado' => 'cancelada']);
        suscripciones_cliente_delete($cancelada['id']);
        $this->assertHttp(404, fn() => suscripciones_cliente_get($cancelada['id']));

        $sinDebito = $this->susc();
        suscripciones_cliente_delete($sinDebito['id']);
        $this->assertHttp(404, fn() => suscripciones_cliente_get($sinDebito['id']));
    }

    public function test_listar_filtra_por_estado_cancelada(): void
    {
        $activa = $this->susc();
        $baja = $this->susc();
        crud_update('suscripciones_cliente', $baja['id'], ['estado' => 'cancelada', 'fecha_baja' => '2026-03-01']);
        $this->assertSame([$baja['id']], array_column(suscripciones_cliente_list(['estado' => 'cancelada']), 'id'));
        $this->assertSame([$activa['id']], array_column(suscripciones_cliente_list(['estado' => 'activa']), 'id'));
    }

    public function test_listar_filtra_por_cliente(): void
    {
        $otro = clientes_create(['nombre' => 'Otro'])['id'];
        $mia = $this->susc();
        $ajena = $this->susc(['cliente_id' => $otro]);
        $this->assertSame([$mia['id']], array_column(suscripciones_cliente_list(['cliente_id' => (string)$this->cliente]), 'id'));
        $this->assertEqualsCanonicalizing([$mia['id'], $ajena['id']], array_column(suscripciones_cliente_list([]), 'id'));
        $this->assertSame([], suscripciones_cliente_list(['estado' => 'cancelada']));
    }

    public function test_listar_ordena_por_proximo_cobro(): void
    {
        $tarde = $this->susc(['fecha_proximo_cobro' => '2026-06-01']);
        $pronto = $this->susc(['fecha_proximo_cobro' => '2026-04-01']);
        $this->assertSame([$pronto['id'], $tarde['id']], array_column(suscripciones_cliente_list([]), 'id'));
    }

    public function test_borrar_una_suscripcion_deja_sus_cobros(): void
    {
        $s = $this->susc();
        $cobroId = crud_insert('cobros', [
            'cliente_id' => $this->cliente, 'suscripcion_cliente_id' => $s['id'], 'mp_payment_id' => 'pago-1',
            'monto' => '25000', 'moneda' => 'ARS', 'fecha_vencimiento' => '2026-04-01',
        ]);
        suscripciones_cliente_delete($s['id']);
        $this->assertHttp(404, fn() => suscripciones_cliente_get($s['id']));
        $cobro = cobros_get($cobroId);
        $this->assertNull($cobro['suscripcion_cliente_id']);
        $this->assertSame('25000.00', $cobro['monto']);
        $this->assertHttp(404, fn() => suscripciones_cliente_delete($s['id']));
    }
}
