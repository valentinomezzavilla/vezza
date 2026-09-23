<?php
declare(strict_types=1);

final class ProcesosTest extends DbTestCase
{
    private function cliente(string $nombre = 'Acme'): int
    {
        return clientes_create(['nombre' => $nombre])['id'];
    }

    private function titulos(string $estado): array
    {
        return array_column(procesos_list(['estado' => $estado]), 'titulo');
    }

    public function test_crear_con_valores_por_defecto(): void
    {
        $p = procesos_create(['cliente_id' => $this->cliente(), 'titulo' => 'Landing page']);
        $this->assertSame('por_hacer', $p['estado']);
        $this->assertSame('media', $p['prioridad']);
        $this->assertSame('Acme', $p['cliente_nombre']);
        $this->assertSame(0, $p['subtareas_total']);
        $this->assertSame(0, $p['orden']);
    }

    public function test_orden_secuencial_por_columna(): void
    {
        $c = $this->cliente();
        procesos_create(['cliente_id' => $c, 'titulo' => 'A']);
        $b = procesos_create(['cliente_id' => $c, 'titulo' => 'B']);
        $x = procesos_create(['cliente_id' => $c, 'titulo' => 'X', 'estado' => 'en_curso']);
        $this->assertSame(1, $b['orden']);
        $this->assertSame(0, $x['orden']);
    }

    public function test_validaciones(): void
    {
        $campos = $this->errores422(fn() => procesos_create(['titulo' => '']));
        $this->assertArrayHasKey('cliente_id', $campos);
        $this->assertArrayHasKey('titulo', $campos);
        $c = $this->cliente();
        $campos = $this->errores422(fn() => procesos_create([
            'cliente_id' => $c, 'titulo' => 'X', 'fecha_inicio' => '2026-05-10', 'fecha_entrega_estimada' => '2026-05-01',
        ]));
        $this->assertArrayHasKey('fecha_entrega_estimada', $campos);
    }

    public function test_update_parcial_valida_fechas_contra_lo_guardado_y_no_cambia_cliente(): void
    {
        $c = $this->cliente();
        $otro = $this->cliente('Otro');
        $p = procesos_create(['cliente_id' => $c, 'titulo' => 'X', 'fecha_entrega_estimada' => '2026-05-01']);
        $this->assertArrayHasKey('fecha_entrega_estimada', $this->errores422(fn() => procesos_update($p['id'], ['fecha_inicio' => '2026-06-01'])));
        $p2 = procesos_update($p['id'], ['prioridad' => 'alta', 'cliente_id' => $otro]);
        $this->assertSame('alta', $p2['prioridad']);
        $this->assertSame('X', $p2['titulo']);
        $this->assertSame($c, $p2['cliente_id']);
    }

    public function test_list_filtra_y_ordena_por_estado_y_orden(): void
    {
        $a = $this->cliente('A');
        $b = $this->cliente('B');
        procesos_create(['cliente_id' => $a, 'titulo' => 'A1', 'estado' => 'entregado']);
        procesos_create(['cliente_id' => $a, 'titulo' => 'A2']);
        procesos_create(['cliente_id' => $b, 'titulo' => 'B1', 'estado' => 'en_curso']);
        $this->assertSame(['A2', 'B1', 'A1'], array_column(procesos_list([]), 'titulo'));
        $this->assertSame(['A2', 'A1'], array_column(procesos_list(['cliente_id' => (string)$a]), 'titulo'));
        $this->assertSame(['B1'], $this->titulos('en_curso'));
    }

    public function test_cambiar_estado_sin_antes_de_va_al_final_de_la_columna(): void
    {
        $c = $this->cliente();
        procesos_create(['cliente_id' => $c, 'titulo' => 'Y', 'estado' => 'en_curso']);
        $x = procesos_create(['cliente_id' => $c, 'titulo' => 'X']);
        procesos_update($x['id'], ['estado' => 'en_curso']);
        $this->assertSame(['Y', 'X'], $this->titulos('en_curso'));
    }

    public function test_reordenar_con_antes_de_ignora_tarjetas_de_otros_clientes(): void
    {
        $a = $this->cliente('A');
        $b = $this->cliente('B');
        $a1 = procesos_create(['cliente_id' => $a, 'titulo' => 'A1']);
        procesos_create(['cliente_id' => $b, 'titulo' => 'B1']);
        $a2 = procesos_create(['cliente_id' => $a, 'titulo' => 'A2']);

        // Kanban filtrado por A muestra [A1, A2]; se arrastra A2 arriba de A1.
        procesos_update($a2['id'], ['estado' => 'por_hacer', 'antes_de' => $a1['id']]);
        $this->assertSame(['A2', 'A1', 'B1'], $this->titulos('por_hacer'));

        // Se suelta A1 al final de la columna visible (antes_de = null).
        procesos_update($a1['id'], ['antes_de' => null]);
        $this->assertSame(['A2', 'B1', 'A1'], $this->titulos('por_hacer'));
    }

    public function test_mover_a_otra_columna_antes_de_una_tarjeta(): void
    {
        $c = $this->cliente();
        $y = procesos_create(['cliente_id' => $c, 'titulo' => 'Y', 'estado' => 'en_revision']);
        $x = procesos_create(['cliente_id' => $c, 'titulo' => 'X']);
        procesos_update($x['id'], ['estado' => 'en_revision', 'antes_de' => $y['id']]);
        $this->assertSame(['X', 'Y'], $this->titulos('en_revision'));
        $this->assertSame([], $this->titulos('por_hacer'));
    }

    public function test_contadores_de_subtareas_y_borrado_en_cascada(): void
    {
        $p = procesos_create(['cliente_id' => $this->cliente(), 'titulo' => 'X']);
        $s1 = subtareas_create(['proceso_id' => $p['id'], 'titulo' => 'a']);
        subtareas_create(['proceso_id' => $p['id'], 'titulo' => 'b']);
        subtareas_update($s1['id'], ['completada' => true]);
        $p2 = procesos_get($p['id']);
        $this->assertSame(2, $p2['subtareas_total']);
        $this->assertSame(1, $p2['subtareas_hechas']);
        procesos_delete($p['id']);
        $this->assertFalse(crud_exists('subtareas', $s1['id']));
    }

    public function test_validar_proceso_de_cliente(): void
    {
        $a = $this->cliente('A');
        $b = $this->cliente('B');
        $p = procesos_create(['cliente_id' => $a, 'titulo' => 'X']);
        validar_proceso_de_cliente(null, $b);
        validar_proceso_de_cliente($p['id'], $a);
        $this->assertArrayHasKey('proceso_id', $this->errores422(fn() => validar_proceso_de_cliente($p['id'], $b)));
    }
}
