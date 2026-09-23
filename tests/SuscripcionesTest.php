<?php
declare(strict_types=1);

final class SuscripcionesTest extends DbTestCase
{
    private function suscripcion(array $extra = []): array
    {
        return suscripciones_create($extra + [
            'servicio' => 'Claude', 'categoria' => 'IA', 'monto' => '20', 'moneda' => 'USD',
            'frecuencia' => 'mensual', 'fecha_proximo_cobro' => '2026-01-31',
        ]);
    }

    public function test_sumar_periodo_ajusta_fin_de_mes_y_bisiestos(): void
    {
        $this->assertSame('2026-02-28', sumar_periodo('2026-01-31', 'mensual'));
        $this->assertSame('2028-02-29', sumar_periodo('2028-01-31', 'mensual'));
        $this->assertSame('2027-01-15', sumar_periodo('2026-12-15', 'mensual'));
        $this->assertSame('2026-05-30', sumar_periodo('2026-04-30', 'mensual'));
        $this->assertSame('2029-02-28', sumar_periodo('2028-02-29', 'anual'));
        $this->assertSame('2027-03-10', sumar_periodo('2026-03-10', 'anual'));
    }

    public function test_crear_con_dias_restantes(): void
    {
        clock_set('2026-01-25');
        $s = $this->suscripcion();
        $this->assertSame(1, $s['activa']);
        $this->assertSame(6, $s['dias_restantes']);
        $this->assertSame('20.00', $s['monto']);
    }

    public function test_obligatorios(): void
    {
        $campos = $this->errores422(fn() => suscripciones_create(['servicio' => 'X']));
        foreach (['monto', 'moneda', 'frecuencia', 'fecha_proximo_cobro'] as $campo) {
            $this->assertArrayHasKey($campo, $campos);
        }
    }

    public function test_pagar_crea_gasto_y_avanza_la_fecha(): void
    {
        $s = $this->suscripcion();
        $r = suscripciones_pagar($s['id'], []);
        $this->assertSame('Claude', $r['gasto']['concepto']);
        $this->assertSame('IA', $r['gasto']['categoria']);
        $this->assertSame('20.00', $r['gasto']['monto']);
        $this->assertSame('USD', $r['gasto']['moneda']);
        $this->assertSame('2026-01-31', $r['gasto']['fecha']);
        $this->assertSame($s['id'], $r['gasto']['suscripcion_id']);
        $this->assertSame('2026-02-28', $r['suscripcion']['fecha_proximo_cobro']);
    }

    public function test_pagar_con_fecha_y_monto_distintos(): void
    {
        $s = $this->suscripcion();
        $r = suscripciones_pagar($s['id'], ['fecha' => '2026-02-02', 'monto' => '22,5']);
        $this->assertSame('2026-02-02', $r['gasto']['fecha']);
        $this->assertSame('22.50', $r['gasto']['monto']);
        $this->assertSame('2026-02-28', $r['suscripcion']['fecha_proximo_cobro']);
    }

    public function test_pagar_pausada_da_409(): void
    {
        $s = $this->suscripcion(['activa' => false]);
        $this->assertHttp(409, fn() => suscripciones_pagar($s['id'], []));
        $this->assertSame(0, (int)q_val('SELECT COUNT(*) FROM gastos'));
    }

    public function test_list_y_borrar_conserva_los_gastos(): void
    {
        $a = $this->suscripcion();
        $this->suscripcion(['servicio' => 'Viejo', 'activa' => false]);
        $this->assertSame(['Claude', 'Viejo'], array_column(suscripciones_list([]), 'servicio'));
        $this->assertSame(['Claude'], array_column(suscripciones_list(['activa' => '1']), 'servicio'));
        $r = suscripciones_pagar($a['id'], []);
        suscripciones_delete($a['id']);
        $this->assertNull(gastos_get($r['gasto']['id'])['suscripcion_id']);
    }
}
