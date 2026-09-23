<?php
declare(strict_types=1);

final class AgendaTest extends DbTestCase
{
    public function test_valida_el_rango(): void
    {
        $campos = $this->errores422(fn() => agenda_items([]));
        $this->assertArrayHasKey('desde', $campos);
        $this->assertArrayHasKey('hasta', $campos);
        $this->assertArrayHasKey('hasta', $this->errores422(fn() => agenda_items(['desde' => '2026-05-10', 'hasta' => '2026-05-01'])));
        $this->assertArrayHasKey('hasta', $this->errores422(fn() => agenda_items(['desde' => '2026-01-01', 'hasta' => '2026-04-04'])));
        $this->assertSame([], agenda_items(['desde' => '2026-01-01', 'hasta' => '2026-04-03']));
    }

    public function test_combina_todas_las_fuentes_en_orden(): void
    {
        clock_set('2026-05-11');
        $c = clientes_create(['nombre' => 'Acme'])['id'];
        eventos_create(['titulo' => 'Reunión', 'fecha_hora' => '2026-05-10 15:00', 'cliente_id' => $c, 'tipo' => 'reunion']);
        cobros_create(['cliente_id' => $c, 'monto' => '100', 'moneda' => 'ARS', 'fecha_vencimiento' => '2026-05-10', 'concepto' => 'Saldo']);
        cobros_create(['cliente_id' => $c, 'monto' => '100', 'moneda' => 'ARS', 'fecha_vencimiento' => '2026-05-10', 'estado' => 'pagado']);
        suscripciones_create(['servicio' => 'n8n', 'monto' => '24', 'moneda' => 'USD', 'frecuencia' => 'mensual', 'fecha_proximo_cobro' => '2026-05-12']);
        suscripciones_create(['servicio' => 'Pausada', 'monto' => '1', 'moneda' => 'USD', 'frecuencia' => 'mensual', 'fecha_proximo_cobro' => '2026-05-12', 'activa' => false]);
        procesos_create(['cliente_id' => $c, 'titulo' => 'Landing', 'fecha_entrega_estimada' => '2026-05-11']);
        procesos_create(['cliente_id' => $c, 'titulo' => 'Entregado', 'estado' => 'entregado', 'fecha_entrega_estimada' => '2026-05-11']);
        tareas_create(['titulo' => 'Renovar dominio', 'fecha_vencimiento' => '2026-05-12']);
        tareas_create(['titulo' => 'Hecha', 'estado' => 'hecha', 'fecha_vencimiento' => '2026-05-12']);
        eventos_create(['titulo' => 'Fuera de rango', 'fecha_hora' => '2026-06-01 10:00']);

        $items = agenda_items(['desde' => '2026-05-01', 'hasta' => '2026-05-31']);

        $this->assertSame(['cobro', 'evento', 'entrega', 'suscripcion', 'tarea'], array_column($items, 'tipo'));
        $this->assertSame(['Acme · Saldo', 'Reunión', 'Landing', 'n8n', 'Renovar dominio'], array_column($items, 'titulo'));
        $this->assertTrue($items[0]['vencido']);
        $this->assertSame('100.00', $items[0]['monto']);
        $this->assertSame('15:00', $items[1]['hora']);
        $this->assertNull($items[0]['hora']);
        $this->assertSame('Acme', $items[2]['cliente_nombre']);
        $this->assertIsInt($items[1]['ref']);
    }
}
