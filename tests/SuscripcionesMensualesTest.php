<?php
declare(strict_types=1);

final class SuscripcionesMensualesTest extends DbTestCase
{
    private int $cliente;
    private array $plan;

    protected function setUp(): void
    {
        parent::setUp();
        clock_set('2026-03-10');
        $this->cliente = clientes_create(['nombre' => 'Acme', 'email' => 'acme@sol.com', 'telefono' => '11 5555-1234'])['id'];
        $this->plan = planes_create(['nombre' => 'Mantenimiento', 'monto' => '25000', 'moneda' => 'ARS', 'frecuencia' => 'mensual']);
    }

    protected function tearDown(): void
    {
        MercadoPago::$transporte = null;
        env_set('MP_ACCESS_TOKEN', null);
        parent::tearDown();
    }

    private function susc(string $proximoCobro, array $extra = []): array
    {
        return suscripciones_cliente_create($extra + [
            'cliente_id' => $this->cliente, 'plan_id' => $this->plan['id'], 'fecha_proximo_cobro' => $proximoCobro,
        ]);
    }

    private function filas(array $get = []): array
    {
        return suscripciones_mensuales($get)['filas'];
    }

    // ---- resumen ----

    public function test_el_ingreso_mensual_suma_solo_las_activas_no_pausadas_en_ars(): void
    {
        $this->susc('2026-04-01');
        $this->susc('2026-04-01', ['monto' => '10000']);
        $pausada = $this->susc('2026-04-01', ['monto' => '5000']);
        crud_update('suscripciones_cliente', $pausada['id'], ['pausada' => 1]);
        $cancelada = $this->susc('2026-04-01', ['monto' => '7000']);
        crud_update('suscripciones_cliente', $cancelada['id'], ['estado' => 'cancelada', 'fecha_baja' => '2026-03-01']);

        $resumen = suscripciones_mensuales([])['resumen'];
        $this->assertSame('$35.000', $resumen['ingreso_mensual']);
        $this->assertFalse($resumen['otras_monedas']);

        $usd = planes_create(['nombre' => 'Dólares', 'monto' => '100', 'moneda' => 'USD', 'frecuencia' => 'mensual']);
        $this->susc('2026-04-01', ['plan_id' => $usd['id']]);
        $resumen = suscripciones_mensuales([])['resumen'];
        $this->assertSame('$35.000', $resumen['ingreso_mensual'], 'Las suscripciones en otra moneda no se suman');
        $this->assertTrue($resumen['otras_monedas']);
    }

    public function test_clasifica_al_dia_por_vencer_y_vencido(): void
    {
        $this->susc('2026-03-09'); // vencida
        $this->susc('2026-03-15'); // por vencer
        $this->susc('2026-03-17'); // por vencer (7 días)
        $this->susc('2026-04-15'); // al día

        $resumen = suscripciones_mensuales([])['resumen'];

        $this->assertSame(1, $resumen['vencidas']);
        $this->assertSame(2, $resumen['por_vencer']);
        $this->assertSame(1, $resumen['al_dia']);
    }

    public function test_pausadas_y_canceladas_no_cuentan_en_el_resumen_pero_aparecen_en_las_filas(): void
    {
        $pausada = $this->susc('2026-03-01');
        crud_update('suscripciones_cliente', $pausada['id'], ['pausada' => 1]);
        $cancelada = $this->susc('2026-03-01');
        crud_update('suscripciones_cliente', $cancelada['id'], ['estado' => 'cancelada', 'fecha_baja' => '2026-03-01']);

        $r = suscripciones_mensuales([]);

        $this->assertSame(['al_dia' => 0, 'por_vencer' => 0, 'vencidas' => 0], array_intersect_key($r['resumen'], array_flip(['al_dia', 'por_vencer', 'vencidas'])));
        $this->assertEqualsCanonicalizing([$pausada['id'], $cancelada['id']], array_column($r['filas'], 'id'));
    }

    // ---- filas ----

    public function test_las_vencidas_van_primero_y_las_canceladas_al_final(): void
    {
        $alDia = $this->susc('2026-04-15');
        $vencida = $this->susc('2026-03-01');
        $porVencer = $this->susc('2026-03-12');
        $cancelada = $this->susc('2026-03-01');
        crud_update('suscripciones_cliente', $cancelada['id'], ['estado' => 'cancelada', 'fecha_baja' => '2026-03-01']);

        $this->assertSame(
            [$vencida['id'], $porVencer['id'], $alDia['id'], $cancelada['id']],
            array_column($this->filas(), 'id')
        );
    }

