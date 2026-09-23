<?php
declare(strict_types=1);

final class SubtareasTest extends DbTestCase
{
    private function proceso(): int
    {
        $c = clientes_create(['nombre' => 'X'])['id'];
        return procesos_create(['cliente_id' => $c, 'titulo' => 'P'])['id'];
    }

    public function test_list_exige_proceso(): void
    {
        $this->assertArrayHasKey('proceso_id', $this->errores422(fn() => subtareas_list([])));
    }

    public function test_crear_tildar_y_ordenar(): void
    {
        $p = $this->proceso();
        $a = subtareas_create(['proceso_id' => $p, 'titulo' => 'Diseño']);
        $b = subtareas_create(['proceso_id' => $p, 'titulo' => 'Maquetado']);
        $c = subtareas_create(['proceso_id' => $p, 'titulo' => 'Deploy']);
        $this->assertSame(0, $a['completada']);
        $this->assertSame(1, subtareas_update($a['id'], ['completada' => true])['completada']);
        subtareas_update($c['id'], ['antes_de' => $a['id']]);
        $this->assertSame(['Deploy', 'Diseño', 'Maquetado'], array_column(subtareas_list(['proceso_id' => (string)$p]), 'titulo'));
        $this->assertSame('Deploy', subtareas_get($c['id'])['titulo']);
        subtareas_update($b['id'], ['titulo' => 'Maquetado responsive']);
        $this->assertSame('Maquetado responsive', subtareas_get($b['id'])['titulo']);
    }

    public function test_validaciones(): void
    {
        $this->assertArrayHasKey('proceso_id', $this->errores422(fn() => subtareas_create(['proceso_id' => 999999, 'titulo' => 'x'])));
        $s = subtareas_create(['proceso_id' => $this->proceso(), 'titulo' => 'x']);
        $this->assertArrayHasKey('completada', $this->errores422(fn() => subtareas_update($s['id'], ['completada' => 'tal vez'])));
        $this->assertArrayHasKey('titulo', $this->errores422(fn() => subtareas_update($s['id'], ['titulo' => ''])));
    }

    public function test_borrar(): void
    {
        $s = subtareas_create(['proceso_id' => $this->proceso(), 'titulo' => 'x']);
        subtareas_delete($s['id']);
        $this->assertFalse(crud_exists('subtareas', $s['id']));
    }
}
