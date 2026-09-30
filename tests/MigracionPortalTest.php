<?php
declare(strict_types=1);

final class MigracionPortalTest extends DbTestCase
{
    public function test_existen_las_tablas_del_portal(): void
    {
        $tablas = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach ([
            'usuarios_cliente', 'planes', 'suscripciones_cliente', 'solicitudes_suscripcion',
            'servicios', 'servicio_eventos', 'categorias_ticket', 'tickets', 'tareas_cliente',
        ] as $tabla) {
            $this->assertContains($tabla, $tablas, "Falta la tabla $tabla");
        }
    }

    public function test_la_tabla_suscripciones_de_gastos_propios_no_se_toca(): void
    {
        $columnas = db()->query('SHOW COLUMNS FROM suscripciones')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('servicio', $columnas);
        $this->assertContains('fecha_proximo_cobro', $columnas);
        $this->assertNotContains('cliente_id', $columnas);
    }

    public function test_categorias_iniciales_con_su_mapeo(): void
    {
        $filas = q_all('SELECT nombre, tipo, prioridad_default FROM categorias_ticket ORDER BY orden');
        $this->assertSame(
            ['Algo no funciona o está caído', 'Error visual o de datos', 'Cambio de contenido', 'Nueva funcionalidad', 'Mejora de algo existente', 'Consulta'],
            array_column($filas, 'nombre')
        );
        $this->assertSame(['fix', 'fix', 'tarea', 'tarea', 'tarea', 'tarea'], array_column($filas, 'tipo'));
        $this->assertSame(['alta', 'media', 'baja', 'media', 'media', 'baja'], array_column($filas, 'prioridad_default'));
    }

    public function test_los_fixs_viejos_quedan_sin_ticket(): void
    {
        $c = $this->crearCliente();
        $id = crud_insert('fixs', ['cliente_id' => $c, 'titulo' => 'Viejo', 'fecha_reportado' => '2026-01-01']);
        $this->assertNull(q_val('SELECT ticket_id FROM fixs WHERE id = ?', [$id]));
    }

    public function test_login_intentos_es_del_ambito_admin_por_defecto(): void
    {
        db()->exec("INSERT INTO login_intentos (ip) VALUES ('1.2.3.4')");
        $this->assertSame('admin', q_val("SELECT ambito FROM login_intentos WHERE ip = '1.2.3.4'"));
        $this->assertNull(q_val("SELECT clave FROM login_intentos WHERE ip = '1.2.3.4'"));
    }

    public function test_un_cliente_tiene_un_solo_acceso(): void
    {
        $c = $this->crearCliente();
        $this->crearUsuarioCliente($c, 'uno@x.com');
        $this->expectException(PDOException::class);
        $this->crearUsuarioCliente($c, 'dos@x.com');
    }

    public function test_el_email_del_acceso_es_unico_sin_importar_mayusculas(): void
    {
        $this->crearUsuarioCliente($this->crearCliente('A'), 'Ana@Sol.com');
        $this->expectException(PDOException::class);
        $this->crearUsuarioCliente($this->crearCliente('B'), 'ana@sol.com');
    }

    public function test_borrar_el_cliente_borra_su_acceso(): void
    {
        $c = $this->crearCliente();
        $this->crearUsuarioCliente($c);
        crud_delete('clientes', $c);
        $this->assertSame(0, (int)q_val('SELECT COUNT(*) FROM usuarios_cliente WHERE cliente_id = ?', [$c]));
    }

    public function test_un_ticket_solo_puede_tener_un_fix(): void
    {
        $c = $this->crearCliente();
        $cat = (int)q_val('SELECT id FROM categorias_ticket ORDER BY orden LIMIT 1');
        $t = crud_insert('tickets', ['cliente_id' => $c, 'categoria_id' => $cat, 'tipo' => 'fix', 'asunto' => 'A', 'descripcion' => 'B']);
        crud_insert('fixs', ['cliente_id' => $c, 'ticket_id' => $t, 'titulo' => 'F1', 'fecha_reportado' => '2026-01-01']);
        $this->expectException(PDOException::class);
        crud_insert('fixs', ['cliente_id' => $c, 'ticket_id' => $t, 'titulo' => 'F2', 'fecha_reportado' => '2026-01-01']);
    }

    public function test_borrar_el_ticket_deja_el_fix_sin_ticket(): void
    {
        $c = $this->crearCliente();
        $cat = (int)q_val('SELECT id FROM categorias_ticket ORDER BY orden LIMIT 1');
        $t = crud_insert('tickets', ['cliente_id' => $c, 'categoria_id' => $cat, 'tipo' => 'fix', 'asunto' => 'A', 'descripcion' => 'B']);
        $f = crud_insert('fixs', ['cliente_id' => $c, 'ticket_id' => $t, 'titulo' => 'F', 'fecha_reportado' => '2026-01-01']);
        crud_delete('tickets', $t);
        $this->assertNull(q_val('SELECT ticket_id FROM fixs WHERE id = ?', [$f]));
    }
}
