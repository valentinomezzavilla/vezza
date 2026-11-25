<?php
declare(strict_types=1);

final class MigracionPagosTest extends DbTestCase
{
    /** @return list<string> */
    private function columnasDe(string $tabla): array
    {
        return array_column(q_all(
            'SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$tabla]
        ), 'c');
    }

    public function test_las_columnas_nuevas_existen(): void
    {
        $esperadas = [
            'suscripciones_cliente' => ['monto', 'moneda', 'modalidad', 'mp_preapproval_id', 'mp_preapproval_estado', 'mp_init_point', 'pausada', 'aviso_baja_enviado_en', 'debito_al_cancelar'],
            'cobros' => ['suscripcion_cliente_id', 'mp_payment_id'],
            'mp_notificaciones' => ['id', 'tipo', 'recurso_id', 'payload', 'recibida_en', 'procesada_en', 'sin_cobro', 'error'],
            'suscripciones_precio_historial' => ['id', 'suscripcion_cliente_id', 'monto_anterior', 'monto_nuevo', 'moneda', 'creado_en', 'avisado_en'],
        ];
        foreach ($esperadas as $tabla => $columnas) {
            $existentes = $this->columnasDe($tabla);
            foreach ($columnas as $columna) {
                $this->assertContains($columna, $existentes, "Falta la columna $tabla.$columna");
            }
        }
    }

    public function test_ya_no_existen_los_links_ni_los_avisos(): void
    {
        foreach (['periodo', 'mp_preference_id', 'link_pago'] as $columna) {
            $this->assertNotContains($columna, $this->columnasDe('cobros'), "cobros.$columna no debería existir");
        }
        foreach (['cobro_id', 'estado_pago'] as $columna) {
            $this->assertNotContains($columna, $this->columnasDe('mp_notificaciones'), "mp_notificaciones.$columna no debería existir");
        }
        $this->assertSame(0, (int)q_val(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'avisos_pago'"
        ), 'La tabla avisos_pago no debería existir');
    }

    public function test_la_modalidad_solo_admite_debito_y_transferencia(): void
    {
        $tipo = q_val(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suscripciones_cliente' AND COLUMN_NAME = 'modalidad'"
        );
        $this->assertSame("enum('debito','transferencia')", $tipo);
    }

    public function test_un_pago_de_mercado_pago_no_se_registra_dos_veces(): void
    {
        $s = $this->crearSuscripcion();
        $c = (int)q_val('SELECT cliente_id FROM suscripciones_cliente WHERE id = ?', [$s]);

        // Los cobros sin pago de Mercado Pago (nulo) se pueden repetir.
        $this->crearCobro($c, $s, null);
        $this->crearCobro($c, $s, null);
        $this->crearCobro($c, $s, 'pago-1');
        $this->crearCobro($c, $s, 'pago-2');
        $this->assertSame(4, (int)q_val('SELECT COUNT(*) FROM cobros WHERE cliente_id = ?', [$c]));

        $this->expectException(PDOException::class);
        $this->crearCobro($c, $s, 'pago-1');
    }

    public function test_un_preapproval_no_se_repite(): void
    {
        $a = $this->crearSuscripcion();
        $b = $this->crearSuscripcion();
        crud_update('suscripciones_cliente', $a, ['mp_preapproval_id' => 'pre-1']);

        $this->expectException(PDOException::class);
        crud_update('suscripciones_cliente', $b, ['mp_preapproval_id' => 'pre-1']);
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
        $cobro = $this->crearCobro($c, $s, 'pago-1');

        crud_delete('suscripciones_cliente', $s);

        $this->assertSame(1, (int)q_val('SELECT COUNT(*) FROM cobros WHERE id = ?', [$cobro]));
        $this->assertNull(q_val('SELECT suscripcion_cliente_id FROM cobros WHERE id = ?', [$cobro]));
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

    private function crearCobro(int $clienteId, ?int $suscripcionId, ?string $mpPaymentId): int
    {
        return crud_insert('cobros', [
            'cliente_id' => $clienteId,
            'suscripcion_cliente_id' => $suscripcionId,
            'mp_payment_id' => $mpPaymentId,
            'monto' => '1000.00',
            'moneda' => 'ARS',
            'fecha_vencimiento' => '2026-10-10',
        ]);
    }
}
