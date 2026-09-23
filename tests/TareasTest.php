<?php
declare(strict_types=1);

final class TareasTest extends DbTestCase
{
    public function test_crear_con_valores_por_defecto_y_validar(): void
    {
        $t = tareas_create(['titulo' => 'Renovar dominio']);
        $this->assertSame('pendiente', $t['estado']);
        $this->assertSame('media', $t['prioridad']);
        $this->assertArrayHasKey('titulo', $this->errores422(fn() => tareas_create(['titulo' => ''])));
        $this->assertArrayHasKey('estado', $this->errores422(fn() => tareas_create(['titulo' => 'x', 'estado' => 'lista'])));
    }

    public function test_orden_y_filtro_de_abiertas(): void
    {
        tareas_create(['titulo' => 'Sin fecha alta', 'prioridad' => 'alta']);
        tareas_create(['titulo' => 'Vence tarde', 'fecha_vencimiento' => '2026-05-20']);
        tareas_create(['titulo' => 'Vence pronto', 'fecha_vencimiento' => '2026-05-01']);
        tareas_create(['titulo' => 'Hecha', 'estado' => 'hecha', 'fecha_vencimiento' => '2026-01-01']);
        tareas_create(['titulo' => 'Sin fecha baja', 'prioridad' => 'baja']);
        $this->assertSame(
            ['Vence pronto', 'Vence tarde', 'Sin fecha alta', 'Sin fecha baja', 'Hecha'],
            array_column(tareas_list([]), 'titulo'));
        $this->assertNotContains('Hecha', array_column(tareas_list(['abiertas' => '1']), 'titulo'));
        $this->assertSame(['Hecha'], array_column(tareas_list(['estado' => 'hecha']), 'titulo'));
    }

    public function test_actualizar_y_borrar(): void
    {
        $t = tareas_create(['titulo' => 'x']);
        $this->assertSame('hecha', tareas_update($t['id'], ['estado' => 'hecha'])['estado']);
        tareas_delete($t['id']);
        $this->assertFalse(crud_exists('tareas_personales', $t['id']));
    }
}
