<?php
declare(strict_types=1);

final class EventosTest extends DbTestCase
{
    public function test_crear_con_formato_de_input_y_sin_cliente(): void
    {
        $e = eventos_create(['titulo' => 'Llamada con Acme', 'fecha_hora' => '2026-05-10T15:30', 'tipo' => 'llamada']);
        $this->assertSame('2026-05-10 15:30:00', $e['fecha_hora']);
        $this->assertNull($e['cliente_id']);
        $this->assertNull($e['cliente_nombre']);
        $this->assertSame('otro', eventos_create(['titulo' => 'x', 'fecha_hora' => '2026-05-10 09:00'])['tipo']);
    }

    public function test_validaciones(): void
    {
        $campos = $this->errores422(fn() => eventos_create(['duracion_min' => '0', 'cliente_id' => 999999]));
        foreach (['titulo', 'fecha_hora', 'duracion_min', 'cliente_id'] as $campo) {
            $this->assertArrayHasKey($campo, $campos);
        }
    }

    public function test_list_incluye_el_dia_hasta_completo(): void
    {
        $c = clientes_create(['nombre' => 'Acme'])['id'];
        eventos_create(['titulo' => 'A', 'fecha_hora' => '2026-05-09 23:59']);
        eventos_create(['titulo' => 'B', 'fecha_hora' => '2026-05-10 00:00', 'cliente_id' => $c]);
        eventos_create(['titulo' => 'C', 'fecha_hora' => '2026-05-11 23:59']);
        eventos_create(['titulo' => 'D', 'fecha_hora' => '2026-05-12 00:00']);
        $this->assertSame(['B', 'C'], array_column(eventos_list(['desde' => '2026-05-10', 'hasta' => '2026-05-11']), 'titulo'));
        $this->assertSame(['B'], array_column(eventos_list(['cliente_id' => (string)$c]), 'titulo'));
    }

    public function test_actualizar_y_borrar(): void
    {
        $e = eventos_create(['titulo' => 'x', 'fecha_hora' => '2026-05-10 09:00']);
        $this->assertSame(45, eventos_update($e['id'], ['duracion_min' => '45'])['duracion_min']);
        eventos_delete($e['id']);
        $this->assertFalse(crud_exists('eventos', $e['id']));
    }
}
