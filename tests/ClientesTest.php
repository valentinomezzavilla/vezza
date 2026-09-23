<?php
declare(strict_types=1);

final class ClientesTest extends DbTestCase
{
    public function test_crear_y_obtener(): void
    {
        $c = clientes_create(['nombre' => '  Panadería Sol ', 'email' => 'hola@sol.com', 'fecha_inicio' => '2026-01-10']);
        $this->assertSame('Panadería Sol', $c['nombre']);
        $this->assertSame('activo', $c['estado']);
        $this->assertSame('2026-01-10', $c['fecha_inicio']);
        $this->assertSame($c['id'], clientes_get($c['id'])['id']);
    }

    public function test_nombre_obligatorio_y_email_valido(): void
    {
        $campos = $this->errores422(fn() => clientes_create(['email' => 'no-es-mail']));
        $this->assertArrayHasKey('nombre', $campos);
        $this->assertArrayHasKey('email', $campos);
    }

    public function test_estado_invalido(): void
    {
        $this->assertArrayHasKey('estado', $this->errores422(fn() => clientes_create(['nombre' => 'X', 'estado' => 'borrado'])));
    }

    public function test_update_vaciar_campo_lo_deja_null(): void
    {
        $c = clientes_create(['nombre' => 'X', 'email' => 'a@b.com', 'telefono' => '11 5555-5555']);
        $c2 = clientes_update($c['id'], ['email' => '']);
        $this->assertNull($c2['email']);
        $this->assertSame('11 5555-5555', $c2['telefono']);
        $this->assertSame('X', $c2['nombre']);
    }

    public function test_list_filtra_y_busca(): void
    {
        clientes_create(['nombre' => 'Alfa']);
        clientes_create(['nombre' => 'Beta', 'estado' => 'pausado']);
        clientes_create(['nombre' => 'Alfalfa', 'estado' => 'finalizado']);
        $this->assertSame(['Alfa', 'Alfalfa'], array_column(clientes_list(['q' => 'ALFA']), 'nombre'));
        $this->assertSame(['Beta'], array_column(clientes_list(['estado' => 'pausado']), 'nombre'));
        $this->assertSame(['Alfa', 'Beta', 'Alfalfa'], array_column(clientes_list([]), 'nombre'));
        $this->assertSame([], clientes_list(['q' => '%']));
    }

    public function test_list_incluye_contadores(): void
    {
        $c = clientes_create(['nombre' => 'X']);
        crud_insert('procesos', ['cliente_id' => $c['id'], 'titulo' => 'P1', 'estado' => 'en_curso']);
        crud_insert('procesos', ['cliente_id' => $c['id'], 'titulo' => 'P2', 'estado' => 'entregado']);
        crud_insert('fixs', ['cliente_id' => $c['id'], 'titulo' => 'F1', 'fecha_reportado' => '2026-01-01']);
        crud_insert('fixs', ['cliente_id' => $c['id'], 'titulo' => 'F2', 'estado' => 'resuelto', 'fecha_reportado' => '2026-01-01']);
        $fila = clientes_list([])[0];
        $this->assertSame(1, $fila['procesos_activos']);
        $this->assertSame(1, $fila['fixs_abiertos']);
    }

    public function test_borrar_cliente_con_cobros_da_409(): void
    {
        $c = clientes_create(['nombre' => 'X']);
        crud_insert('cobros', ['cliente_id' => $c['id'], 'monto' => '10.00', 'moneda' => 'ARS', 'fecha_vencimiento' => '2026-01-01']);
        $this->assertHttp(409, fn() => clientes_delete($c['id']));
        $this->assertTrue(crud_exists('clientes', $c['id']));
    }

    public function test_borrar_cliente_sin_cobros_borra_en_cascada(): void
    {
        $c = clientes_create(['nombre' => 'X']);
        $nota = crud_insert('notas_cliente', ['cliente_id' => $c['id'], 'contenido' => 'hola']);
        $proc = crud_insert('procesos', ['cliente_id' => $c['id'], 'titulo' => 'P']);
        clientes_delete($c['id']);
        $this->assertFalse(crud_exists('clientes', $c['id']));
        $this->assertFalse(crud_exists('notas_cliente', $nota));
        $this->assertFalse(crud_exists('procesos', $proc));
    }

    public function test_get_inexistente_da_404(): void
    {
        $this->assertHttp(404, fn() => clientes_get(999999));
    }
}
