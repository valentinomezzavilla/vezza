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

    public function test_debito_y_link_exigen_moneda_ars(): void
    {
        $usd = planes_create(['nombre' => 'Dólares', 'monto' => '100', 'moneda' => 'USD', 'frecuencia' => 'mensual']);
        $this->assertArrayHasKey('modalidad', $this->errores422(fn() => $this->susc(['plan_id' => $usd['id'], 'modalidad' => 'link'])));
        $this->assertSame('transferencia', $this->susc(['plan_id' => $usd['id'], 'modalidad' => 'transferencia'])['modalidad']);
        $this->assertSame('link', $this->susc(['modalidad' => 'link'])['modalidad']);
    }

    public function test_debito_y_link_exigen_que_el_cliente_tenga_mail(): void
    {
        $sinMail = clientes_create(['nombre' => 'Sin mail'])['id'];
        $this->assertArrayHasKey('modalidad', $this->errores422(fn() => $this->susc(['cliente_id' => $sinMail, 'modalidad' => 'link'])));
        $this->assertSame('transferencia', $this->susc(['cliente_id' => $sinMail])['modalidad']);
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
        suscripcion_validar_modalidad($s, 'link');

        $this->assertArrayHasKey('modalidad', $this->errores422(fn() => suscripcion_validar_modalidad($s, 'cripto')));

        $usd = $s;
        $usd['moneda'] = 'USD';
        suscripcion_validar_modalidad($usd, 'transferencia');
        foreach (['debito', 'link'] as $modalidad) {
            $this->assertArrayHasKey('modalidad', $this->errores422(fn() => suscripcion_validar_modalidad($usd, $modalidad)));
        }

        clientes_update($this->cliente, ['email' => null]);
        suscripcion_validar_modalidad($s, 'transferencia');
        foreach (['debito', 'link'] as $modalidad) {
            $this->assertArrayHasKey('modalidad', $this->errores422(fn() => suscripcion_validar_modalidad($s, $modalidad)));
        }
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
            'cliente_id' => $otro, 'modalidad' => 'link', 'pausada' => 1, 'estado' => 'cancelada',
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
        $this->assertSame('30000.00', $u['monto']);
    }

    public function test_cambiar_de_plan_toma_su_precio_salvo_que_se_indique_otro(): void
    {
        $usd = planes_create(['nombre' => 'Dólares', 'monto' => '100', 'moneda' => 'USD', 'frecuencia' => 'anual']);
        $s = $this->susc(['monto' => '20000']);
        $u = suscripciones_cliente_update($s['id'], ['plan_id' => $usd['id']]);
        $this->assertSame('100.00', $u['monto']);
        $this->assertSame('USD', $u['moneda']);
        $this->assertSame('90.00', suscripciones_cliente_update($s['id'], ['plan_id' => $this->plan['id'], 'monto' => '90'])['monto']);
    }

    public function test_cambiar_a_un_plan_en_otra_moneda_respeta_la_modalidad_de_cobro(): void
    {
        $usd = planes_create(['nombre' => 'Dólares', 'monto' => '100', 'moneda' => 'USD', 'frecuencia' => 'mensual']);
        $s = $this->susc(['modalidad' => 'link']);
        $this->assertArrayHasKey('modalidad', $this->errores422(fn() => suscripciones_cliente_update($s['id'], ['plan_id' => $usd['id']])));
        $this->assertSame('ARS', suscripciones_cliente_get($s['id'])['moneda']);
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
            'cliente_id' => $this->cliente, 'suscripcion_cliente_id' => $s['id'], 'periodo' => '2026-04',
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
