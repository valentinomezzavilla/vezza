<?php
declare(strict_types=1);

final class ReportesTest extends DbTestCase
{
    public function test_valida_parametros(): void
    {
        $campos = $this->errores422(fn() => reporte_balance([]));
        $this->assertArrayHasKey('desde', $campos);
        $this->assertArrayHasKey('hasta', $campos);
        $this->assertArrayHasKey('hasta', $this->errores422(fn() => reporte_balance(['desde' => '2026-05-01', 'hasta' => '2026-04-01'])));
        $this->assertArrayHasKey('cliente_id', $this->errores422(fn() => reporte_balance(['desde' => '2026-01-01', 'hasta' => '2026-02-01', 'cliente_id' => '999999'])));
    }

    public function test_meses_completos_y_totales(): void
    {
        $c = clientes_create(['nombre' => 'A'])['id'];
        cobros_create(['cliente_id' => $c, 'monto' => '1000', 'moneda' => 'ARS', 'fecha_vencimiento' => '2026-01-01', 'estado' => 'pagado', 'fecha_pago' => '2026-01-10']);
        gastos_create(['concepto' => 'Hosting', 'monto' => '100', 'moneda' => 'ARS', 'fecha' => '2026-03-05']);

        $r = reporte_balance(['desde' => '2026-01-15', 'hasta' => '2026-03-31']);

        $this->assertTrue($r['incluye_gastos']);
        $this->assertSame(['2026-01', '2026-02', '2026-03'], array_column($r['meses'], 'mes'));
        $this->assertSame([], $r['meses'][0]['monedas']);
        $this->assertSame([], $r['meses'][1]['monedas']);
        $this->assertSame([['moneda' => 'ARS', 'ingresos' => '0.00', 'gastos' => '100.00', 'balance' => '-100.00']], $r['meses'][2]['monedas']);
        $this->assertSame([['moneda' => 'ARS', 'ingresos' => '0.00', 'gastos' => '100.00', 'balance' => '-100.00']], $r['totales']);
    }

    public function test_por_cliente_omite_gastos(): void
    {
        $a = clientes_create(['nombre' => 'A'])['id'];
        $b = clientes_create(['nombre' => 'B'])['id'];
        cobros_create(['cliente_id' => $a, 'monto' => '1000', 'moneda' => 'ARS', 'fecha_vencimiento' => '2026-01-01', 'estado' => 'pagado', 'fecha_pago' => '2026-01-10']);
        cobros_create(['cliente_id' => $b, 'monto' => '7', 'moneda' => 'USD', 'fecha_vencimiento' => '2026-01-01', 'estado' => 'pagado', 'fecha_pago' => '2026-01-11']);
        gastos_create(['concepto' => 'Hosting', 'monto' => '100', 'moneda' => 'ARS', 'fecha' => '2026-01-05']);

        $r = reporte_balance(['desde' => '2026-01-01', 'hasta' => '2026-01-31', 'cliente_id' => (string)$a]);

        $this->assertFalse($r['incluye_gastos']);
        $this->assertSame($a, $r['cliente_id']);
        $this->assertSame([['moneda' => 'ARS', 'ingresos' => '1000.00', 'balance' => '1000.00']], $r['meses'][0]['monedas']);
        $this->assertSame([['moneda' => 'ARS', 'ingresos' => '1000.00', 'balance' => '1000.00']], $r['totales']);
    }
}
