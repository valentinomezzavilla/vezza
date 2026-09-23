<?php
declare(strict_types=1);

final class CrudTest extends DbTestCase
{
    private function cliente(string $nombre = 'Acme'): int
    {
        return crud_insert('clientes', ['nombre' => $nombre]);
    }

    public function test_insert_find_update_delete(): void
    {
        $id = $this->cliente();
        $this->assertSame('Acme', crud_find('clientes', $id)['nombre']);
        crud_update('clientes', $id, ['rubro' => 'Gastronomía']);
        $fila = crud_find('clientes', $id);
        $this->assertSame('Gastronomía', $fila['rubro']);
        $this->assertSame('Acme', $fila['nombre']);
        crud_delete('clientes', $id);
        $this->assertFalse(crud_exists('clientes', $id));
    }

    public function test_find_update_delete_inexistente_da_404(): void
    {
        $this->assertHttp(404, fn() => crud_find('clientes', 999999));
        $this->assertHttp(404, fn() => crud_update('clientes', 999999, ['nombre' => 'x']));
        $this->assertHttp(404, fn() => crud_delete('clientes', 999999));
    }

    public function test_fk_valida_existencia(): void
    {
        $id = $this->cliente();
        $regla = ['type' => 'fk', 'table' => 'clientes'];
        $this->assertSame($id, validar_valor((string)$id, $regla));
        $this->expectException(InvalidArgumentException::class);
        validar_valor(999999, $regla);
    }

    public function test_helpers_de_consulta(): void
    {
        $id = $this->cliente('Beta');
        $this->assertSame('Beta', q_val('SELECT nombre FROM clientes WHERE id = ?', [$id]));
        $this->assertNull(q_one('SELECT * FROM clientes WHERE id = ?', [999999]));
        $this->assertCount(1, q_all('SELECT * FROM clientes WHERE id = ?', [$id]));
        [$partes, $params] = where_eq(['a' => 1, 'b' => null], ['a' => 'x.a', 'b' => 'x.b', 'c' => 'x.c']);
        $this->assertSame(['x.a = ?'], $partes);
        $this->assertSame([1], $params);
        $this->assertSame(' WHERE x.a = ?', sql_where($partes));
        $this->assertSame('', sql_where([]));
    }

    public function test_siguiente_orden_y_reordenar(): void
    {
        $c = $this->cliente();
        $ids = [];
        foreach (['A', 'B', 'C'] as $t) {
            $ids[$t] = crud_insert('procesos', [
                'cliente_id' => $c, 'titulo' => $t,
                'orden' => siguiente_orden('procesos', 'estado', 'por_hacer'),
            ]);
        }
        $orden = fn() => q_all("SELECT titulo FROM procesos WHERE estado = 'por_hacer' ORDER BY orden, id");
        $this->assertSame(['A', 'B', 'C'], array_column($orden(), 'titulo'));

        reordenar('procesos', $ids['C'], 'estado', 'por_hacer', $ids['A']);
        $this->assertSame(['C', 'A', 'B'], array_column($orden(), 'titulo'));

        reordenar('procesos', $ids['C'], 'estado', 'por_hacer', null);
        $this->assertSame(['A', 'B', 'C'], array_column($orden(), 'titulo'));

        reordenar('procesos', $ids['A'], 'estado', 'por_hacer', 999999);
        $this->assertSame(['B', 'C', 'A'], array_column($orden(), 'titulo'));
    }

    public function test_tx_dentro_de_transaccion_abierta(): void
    {
        $this->assertSame(5, tx(fn() => 5));
        $this->assertTrue(db()->inTransaction());
    }
}
