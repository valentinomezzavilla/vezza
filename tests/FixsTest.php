<?php
declare(strict_types=1);

final class FixsTest extends DbTestCase
{
    private int $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        clock_set('2026-06-15');
        $this->cliente = clientes_create(['nombre' => 'Acme'])['id'];
    }

    private function fix(string $titulo, array $extra = []): array
    {
        return fixs_create($extra + ['cliente_id' => $this->cliente, 'titulo' => $titulo]);
    }

    private function titulos(string $estado): array
    {
        return array_column(fixs_list(['estado' => $estado]), 'titulo');
    }

    public function test_crear_con_valores_por_defecto(): void
    {
        $f = $this->fix('Botón roto en mobile');
        $this->assertSame('reportado', $f['estado']);
        $this->assertSame('2026-06-15', $f['fecha_reportado']);
        $this->assertNull($f['fecha_resuelto']);
        $this->assertSame('Acme', $f['cliente_nombre']);
        $this->assertSame(0, $f['orden']);
    }

    public function test_resolver_y_reabrir(): void
    {
        $f = $this->fix('X');
        $this->assertSame('2026-06-15', fixs_update($f['id'], ['estado' => 'resuelto'])['fecha_resuelto']);
        clock_set('2026-06-20');
        $this->assertSame('2026-06-15', fixs_update($f['id'], ['titulo' => 'X2'])['fecha_resuelto']);
        $this->assertNull(fixs_update($f['id'], ['estado' => 'en_progreso'])['fecha_resuelto']);
        $this->assertSame('2026-06-15', $this->fix('Y', ['estado' => 'resuelto', 'fecha_resuelto' => '2026-06-15'])['fecha_resuelto']);
        $this->assertSame('2026-06-20', $this->fix('Z', ['estado' => 'resuelto'])['fecha_resuelto']);
    }

    public function test_proceso_de_otro_cliente_falla(): void
    {
        $otro = clientes_create(['nombre' => 'Otro'])['id'];
        $ajeno = procesos_create(['cliente_id' => $otro, 'titulo' => 'Ajeno']);
        $this->assertArrayHasKey('proceso_id', $this->errores422(fn() => $this->fix('X', ['proceso_id' => $ajeno['id']])));
        $propio = procesos_create(['cliente_id' => $this->cliente, 'titulo' => 'Landing']);
        $f = $this->fix('X', ['proceso_id' => $propio['id']]);
        $this->assertSame('Landing', $f['proceso_titulo']);
        $this->assertArrayHasKey('proceso_id', $this->errores422(fn() => fixs_update($f['id'], ['cliente_id' => $otro])));
    }

    public function test_mover_con_antes_de_y_cambiar_columna(): void
    {
        $otro = clientes_create(['nombre' => 'Otro'])['id'];
        $a = $this->fix('A');
        $this->fix('B', ['cliente_id' => $otro]);
        $c = $this->fix('C');
        fixs_update($c['id'], ['antes_de' => $a['id']]);
        $this->assertSame(['C', 'A', 'B'], $this->titulos('reportado'));
        fixs_update($a['id'], ['estado' => 'en_progreso']);
        $this->assertSame(['C', 'B'], $this->titulos('reportado'));
        $this->assertSame(['A'], $this->titulos('en_progreso'));
        $this->assertSame(['C', 'A'], array_column(fixs_list(['cliente_id' => (string)$this->cliente]), 'titulo'));
    }

    public function test_validaciones_y_borrado(): void
    {
        $campos = $this->errores422(fn() => fixs_create(['titulo' => '']));
        $this->assertArrayHasKey('cliente_id', $campos);
        $this->assertArrayHasKey('titulo', $campos);
        $f = $this->fix('X');
        fixs_delete($f['id']);
        $this->assertFalse(crud_exists('fixs', $f['id']));
    }
}
