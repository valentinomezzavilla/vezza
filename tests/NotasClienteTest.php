<?php
declare(strict_types=1);

final class NotasClienteTest extends DbTestCase
{
    public function test_list_exige_cliente(): void
    {
        $this->assertArrayHasKey('cliente_id', $this->errores422(fn() => notas_cliente_list([])));
    }

    public function test_crear_y_listar_de_la_mas_nueva_a_la_mas_vieja(): void
    {
        $c = clientes_create(['nombre' => 'X']);
        $otro = clientes_create(['nombre' => 'Y']);
        notas_cliente_create(['cliente_id' => $c['id'], 'contenido' => 'primera']);
        notas_cliente_create(['cliente_id' => $c['id'], 'contenido' => "segunda\ncon salto"]);
        notas_cliente_create(['cliente_id' => $otro['id'], 'contenido' => 'ajena']);
        $this->assertSame(["segunda\ncon salto", 'primera'], array_column(notas_cliente_list(['cliente_id' => (string)$c['id']]), 'contenido'));
    }

    public function test_update_solo_cambia_contenido(): void
    {
        $c = clientes_create(['nombre' => 'X']);
        $otro = clientes_create(['nombre' => 'Y']);
        $n = notas_cliente_create(['cliente_id' => $c['id'], 'contenido' => 'a']);
        $n2 = notas_cliente_update($n['id'], ['contenido' => 'b', 'cliente_id' => $otro['id']]);
        $this->assertSame('b', $n2['contenido']);
        $this->assertSame($c['id'], $n2['cliente_id']);
    }

    public function test_validaciones(): void
    {
        $this->assertArrayHasKey('cliente_id', $this->errores422(fn() => notas_cliente_create(['cliente_id' => 999999, 'contenido' => 'x'])));
        $c = clientes_create(['nombre' => 'X']);
        $this->assertArrayHasKey('contenido', $this->errores422(fn() => notas_cliente_create(['cliente_id' => $c['id'], 'contenido' => '   '])));
    }

    public function test_borrar(): void
    {
        $c = clientes_create(['nombre' => 'X']);
        $n = notas_cliente_create(['cliente_id' => $c['id'], 'contenido' => 'a']);
        notas_cliente_delete($n['id']);
        $this->assertFalse(crud_exists('notas_cliente', $n['id']));
    }
}
