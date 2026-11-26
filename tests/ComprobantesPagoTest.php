<?php
declare(strict_types=1);

final class ComprobantesPagoTest extends DbTestCase
{
    private int $cliente;
    private array $plan;
    /** @var list<array> */
    private array $mails = [];
    private bool $mailOk = true;

    protected function setUp(): void
    {
        parent::setUp();
        clock_set('2026-12-03');
        env_set('MAIL_WEBHOOK_URL', 'https://n8n.test/hook');
        env_set('PORTAL_URL', 'https://portal.test');
        env_set('COMPROBANTE_EMISOR_NOMBRE', 'VEZZA');
        $this->mails = [];
        $this->mailOk = true;
        Mail::$transporte = function (string $url, string $secreto, array $payload): bool {
            $this->mails[] = $payload;
            return $this->mailOk;
        };
        $this->cliente = clientes_create(['nombre' => 'Pescadería Valle Viejo', 'email' => 'ficha@pesca.test'])['id'];
        $this->plan = planes_create(['nombre' => 'Mantenimiento', 'monto' => '45000', 'moneda' => 'ARS', 'frecuencia' => 'mensual']);
    }

    protected function tearDown(): void
    {
        Mail::$transporte = null;
        foreach (['MAIL_WEBHOOK_URL', 'PORTAL_URL', 'COMPROBANTE_EMISOR_NOMBRE'] as $k) {
            env_set($k, null);
        }
        parent::tearDown();
    }

    private function susc(string $vence = '2026-12-05'): array
    {
        return suscripciones_cliente_create(['cliente_id' => $this->cliente, 'plan_id' => $this->plan['id'], 'fecha_proximo_cobro' => $vence]);
    }

    private function comprobantes(): array
    {
        return comprobantes_pago_de_cliente($this->cliente);
    }

    // ---- emisión con cada pago ----

    public function test_cada_pago_registrado_emite_un_comprobante_y_lo_manda(): void
    {
        $s = $this->susc();
        $r = suscripcion_pago_manual($s['id'], ['metodo_pago' => 'Transferencia']);

        $c = $this->comprobantes();
        $this->assertCount(1, $c);
        $this->assertSame($r['cobro']['id'], $c[0]['cobro_id']);
        $this->assertSame(comprobante_pago_numero($c[0]['id']), $c[0]['numero']);
        $this->assertMatchesRegularExpression('/^0001-\d{8}$/', $c[0]['numero']);
        $this->assertSame([
            'numero' => $c[0]['numero'], 'fecha' => '2026-12-03',
            'emisor' => ['nombre' => 'VEZZA', 'cuit' => null, 'contacto' => null],
            'cliente' => ['nombre' => 'Pescadería Valle Viejo'],
            'concepto' => 'Mantenimiento · diciembre 2026',
            'periodo' => ['desde' => '2026-12-05', 'hasta' => '2027-01-04'],
            'medio' => 'Transferencia', 'monto' => '45000.00', 'moneda' => 'ARS',
            'cobro_id' => $r['cobro']['id'], 'leyenda' => 'Comprobante no válido como factura',
        ], $c[0]['datos']);

        $this->assertCount(1, $this->mails);
        $this->assertSame('comprobante_pago', $this->mails[0]['plantilla']);
        $this->assertSame('ficha@pesca.test', $this->mails[0]['destino']);
        $this->assertSame([
            'nombre' => 'Pescadería Valle Viejo', 'numero' => $c[0]['numero'], 'fecha' => '3/12/2026',
            'concepto' => 'Mantenimiento · diciembre 2026', 'periodo' => '5/12/2026 al 4/1/2027', 'medio' => 'Transferencia',
            'monto' => '$45.000', 'emisor' => 'VEZZA', 'link' => 'https://portal.test/clientes/comprobante?n=' . $c[0]['numero'],
            'leyenda' => 'Comprobante no válido como factura',
        ], $this->mails[0]['datos']);
        $this->assertNotNull($c[0]['enviado_en']);
        $this->assertSame(['numero' => $c[0]['numero'], 'destino' => 'ficha@pesca.test', 'enviado_en' => $c[0]['enviado_en'], 'error' => null],
            cobros_get($r['cobro']['id'])['comprobante_pago']);
    }

