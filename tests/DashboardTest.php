<?php
declare(strict_types=1);

final class DashboardTest extends DbTestCase
{
    public function test_balance_por_moneda_y_por_mes(): void
    {
        $c = clientes_create(['nombre' => 'A'])['id'];
        $otro = clientes_create(['nombre' => 'B'])['id'];
        cobros_create(['cliente_id' => $c, 'monto' => '1000', 'moneda' => 'ARS', 'fecha_vencimiento' => '2026-05-01', 'estado' => 'pagado', 'fecha_pago' => '2026-05-03']);
        cobros_create(['cliente_id' => $otro, 'monto' => '50', 'moneda' => 'USD', 'fecha_vencimiento' => '2026-05-01', 'estado' => 'pagado', 'fecha_pago' => '2026-06-02']);
        cobros_create(['cliente_id' => $c, 'monto' => '999', 'moneda' => 'ARS', 'fecha_vencimiento' => '2026-05-01']);
        gastos_create(['concepto' => 'Hosting', 'monto' => '300', 'moneda' => 'ARS', 'fecha' => '2026-05-10']);
        gastos_create(['concepto' => 'Claude', 'monto' => '20', 'moneda' => 'USD', 'fecha' => '2026-05-10']);

        $this->assertSame([
            ['moneda' => 'ARS', 'ingresos' => '1000.00', 'gastos' => '300.00', 'balance' => '700.00'],
            ['moneda' => 'USD', 'ingresos' => '0.00', 'gastos' => '20.00', 'balance' => '-20.00'],
        ], balance_por_moneda('2026-05-01', '2026-05-31'));

        $this->assertSame([
            ['mes' => '2026-05', 'moneda' => 'ARS', 'ingresos' => '1000.00', 'gastos' => '300.00', 'balance' => '700.00'],
            ['mes' => '2026-05', 'moneda' => 'USD', 'ingresos' => '0.00', 'gastos' => '20.00', 'balance' => '-20.00'],
            ['mes' => '2026-06', 'moneda' => 'USD', 'ingresos' => '50.00', 'gastos' => '0.00', 'balance' => '50.00'],
        ], balance_por_moneda('2026-05-01', '2026-06-30', null, true));

        $this->assertSame([
            ['moneda' => 'ARS', 'ingresos' => '1000.00', 'gastos' => '0.00', 'balance' => '1000.00'],
        ], balance_por_moneda('2026-05-01', '2026-06-30', $c));
    }

    public function test_resumen(): void
    {
        clock_set('2026-05-15');
        $c = clientes_create(['nombre' => 'Acme'])['id'];
        cobros_create(['cliente_id' => $c, 'monto' => '200', 'moneda' => 'ARS', 'fecha_vencimiento' => '2026-05-01']);
        cobros_create(['cliente_id' => $c, 'monto' => '500', 'moneda' => 'ARS', 'fecha_vencimiento' => '2026-06-01']);
        cobros_create(['cliente_id' => $c, 'monto' => '800', 'moneda' => 'ARS', 'fecha_vencimiento' => '2026-05-01', 'estado' => 'pagado', 'fecha_pago' => '2026-05-02']);
        suscripciones_create(['servicio' => 'Atrasada', 'monto' => '1', 'moneda' => 'USD', 'frecuencia' => 'mensual', 'fecha_proximo_cobro' => '2026-05-01']);
        suscripciones_create(['servicio' => 'Pronto', 'monto' => '1', 'moneda' => 'USD', 'frecuencia' => 'mensual', 'fecha_proximo_cobro' => '2026-05-29']);
        suscripciones_create(['servicio' => 'Lejos', 'monto' => '1', 'moneda' => 'USD', 'frecuencia' => 'mensual', 'fecha_proximo_cobro' => '2026-05-30']);
        suscripciones_create(['servicio' => 'Pausada', 'monto' => '1', 'moneda' => 'USD', 'frecuencia' => 'mensual', 'fecha_proximo_cobro' => '2026-05-20', 'activa' => false]);
        procesos_create(['cliente_id' => $c, 'titulo' => 'Landing', 'estado' => 'en_curso']);
        procesos_create(['cliente_id' => $c, 'titulo' => 'Viejo', 'estado' => 'entregado']);
        tareas_create(['titulo' => 'a']);
        tareas_create(['titulo' => 'b', 'estado' => 'hecha']);
        fixs_create(['cliente_id' => $c, 'titulo' => 'bug']);
        eventos_create(['titulo' => 'Ayer', 'fecha_hora' => '2026-05-14 10:00']);
        eventos_create(['titulo' => 'Hoy temprano', 'fecha_hora' => '2026-05-15 08:00']);

        $r = dashboard_resumen();

        $this->assertSame('2026-05', $r['mes']);
        $this->assertSame([['moneda' => 'ARS', 'ingresos' => '800.00', 'gastos' => '0.00', 'balance' => '800.00']], $r['balance_mes']);
        $this->assertCount(2, $r['cobros_pendientes']);
        $this->assertSame('vencido', $r['cobros_pendientes'][0]['estado_efectivo']);
        $this->assertSame([['moneda' => 'ARS', 'vencido' => '200.00', 'por_vencer' => '500.00']], $r['cobros_pendientes_totales']);
        $this->assertSame(['Atrasada', 'Pronto'], array_column($r['suscripciones_proximas'], 'servicio'));
        $this->assertSame(-14, $r['suscripciones_proximas'][0]['dias_restantes']);
        $this->assertCount(1, $r['procesos_por_cliente']);
        $this->assertSame('Acme', $r['procesos_por_cliente'][0]['cliente_nombre']);
        $this->assertSame(['Landing'], array_column($r['procesos_por_cliente'][0]['procesos'], 'titulo'));
        $this->assertSame(1, $r['tareas_abiertas']);
        $this->assertSame(1, $r['fixs_abiertos']);
        $this->assertSame(['Hoy temprano'], array_column($r['proximos_eventos'], 'titulo'));
    }
}