    public function test_filtra_por_cliente(): void
    {
        $otro = clientes_create(['nombre' => 'Otro'])['id'];
        $mia = $this->susc('2026-04-01');
        $this->susc('2026-04-01', ['cliente_id' => $otro]);

        $this->assertSame([$mia['id']], array_column($this->filas(['cliente_id' => (string)$this->cliente]), 'id'));
        $this->assertCount(2, $this->filas());
    }

    public function test_trae_el_ultimo_pago_y_el_debito_pendiente(): void
    {
        $s = $this->susc('2026-03-15');
        $this->assertNull($this->filas()[0]['ultimo_pago']);
        $this->assertFalse($this->filas()[0]['debito_pendiente']);

        suscripcion_pago_manual($s['id'], ['monto' => '20000']);
        clock_set('2026-03-12');
        suscripcion_pago_manual($s['id'], ['monto' => '25000', 'metodo_pago' => 'Efectivo']);
        crud_update('suscripciones_cliente', $s['id'], ['mp_preapproval_id' => 'pre-1', 'mp_preapproval_estado' => 'pendiente']);

        $fila = $this->filas()[0];
        $this->assertSame(['fecha_pago' => '2026-03-12', 'monto' => '25000.00', 'moneda' => 'ARS', 'metodo_pago' => 'Efectivo'], $fila['ultimo_pago']);
        $this->assertTrue($fila['debito_pendiente']);
        $this->assertSame('Acme', $fila['cliente_nombre']);
        $this->assertSame('Mantenimiento', $fila['plan_nombre']);
        $this->assertSame('al_dia', $fila['cobro_estado']);
    }

    // ---- WhatsApp ----

    public function test_el_link_de_whatsapp_solo_en_vencidas_activas_con_telefono_valido(): void
    {
        $vencida = $this->susc('2026-03-09');
        $porVencer = $this->susc('2026-03-12');
        $pausada = $this->susc('2026-03-01');
        crud_update('suscripciones_cliente', $pausada['id'], ['pausada' => 1]);
        $sinTel = clientes_create(['nombre' => 'Sin teléfono'])['id'];
        $sinTelefono = $this->susc('2026-03-01', ['cliente_id' => $sinTel]);

        $urls = array_column($this->filas(), 'whatsapp_url', 'id');

        $this->assertNull($urls[$porVencer['id']]);
        $this->assertNull($urls[$pausada['id']]);
        $this->assertNull($urls[$sinTelefono['id']]);
        $this->assertStringStartsWith('https://wa.me/5491155551234?text=', $urls[$vencida['id']]);
        $mensaje = urldecode(substr($urls[$vencida['id']], strlen('https://wa.me/5491155551234?text=')));
        $this->assertSame('Hola Acme, te escribo por tu pago mensual de Mantenimiento ($25.000), que venció el 9 mar. ¿Lo pudiste hacer? Gracias.', $mensaje);
    }

    public function test_normalizar_telefonos(): void
    {
        $this->assertSame('5491155551234', whatsapp_telefono_normalizar('11 5555-1234'));
        $this->assertSame('5491155551234', whatsapp_telefono_normalizar('+54 9 11 5555 1234'));
        $this->assertSame('5491155551234', whatsapp_telefono_normalizar('011 5555-1234'));
        $this->assertSame('5491155551234', whatsapp_telefono_normalizar('5491155551234'));
        $this->assertSame('5493515551234', whatsapp_telefono_normalizar('(351) 555 1234'));
        $this->assertNull(whatsapp_telefono_normalizar('llamar a Ana'));
        $this->assertNull(whatsapp_telefono_normalizar('1234'));
        $this->assertNull(whatsapp_telefono_normalizar(''));
    }

    public function test_fecha_corta(): void
    {
        $this->assertSame('1 nov', fecha_corta_es('2026-11-01'));
        $this->assertSame('9 mar', fecha_corta_es('2026-03-09'));
        $this->assertSame('31 dic', fecha_corta_es('2026-12-31'));
    }

    // ---- débito disponible ----

    public function test_debito_disponible_sigue_a_mp_configurado(): void
    {
        MercadoPago::$transporte = null;
        env_set('MP_ACCESS_TOKEN', null);
        $this->assertFalse(suscripciones_mensuales([])['debito_disponible']);

        env_set('MP_ACCESS_TOKEN', 'TEST-token');
        $this->assertTrue(suscripciones_mensuales([])['debito_disponible']);
    }
}
