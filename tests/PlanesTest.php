<?php
declare(strict_types=1);

final class PlanesTest extends DbTestCase
{
    private function plan(array $extra = []): array
    {
        return planes_create($extra + [
            'nombre' => 'Mantenimiento', 'monto' => '25000', 'moneda' => 'ars', 'frecuencia' => 'mensual',
        ]);
    }

    public function test_crear_normaliza_monto_y_moneda(): void
    {
        $p = $this->plan(['descripcion' => 'Hosting y soporte']);
        $this->assertSame('Mantenimiento', $p['nombre']);
        $this->assertSame('25000.00', $p['monto']);
        $this->assertSame('ARS', $p['moneda']);
        $this->assertSame('mensual', $p['frecuencia']);
        $this->assertSame(1, (int)$p['activo']);
    }

    public function test_monto_vacio_moneda_invalida_y_obligatorios(): void
    {
        $this->assertArrayHasKey('monto', $this->errores422(fn() => $this->plan(['monto' => ''])));
        $this->assertArrayHasKey('moneda', $this->errores422(fn() => $this->plan(['moneda' => 'pesos'])));
        $campos = $this->errores422(fn() => planes_create([]));
        foreach (['nombre', 'monto', 'moneda', 'frecuencia'] as $campo) {
            $this->assertArrayHasKey($campo, $campos);
        }
        $this->assertArrayHasKey('frecuencia', $this->errores422(fn() => $this->plan(['frecuencia' => 'semanal'])));
    }

    public function test_listar_ordena_por_orden(): void
    {
        $c = $this->plan(['nombre' => 'C', 'orden' => 3]);
        $a = $this->plan(['nombre' => 'A', 'orden' => 1]);
        $b = $this->plan(['nombre' => 'B', 'orden' => 2]);
        $this->assertSame([$a['id'], $b['id'], $c['id']], array_column(planes_list([]), 'id'));
    }

    public function test_actualizar_y_404(): void
    {
        $p = $this->plan();
        $nuevo = planes_update($p['id'], ['monto' => '30.000,50', 'activo' => false]);
        $this->assertSame('30000.50', $nuevo['monto']);
        $this->assertSame(0, (int)$nuevo['activo']);
        $this->assertSame('Mantenimiento', $nuevo['nombre']);
        $this->assertHttp(404, fn() => planes_get(999999));
        $this->assertHttp(404, fn() => planes_update(999999, ['nombre' => 'X']));
    }

    public function test_borrar_un_plan_sin_suscripciones_y_con_suscripciones(): void
    {
        $libre = $this->plan(['nombre' => 'Libre']);
        planes_delete($libre['id']);
        $this->assertHttp(404, fn() => planes_get($libre['id']));

        $usado = $this->plan(['nombre' => 'Usado']);
        suscripciones_cliente_create([
            'cliente_id' => $this->crearCliente(), 'plan_id' => $usado['id'], 'fecha_proximo_cobro' => '2026-04-01',
        ]);
        $this->assertHttp(409, fn() => planes_delete($usado['id']));
        $this->assertSame('Usado', planes_get($usado['id'])['nombre']);
        $this->assertHttp(404, fn() => planes_delete(999999));
    }
}
