<?php
declare(strict_types=1);

final class MigracionPagosTest extends DbTestCase
{
    public function test_las_columnas_nuevas_existen(): void
    {
        $esperadas = [
            'suscripciones_cliente' => ['monto', 'moneda', 'modalidad', 'mp_preapproval_id', 'mp_preapproval_estado', 'mp_init_point', 'pausada'],
            'cobros' => ['suscripcion_cliente_id', 'periodo', 'mp_payment_id', 'mp_preference_id', 'link_pago'],
            'mp_notificaciones' => ['id', 'tipo', 'recurso_id', 'payload', 'recibida_en', 'procesada_en', 'sin_cobro', 'error', 'cobro_id', 'estado_pago'],
            'avisos_pago' => ['id', 'cobro_id', 'tipo', 'canal', 'numero', 'enviado_en', 'ok'],
            'suscripciones_precio_historial' => ['id', 'suscripcion_cliente_id', 'monto_anterior', 'monto_nuevo', 'moneda', 'creado_en', 'avisado_en'],
        ];
        foreach ($esperadas as $tabla => $columnas) {
            $existentes = q_all(
                'SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$tabla]
            );
            $existentes = array_column($existentes, 'c');
            foreach ($columnas as $columna) {
                $this->assertContains($columna, $existentes, "Falta la columna $tabla.$columna");
            }
        }
    }

    public function test_no_se_puede_repetir_un_periodo_por_suscripcion(): void
    {
        $s = $this->crearSuscripcion();
        $c = (int)q_val('SELECT cliente_id FROM suscripciones_cliente WHERE id = ?', [$s]);

        // Sin suscripción ni período (cobros sueltos) se pueden repetir.
        $this->crearCobro($c, null, null);
        $this->crearCobro($c, null, null);

        $this->crearCobro($c, $s, '2026-10');
        $this->crearCobro($c, $s, '2026-11');
        $this->assertSame(4, (int)q_val('SELECT COUNT(*) FROM cobros WHERE cliente_id = ?', [$c]));

        $this->expectException(PDOException::class);
        $this->crearCobro($c, $s, '2026-10');
    }

    public function test_no_se_repite_un_aviso(): void
    {
        $s = $this->crearSuscripcion();
        $c = (int)q_val('SELECT cliente_id FROM suscripciones_cliente WHERE id = ?', [$s]);
        $cobro = $this->crearCobro($c, $s, '2026-10');

        crud_insert('avisos_pago', ['cobro_id' => $cobro, 'tipo' => 'previo', 'canal' => 'mail', 'numero' => 0, 'ok' => 1]);
        // Otro canal o otro número sí entra.
        crud_insert('avisos_pago', ['cobro_id' => $cobro, 'tipo' => 'previo', 'canal' => 'whatsapp', 'numero' => 0, 'ok' => 1]);
        crud_insert('avisos_pago', ['cobro_id' => $cobro, 'tipo' => 'vencido', 'canal' => 'mail', 'numero' => 1, 'ok' => 1]);

        $this->expectException(PDOException::class);
        crud_insert('avisos_pago', ['cobro_id' => $cobro, 'tipo' => 'previo', 'canal' => 'mail', 'numero' => 0, 'ok' => 1]);
    }

    public function test_un_aviso_nace_sin_enviar(): void
    {
        $s = $this->crearSuscripcion();
        $c = (int)q_val('SELECT cliente_id FROM suscripciones_cliente WHERE id = ?', [$s]);
        $cobro = $this->crearCobro($c, $s, '2026-10');

        $id = crud_insert('avisos_pago', ['cobro_id' => $cobro, 'tipo' => 'previo', 'canal' => 'mail']);

        $this->assertSame(0, (int)q_val('SELECT ok FROM avisos_pago WHERE id = ?', [$id]));
    }

    public function test_las_suscripciones_existentes_copian_monto_y_moneda_del_plan(): void
    {
        $plan = crud_insert('planes', ['nombre' => 'Hosting', 'monto' => '15000.50', 'moneda' => 'USD', 'frecuencia' => 'mensual']);
        $c = $this->crearCliente();
        // Una suscripción "vieja": se crea sin monto ni moneda propios.
        $s = crud_insert('suscripciones_cliente', ['cliente_id' => $c, 'plan_id' => $plan, 'fecha_inicio' => '2026-01-01']);

        $sql = (string)file_get_contents(__DIR__ . '/../db/migrations/003_pagos.sql');
        $this->assertSame(1, preg_match('/^UPDATE suscripciones_cliente\b.*?;/ms', $sql, $m), 'La migración no tiene el UPDATE de backfill');
        db()->exec($m[0]);

        $fila = q_one('SELECT monto, moneda, modalidad FROM suscripciones_cliente WHERE id = ?', [$s]);
        $this->assertSame('15000.50', $fila['monto']);
        $this->assertSame('USD', $fila['moneda']);
        $this->assertSame('transferencia', $fila['modalidad']);
    }

    public function test_borrar_una_suscripcion_deja_el_cobro(): void
    {
        $s = $this->crearSuscripcion();
        $c = (int)q_val('SELECT cliente_id FROM suscripciones_cliente WHERE id = ?', [$s]);
        $cobro = $this->crearCobro($c, $s, '2026-10');

        crud_delete('suscripciones_cliente', $s);

        $this->assertSame(1, (int)q_val('SELECT COUNT(*) FROM cobros WHERE id = ?', [$cobro]));
        $this->assertNull(q_val('SELECT suscripcion_cliente_id FROM cobros WHERE id = ?', [$cobro]));
    }

    public function test_borrar_un_cobro_deja_la_notificacion_sin_cobro(): void
    {
        $s = $this->crearSuscripcion();
        $c = (int)q_val('SELECT cliente_id FROM suscripciones_cliente WHERE id = ?', [$s]);
        $cobro = $this->crearCobro($c, $s, '2026-10');
        $n = crud_insert('mp_notificaciones', ['tipo' => 'payment', 'recurso_id' => '123', 'payload' => '{}', 'cobro_id' => $cobro, 'estado_pago' => 'approved']);

        crud_delete('cobros', $cobro);

        $this->assertNull(q_val('SELECT cobro_id FROM mp_notificaciones WHERE id = ?', [$n]));
        $this->assertSame('approved', q_val('SELECT estado_pago FROM mp_notificaciones WHERE id = ?', [$n]));
    }

    private function crearSuscripcion(): int
    {
        $plan = crud_insert('planes', ['nombre' => 'Plan', 'monto' => '1000.00', 'moneda' => 'ARS', 'frecuencia' => 'mensual']);
        return crud_insert('suscripciones_cliente', [
            'cliente_id' => $this->crearCliente(),
            'plan_id' => $plan,
            'fecha_inicio' => '2026-10-01',
        ]);
    }

    private function crearCobro(int $clienteId, ?int $suscripcionId, ?string $periodo): int
    {
        return crud_insert('cobros', [
            'cliente_id' => $clienteId,
            'suscripcion_cliente_id' => $suscripcionId,
            'periodo' => $periodo,
            'monto' => '1000.00',
            'moneda' => 'ARS',
            'fecha_vencimiento' => '2026-10-10',
        ]);
    }
}
