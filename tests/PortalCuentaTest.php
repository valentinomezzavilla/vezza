<?php
declare(strict_types=1);

final class PortalCuentaTest extends DbTestCase
{
    private int $cliente;
    private int $otro;
    private array $plan;

    protected function setUp(): void
    {
        parent::setUp();
        clock_set('2026-12-03');
        $this->cliente = clientes_create(['nombre' => 'Pescadería Valle Viejo', 'email' => 'pesca@sol.com'])['id'];
        $this->otro = clientes_create(['nombre' => 'Otro'])['id'];
        $this->plan = planes_create(['nombre' => 'Mantenimiento', 'descripcion' => 'Hosting y soporte', 'monto' => '45000', 'moneda' => 'ARS', 'frecuencia' => 'mensual']);
    }

    private function susc(int $cliente, string $vence = '2026-12-05'): array
    {
        return suscripciones_cliente_create(['cliente_id' => $cliente, 'plan_id' => $this->plan['id'], 'fecha_proximo_cobro' => $vence]);
    }

    public function test_muestra_solo_lo_del_cliente_de_la_sesion(): void
    {
        $mia = $this->susc($this->cliente);
        $ajena = $this->susc($this->otro);
        suscripcion_pago_manual($mia['id'], []);
        suscripcion_pago_manual($ajena['id'], []);
        cobros_create(['cliente_id' => $this->otro, 'concepto' => 'Ajeno', 'monto' => '1', 'moneda' => 'ARS', 'fecha_vencimiento' => '2026-12-10']);
        cobros_create(['cliente_id' => $this->cliente, 'concepto' => 'Módulo nuevo', 'monto' => '120000', 'moneda' => 'ARS', 'fecha_vencimiento' => '2026-12-10']);

        $c = portal_cuenta($this->cliente);
        $this->assertSame('Pescadería Valle Viejo', $c['nombre']);
        $this->assertSame([$mia['id']], array_column($c['suscripciones'], 'id'));
        $this->assertSame(['Módulo nuevo'], array_column($c['pendientes'], 'concepto'));
        $this->assertCount(1, $c['pagos']);
        $this->assertMatchesRegularExpression('/^0001-\d{8}$/', $c['pagos'][0]['comprobante']);
        $this->assertHttp(404, fn() => portal_suscripcion($this->cliente, $ajena['id']));
        $this->assertSame($mia['id'], portal_suscripcion($this->cliente, $mia['id'])['id']);
    }

    public function test_la_suscripcion_trae_el_vencimiento_fijo_y_sin_datos_internos(): void
    {
        $this->susc($this->cliente);
        $s = portal_cuenta($this->cliente)['suscripciones'][0];
        $this->assertSame(5, $s['dia_vencimiento']);
        $this->assertSame('2026-12-05', $s['proximo_vencimiento']);
        $this->assertSame('por_vencer', $s['cobro_estado']);
        $this->assertSame(2, $s['cobro_dias']);
        foreach (['mp_preapproval_id', 'cliente_id', 'aviso_baja_enviado_en'] as $interno) {
            $this->assertArrayNotHasKey($interno, $s);
        }
        $html = portal_suscripcion_html($s);
        $this->assertStringContainsString('El 5 de cada mes', $html);
        $this->assertStringContainsString('5/12/2026 · faltan 2 días', $html);
        $this->assertStringContainsString('Por vencer', $html);
    }

    public function test_el_link_de_un_debito_pendiente_es_del_cliente(): void
    {
        $s = $this->susc($this->cliente);
        crud_update('suscripciones_cliente', $s['id'], ['mp_preapproval_id' => 'pre-1', 'mp_preapproval_estado' => 'pendiente', 'mp_init_point' => 'https://mp.test/alta']);
        $this->assertSame('https://mp.test/alta', portal_cuenta($this->cliente)['suscripciones'][0]['debito_pendiente_link']);
    }

    public function test_una_suscripcion_pausada_o_dada_de_baja_no_se_puede_pagar(): void
    {
        $s = $this->susc($this->cliente);
        crud_update('suscripciones_cliente', $s['id'], ['pausada' => 1]);
        $p = portal_suscripcion($this->cliente, $s['id']);
        $this->assertSame('pausada', $p['estado']);
        $this->assertNull($p['cobro_estado']);
        $this->assertSame('', portal_acciones_pago($p));
    }

    public function test_el_html_escapa_los_datos(): void
    {
        planes_update($this->plan['id'], ['nombre' => '<b>Plan</b>']);
        crud_update('clientes', $this->cliente, ['nombre' => 'X']);
        $this->susc($this->cliente);
        $html = portal_suscripcion_html(portal_cuenta($this->cliente)['suscripciones'][0]);
        $this->assertStringNotContainsString('<b>Plan</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;Plan&lt;/b&gt;', $html);
    }
}
