<?php
declare(strict_types=1);

final class CobrosTest extends DbTestCase
{
    private int $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        clock_set('2026-03-10');
        $this->cliente = clientes_create(['nombre' => 'Acme'])['id'];
    }

    private function cobro(array $extra = []): array
    {
        return cobros_create($extra + [
            'cliente_id' => $this->cliente, 'monto' => '1000', 'moneda' => 'ARS', 'fecha_vencimiento' => '2026-03-20',
        ]);
    }

    public function test_crear_pendiente_sin_exponer_el_archivo_interno(): void
    {
        $c = $this->cobro(['concepto' => 'Anticipo 50%']);
        $this->assertSame('pendiente', $c['estado']);
        $this->assertSame('pendiente', $c['estado_efectivo']);
        $this->assertSame('1000.00', $c['monto']);
        $this->assertSame('Acme', $c['cliente_nombre']);
        $this->assertFalse($c['tiene_archivo']);
        $this->assertArrayNotHasKey('comprobante_archivo', $c);
    }

    public function test_normaliza_monto_y_moneda(): void
    {
        $c = $this->cobro(['monto' => '1500,5', 'moneda' => 'usd']);
        $this->assertSame('1500.50', $c['monto']);
        $this->assertSame('USD', $c['moneda']);
    }

    public function test_vencido_se_calcula_con_la_fecha_de_hoy(): void
    {
        $vencido = $this->cobro(['fecha_vencimiento' => '2026-03-09']);
        $hoy = $this->cobro(['fecha_vencimiento' => '2026-03-10']);
        $this->assertSame('vencido', $vencido['estado_efectivo']);
        $this->assertSame('pendiente', $hoy['estado_efectivo']);
        $this->assertSame([$vencido['id']], array_column(cobros_list(['estado' => 'vencido']), 'id'));
        $this->assertSame([$hoy['id']], array_column(cobros_list(['estado' => 'pendiente']), 'id'));
    }

    public function test_marcar_pagado_completa_fecha_de_pago_y_volver_a_pendiente_la_borra(): void
    {
        $c = $this->cobro(['fecha_vencimiento' => '2026-03-01']);
        $pagado = cobros_update($c['id'], ['estado' => 'pagado']);
        $this->assertSame('2026-03-10', $pagado['fecha_pago']);
        $this->assertSame('pagado', $pagado['estado_efectivo']);
        $conFecha = cobros_update($c['id'], ['fecha_pago' => '2026-03-05']);
        $this->assertSame('2026-03-05', $conFecha['fecha_pago']);
        $pendiente = cobros_update($c['id'], ['estado' => 'pendiente']);
        $this->assertNull($pendiente['fecha_pago']);
        $this->assertSame('vencido', $pendiente['estado_efectivo']);
    }

    public function test_proceso_tiene_que_ser_del_cliente(): void
    {
        $otro = clientes_create(['nombre' => 'Otro'])['id'];
        $ajeno = procesos_create(['cliente_id' => $otro, 'titulo' => 'Ajeno']);
        $this->assertArrayHasKey('proceso_id', $this->errores422(fn() => $this->cobro(['proceso_id' => $ajeno['id']])));
        $propio = procesos_create(['cliente_id' => $this->cliente, 'titulo' => 'Propio']);
        $this->assertSame('Propio', $this->cobro(['proceso_id' => $propio['id']])['proceso_titulo']);
    }

    public function test_cambiar_cliente_con_proceso_ajeno_falla(): void
    {
        $otro = clientes_create(['nombre' => 'Otro'])['id'];
        $propio = procesos_create(['cliente_id' => $this->cliente, 'titulo' => 'Propio']);
        $c = $this->cobro(['proceso_id' => $propio['id']]);
        $this->assertArrayHasKey('proceso_id', $this->errores422(fn() => cobros_update($c['id'], ['cliente_id' => $otro])));
        $movido = cobros_update($c['id'], ['cliente_id' => $otro, 'proceso_id' => null]);
        $this->assertSame($otro, $movido['cliente_id']);
    }

    public function test_link_de_comprobante_solo_http(): void
    {
        $this->assertArrayHasKey('comprobante_url', $this->errores422(fn() => $this->cobro(['comprobante_url' => 'javascript:alert(1)'])));
        $this->assertSame('https://mp.com/r/1', $this->cobro(['comprobante_url' => 'https://mp.com/r/1'])['comprobante_url']);
    }

    public function test_obligatorios(): void
    {
        $campos = $this->errores422(fn() => cobros_create(['cliente_id' => $this->cliente]));
        foreach (['monto', 'moneda', 'fecha_vencimiento'] as $campo) {
            $this->assertArrayHasKey($campo, $campos);
        }
    }

    public function test_orden_filtros_y_borrado(): void
    {
        $pagado = $this->cobro(['fecha_vencimiento' => '2026-01-01', 'estado' => 'pagado', 'fecha_pago' => '2026-01-05']);
        $tarde = $this->cobro(['fecha_vencimiento' => '2026-04-01']);
        $pronto = $this->cobro(['fecha_vencimiento' => '2026-03-15']);
        $this->assertSame([$pronto['id'], $tarde['id'], $pagado['id']], array_column(cobros_list([]), 'id'));
        $this->assertSame([$pagado['id']], array_column(cobros_list(['desde' => '2026-01-01', 'hasta' => '2026-01-31']), 'id'));
        $this->assertSame([$pagado['id']], array_column(cobros_list(['estado' => 'pagado']), 'id'));
        cobros_delete($tarde['id']);
        $this->assertHttp(404, fn() => cobros_get($tarde['id']));
    }
}