    public function test_los_numeros_son_correlativos(): void
    {
        $s = $this->susc();
        suscripcion_pago_manual($s['id'], []);
        suscripcion_pago_manual($s['id'], []);
        [$segundo, $primero] = $this->comprobantes();
        $this->assertSame($primero['id'] + 1, $segundo['id']);
        $this->assertSame('Mantenimiento · enero 2027', $segundo['datos']['concepto']);
    }

    public function test_un_pago_de_mercado_pago_repetido_no_emite_otro_ni_manda_otro_mail(): void
    {
        $s = $this->susc();
        $pago = ['meses' => 1, 'monto' => '45000.00', 'moneda' => 'ARS', 'metodo_pago' => 'Mercado Pago · débito', 'mp_payment_id' => 'pay-1'];
        $this->assertNotNull(suscripcion_registrar_pago($s['id'], $pago)['comprobante_id']);
        $r = suscripcion_registrar_pago($s['id'], $pago);
        $this->assertSame('repetido', $r['resultado']);
        $this->assertNull($r['comprobante_id']);
        $this->assertCount(1, $this->comprobantes());
        $this->assertCount(1, $this->mails);
    }

    public function test_va_a_la_casilla_del_portal_si_tiene_acceso(): void
    {
        $this->crearUsuarioCliente($this->cliente, 'portal@pesca.test');
        suscripcion_pago_manual($this->susc()['id'], []);
        $this->assertSame('portal@pesca.test', $this->mails[0]['destino']);
    }

    public function test_si_el_mail_falla_el_pago_y_el_comprobante_quedan_y_se_puede_reenviar(): void
    {
        $this->mailOk = false;
        $r = suscripcion_pago_manual($this->susc()['id'], []);
        $this->assertSame('pagado', $r['cobro']['estado']);
        $c = $this->comprobantes()[0];
        $this->assertNull($c['enviado_en']);
        $this->assertNotNull($c['error']);

        $this->mailOk = true;
        $this->assertSame('enviado', comprobante_pago_enviar($c['id']));
        $this->assertNull(comprobante_pago_get($c['id'])['error']);
        $this->assertNotNull(comprobante_pago_get($c['id'])['enviado_en']);
    }

    public function test_sin_mail_queda_emitido_y_avisa(): void
    {
        crud_update('clientes', $this->cliente, ['email' => null]);
        $r = suscripcion_registrar_pago($this->susc()['id'], ['meses' => 1, 'monto' => '45000.00', 'moneda' => 'ARS', 'metodo_pago' => 'Efectivo']);
        $this->assertSame('sin_mail', $r['comprobante_aviso']);
        $this->assertSame([], $this->mails);
        $this->assertSame('El cliente no tiene mail cargado', $this->comprobantes()[0]['error']);
    }

    // ---- la foto no cambia ----

    public function test_lo_que_dice_no_cambia_aunque_cambien_el_cliente_o_el_plan(): void
    {
        suscripcion_pago_manual($this->susc()['id'], []);
        crud_update('clientes', $this->cliente, ['nombre' => 'Otro nombre']);
        crud_update('planes', $this->plan['id'], ['nombre' => 'Otro plan']);
        $c = $this->comprobantes()[0];
        $this->assertSame('Pescadería Valle Viejo', $c['datos']['cliente']['nombre']);
        $this->assertSame('Mantenimiento · diciembre 2026', $c['datos']['concepto']);
        $this->assertStringContainsString('Pescadería Valle Viejo', comprobante_pago_html($c));
    }

    // ---- anulación ----

    public function test_borrar_el_cobro_anula_el_comprobante_sin_borrarlo(): void
    {
        $r = suscripcion_pago_manual($this->susc()['id'], []);
        cobros_delete($r['cobro']['id']);
        $c = $this->comprobantes()[0];
        $this->assertTrue($c['anulado']);
        $this->assertSame('Se borró el pago', $c['motivo_anulacion']);
        $this->assertNull($c['cobro_id']);
        $this->assertSame($r['cobro']['id'], $c['datos']['cobro_id'], 'La foto recuerda de qué cobro era');
        $this->assertStringContainsString('ANULADO', comprobante_pago_html($c));
        $this->assertHttp(409, fn() => comprobante_pago_enviar($c['id']));
    }

