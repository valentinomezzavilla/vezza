<?php
declare(strict_types=1);

final class GastosTest extends DbTestCase
{
    public function test_crear_y_validar(): void
    {
        $g = gastos_create(['concepto' => 'Dominio .com', 'monto' => '15000', 'moneda' => 'ars', 'fecha' => '2026-03-01', 'categoria' => 'Dominios']);
        $this->assertSame('15000.00', $g['monto']);
        $this->assertSame('ARS', $g['moneda']);
        $this->assertNull($g['suscripcion_id']);
        $campos = $this->errores422(fn() => gastos_create(['suscripcion_id' => 999999]));
        foreach (['concepto', 'monto', 'moneda', 'fecha', 'suscripcion_id'] as $campo) {
            $this->assertArrayHasKey($campo, $campos);
        }
    }

    public function test_list_filtra_por_rango_y_categoria_y_ordena_por_fecha_desc(): void
    {
        gastos_create(['concepto' => 'A', 'monto' => '1', 'moneda' => 'ARS', 'fecha' => '2026-02-28', 'categoria' => 'Hosting']);
        gastos_create(['concepto' => 'B', 'monto' => '1', 'moneda' => 'ARS', 'fecha' => '2026-03-01', 'categoria' => 'IA']);
        gastos_create(['concepto' => 'C', 'monto' => '1', 'moneda' => 'ARS', 'fecha' => '2026-03-31', 'categoria' => 'Hosting']);
        $this->assertSame(['C', 'B'], array_column(gastos_list(['desde' => '2026-03-01', 'hasta' => '2026-03-31']), 'concepto'));
        $this->assertSame(['C', 'A'], array_column(gastos_list(['categoria' => 'Hosting']), 'concepto'));
    }

    public function test_actualizar_y_borrar(): void
    {
        $g = gastos_create(['concepto' => 'A', 'monto' => '1', 'moneda' => 'ARS', 'fecha' => '2026-03-01']);
        $this->assertSame('Hosting', gastos_update($g['id'], ['categoria' => 'Hosting'])['categoria']);
        gastos_delete($g['id']);
        $this->assertFalse(crud_exists('gastos', $g['id']));
    }
}