    public function test_volver_a_pendiente_anula(): void
    {
        $r = suscripcion_pago_manual($this->susc()['id'], []);
        cobros_update($r['cobro']['id'], ['estado' => 'pendiente']);
        $this->assertSame('El pago volvió a pendiente', $this->comprobantes()[0]['motivo_anulacion']);
        $this->assertNull(cobros_get($r['cobro']['id'])['comprobante_pago']);
    }

    public function test_corregir_el_monto_anula_y_emite_uno_nuevo_que_se_manda(): void
    {
        $r = suscripcion_pago_manual($this->susc()['id'], []);
        cobros_update($r['cobro']['id'], ['monto' => '40000']);
        [$nuevo, $viejo] = $this->comprobantes();
        $this->assertTrue($viejo['anulado']);
        $this->assertSame('45000.00', $viejo['datos']['monto']);
        $this->assertFalse($nuevo['anulado']);
        $this->assertSame('40000.00', $nuevo['datos']['monto']);
        $this->assertSame($viejo['datos']['periodo'], $nuevo['datos']['periodo'], 'El período del pago se conserva');
        $this->assertCount(2, $this->mails);
        $this->assertSame($nuevo['numero'], cobros_get($r['cobro']['id'])['comprobante_pago']['numero']);
    }

    public function test_editar_algo_que_no_figura_no_toca_el_comprobante(): void
    {
        $r = suscripcion_pago_manual($this->susc()['id'], []);
        cobros_update($r['cobro']['id'], ['comprobante_url' => 'https://drive.test/x']);
        $this->assertCount(1, $this->comprobantes());
        $this->assertFalse($this->comprobantes()[0]['anulado']);
    }

    // ---- cobros sueltos ----

    public function test_un_cobro_suelto_pagado_se_emite_a_pedido(): void
    {
        $cobro = cobros_create(['cliente_id' => $this->cliente, 'concepto' => 'Módulo de reportes', 'monto' => '120000', 'moneda' => 'ARS',
            'fecha_vencimiento' => '2026-12-10', 'estado' => 'pagado', 'metodo_pago' => 'Transferencia']);
        $this->assertNull($cobro['comprobante_pago'], 'Marcar pagado un cobro suelto no emite solo');

        $r = comprobante_pago_emitir_y_enviar($cobro['id']);
        $this->assertSame('enviado', $r['aviso']);
        $this->assertNull($r['comprobante']['datos']['periodo']);
        $this->assertSame('Módulo de reportes', $r['comprobante']['datos']['concepto']);
        $this->assertHttp(409, fn() => comprobante_pago_emitir_y_enviar($cobro['id']));
    }

    public function test_no_se_emite_de_un_cobro_pendiente(): void
    {
        $cobro = cobros_create(['cliente_id' => $this->cliente, 'monto' => '1', 'moneda' => 'ARS', 'fecha_vencimiento' => '2026-12-10']);
        $this->assertHttp(409, fn() => comprobante_pago_emitir_y_enviar($cobro['id']));
    }

    // ---- búsqueda por número ----

    public function test_por_numero_y_aislado_por_cliente(): void
    {
        suscripcion_pago_manual($this->susc()['id'], []);
        $c = $this->comprobantes()[0];
        $this->assertSame($c['id'], comprobante_pago_por_numero($c['numero'], $this->cliente)['id']);
        $otro = clientes_create(['nombre' => 'Otro'])['id'];
        $this->assertHttp(404, fn() => comprobante_pago_por_numero($c['numero'], $otro));
        foreach (['', '42', '0002-00000001', '0001-0000001', "0001-00000001' OR 1=1"] as $malo) {
            $this->assertHttp(404, fn() => comprobante_pago_por_numero($malo));
        }
    }

    public function test_el_html_escapa_lo_que_viene_de_los_datos(): void
    {
        crud_update('clientes', $this->cliente, ['nombre' => '<script>alert(1)</script>']);
        suscripcion_pago_manual($this->susc()['id'], []);
        $html = comprobante_pago_html($this->comprobantes()[0]);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('Comprobante no válido como factura', $html);
    }
}
