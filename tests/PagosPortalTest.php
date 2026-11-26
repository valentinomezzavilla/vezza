<?php
declare(strict_types=1);

final class PagosPortalTest extends DbTestCase
{
    private const SECRETO = 'secreto-webhook';
    private int $cliente;
    private array $plan;
    private array $mp = [];
    private array $llamadas = [];
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();
        clock_set('2026-12-03');
        env_set('MP_ACCESS_TOKEN', 'TEST-123');
        env_set('MP_WEBHOOK_SECRET', self::SECRETO);
        env_set('MAIL_WEBHOOK_URL', 'https://n8n.test/mail');
        env_set('PORTAL_URL', 'https://vezzadev.com');
        $this->cliente = clientes_create(['nombre' => 'Pescadería Valle Viejo', 'email' => 'pesca@sol.com'])['id'];
        $this->plan = planes_create(['nombre' => 'Mantenimiento', 'monto' => '45000', 'moneda' => 'ARS', 'frecuencia' => 'mensual']);
        $this->mp = ['/checkout/preferences' => ['id' => 'pref-1', 'init_point' => 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=pref-1']];
        $this->llamadas = [];
        $this->mails = [];
        MercadoPago::$transporte = function (string $metodo, string $ruta, ?array $cuerpo): array {
            $this->llamadas[] = [$metodo, $ruta, $cuerpo];
            $r = $this->mp[$ruta] ?? new RuntimeException("Mercado Pago respondió 404 a $metodo $ruta");
            if ($r instanceof Throwable) {
                throw $r;
            }
            return $r;
        };
        Mail::$transporte = function (string $url, string $secreto, array $payload): bool {
            $this->mails[] = $payload;
            return true;
        };
    }

    protected function tearDown(): void
    {
        MercadoPago::$transporte = null;
        Mail::$transporte = null;
        foreach (['MP_ACCESS_TOKEN', 'MP_WEBHOOK_SECRET', 'MAIL_WEBHOOK_URL', 'PORTAL_URL'] as $k) {
            env_set($k, null);
        }
        parent::tearDown();
    }

    private function susc(array $extra = []): array
    {
        return suscripciones_cliente_create($extra + ['cliente_id' => $this->cliente, 'plan_id' => $this->plan['id'], 'fecha_proximo_cobro' => '2026-12-05']);
    }

    private function intento(): array
    {
        return q_one('SELECT * FROM pagos_portal ORDER BY id DESC LIMIT 1');
    }

    /** Lo que contestaría GET /v1/payments/{id} y el aviso firmado de ese pago. */
    private function avisarPago(string $id, array $pago): array
    {
        $this->mp["/v1/payments/$id"] = $pago + ['id' => $id];
        $ts = time() * 1000;
        $v1 = hash_hmac('sha256', "id:$id;request-id:req-1;ts:$ts;", self::SECRETO);
        $cuerpo = json_encode(['type' => 'payment', 'data' => ['id' => $id]]);
        return mp_webhook_procesar(['HTTP_X_SIGNATURE' => "ts=$ts,v1=$v1", 'HTTP_X_REQUEST_ID' => 'req-1'], ['data_id' => $id, 'type' => 'payment'], $cuerpo);
    }

    private function pagoAprobado(array $intento, array $extra = []): array
    {
        return $extra + ['status' => 'approved', 'transaction_amount' => (float)$intento['monto'], 'currency_id' => 'ARS',
            'external_reference' => 'portal-' . $intento['id'], 'payment_type_id' => 'credit_card'];
    }

    private function cobros(int $suscId): array
    {
        return q_all('SELECT * FROM cobros WHERE suscripcion_cliente_id = ? ORDER BY id', [$suscId]);
    }

    // ---- iniciar el pago ----

    public function test_crea_la_preferencia_con_el_monto_del_servidor_y_devuelve_el_link(): void
    {
        $s = $this->susc();
        $link = portal_pagar_mp($this->cliente, $s['id']);
        $this->assertSame('https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=pref-1', $link);

        $intento = $this->intento();
        $this->assertSame('45000.00', $intento['monto']);
        $this->assertSame('2026-12-05', $intento['vencimiento']);
        $this->assertSame('pref-1', $intento['mp_preference_id']);
        [$metodo, $ruta, $cuerpo] = $this->llamadas[0];
        $this->assertSame(['POST', '/checkout/preferences'], [$metodo, $ruta]);
        $this->assertSame([['id' => 'susc-' . $s['id'], 'title' => 'Mantenimiento · diciembre 2026', 'quantity' => 1, 'unit_price' => 45000.0, 'currency_id' => 'ARS']], $cuerpo['items']);
        $this->assertSame('portal-' . $intento['id'], $cuerpo['external_reference']);
        $this->assertSame('https://vezzadev.com/api/mp-webhook', $cuerpo['notification_url']);
        $this->assertSame('https://vezzadev.com/clientes?aviso=aprobado', $cuerpo['back_urls']['success']);
        $this->assertSame(['email' => 'pesca@sol.com'], $cuerpo['payer']);
        $this->assertTrue($cuerpo['expires']);
    }

    public function test_una_suscripcion_ajena_da_404_y_no_llama_a_mercado_pago(): void
    {
        $otro = clientes_create(['nombre' => 'Otro'])['id'];
        $ajena = suscripciones_cliente_create(['cliente_id' => $otro, 'plan_id' => $this->plan['id'], 'fecha_proximo_cobro' => '2026-12-05']);
        $this->assertHttp(404, fn() => portal_pagar_mp($this->cliente, $ajena['id']));
        $this->assertSame([], $this->llamadas);
    }

    public function test_no_se_ofrece_si_no_se_puede_pagar_asi(): void
    {
        $pausada = $this->susc();
        crud_update('suscripciones_cliente', $pausada['id'], ['pausada' => 1]);
        $this->assertHttp(409, fn() => portal_pagar_mp($this->cliente, $pausada['id']));

        $conDebito = $this->susc();
        crud_update('suscripciones_cliente', $conDebito['id'], ['modalidad' => 'debito', 'mp_preapproval_id' => 'pre-1', 'mp_preapproval_estado' => 'autorizada']);
        $this->assertHttp(409, fn() => portal_pagar_mp($this->cliente, $conDebito['id']));
        $this->assertStringNotContainsString('/clientes/pagar', portal_acciones_pago(portal_suscripcion($this->cliente, $conDebito['id'])), 'Con débito no hay botón de pago');

        $usd = planes_create(['nombre' => 'Dólares', 'monto' => '100', 'moneda' => 'USD', 'frecuencia' => 'mensual']);
        $enUsd = $this->susc(['plan_id' => $usd['id']]);
        $this->assertHttp(409, fn() => portal_pagar_mp($this->cliente, $enUsd['id']));

        // Sin Mercado Pago configurado (mp_configurado también mira el transporte de prueba).
        env_set('MP_ACCESS_TOKEN', null);
        MercadoPago::$transporte = null;
        $this->assertHttp(409, fn() => portal_pagar_mp($this->cliente, $this->susc()['id']));
    }

    public function test_el_boton_aparece_solo_cuando_se_puede_pagar(): void
    {
        $s = $this->susc();
        $html = portal_acciones_pago(portal_suscripcion($this->cliente, $s['id']));
        $this->assertStringContainsString('action="/clientes/pagar"', $html);
        $this->assertStringContainsString('Pagar $45.000 con Mercado Pago', $html);
        $this->assertStringContainsString('name="csrf"', $html);
        env_set('MP_ACCESS_TOKEN', null);
        MercadoPago::$transporte = null;
        $this->assertStringNotContainsString('/clientes/pagar', portal_acciones_pago(portal_suscripcion($this->cliente, $s['id'])));
    }

    public function test_limita_los_pagos_iniciados_por_hora(): void
    {
        $s = $this->susc();
        for ($i = 0; $i < PORTAL_PAGOS_POR_HORA; $i++) {
            portal_pagar_mp($this->cliente, $s['id']);
        }
        $this->assertHttp(429, fn() => portal_pagar_mp($this->cliente, $s['id']));
    }

    public function test_si_mercado_pago_no_responde_da_502(): void
    {
        $this->mp['/checkout/preferences'] = new RuntimeException('caído');
        $this->assertHttp(502, fn() => portal_pagar_mp($this->cliente, $this->susc()['id']));
    }

    // ---- el aviso del pago ----

    public function test_un_pago_aprobado_se_registra_una_sola_vez_con_su_comprobante(): void
    {
        $s = $this->susc();
        portal_pagar_mp($this->cliente, $s['id']);
        $intento = $this->intento();

        $this->assertSame(['http' => 200, 'resultado' => 'registrado'], $this->avisarPago('7001', $this->pagoAprobado($intento)));
        $this->assertSame(['http' => 200, 'resultado' => 'repetido'], $this->avisarPago('7001', $this->pagoAprobado($intento)));

        $cobros = $this->cobros($s['id']);
        $this->assertCount(1, $cobros);
        $this->assertSame('Mercado Pago · tarjeta de crédito', $cobros[0]['metodo_pago']);
        $this->assertSame('7001', $cobros[0]['mp_payment_id']);
        $this->assertSame('2027-01-05', suscripciones_cliente_get($s['id'])['fecha_proximo_cobro']);
        $this->assertSame('aprobado', $this->intento()['estado']);
        $this->assertSame(['comprobante_pago'], array_column($this->mails, 'plantilla'));
    }

    public function test_un_pago_en_efectivo_pendiente_se_registra_recien_al_aprobarse(): void
    {
        $s = $this->susc();
        portal_pagar_mp($this->cliente, $s['id']);
        $intento = $this->intento();

        $this->assertSame('no_aprobado', $this->avisarPago('7002', $this->pagoAprobado($intento, ['status' => 'pending', 'payment_type_id' => 'ticket']))['resultado']);
        $this->assertSame([], $this->cobros($s['id']));
        $this->assertSame('pendiente', $this->intento()['estado']);

        $this->assertSame('registrado', $this->avisarPago('7002', $this->pagoAprobado($intento, ['payment_type_id' => 'ticket']))['resultado']);
        $this->assertSame('Mercado Pago · efectivo', $this->cobros($s['id'])[0]['metodo_pago']);
    }

    public function test_un_monto_distinto_no_se_registra_y_queda_para_revisar(): void
    {
        $s = $this->susc();
        portal_pagar_mp($this->cliente, $s['id']);
        $intento = $this->intento();
        $this->assertSame('sin_cobro', $this->avisarPago('7003', $this->pagoAprobado($intento, ['transaction_amount' => 1.0]))['resultado']);
        $this->assertSame([], $this->cobros($s['id']));
        $n = q_one("SELECT * FROM mp_notificaciones WHERE recurso_id = '7003'");
        $this->assertSame(1, (int)$n['sin_cobro']);
        $this->assertStringContainsString('no se registró', (string)$n['error']);
    }

    public function test_una_referencia_que_no_existe_queda_para_revisar(): void
    {
        $this->assertSame('sin_cobro', $this->avisarPago('7004', ['status' => 'approved', 'transaction_amount' => 45000.0, 'currency_id' => 'ARS', 'external_reference' => 'portal-999999'])['resultado']);
    }

    public function test_un_rechazo_despues_de_aprobado_no_cambia_el_estado_del_intento(): void
    {
        $s = $this->susc();
        portal_pagar_mp($this->cliente, $s['id']);
        $intento = $this->intento();
        $this->avisarPago('7005', $this->pagoAprobado($intento));
        $this->avisarPago('7006', $this->pagoAprobado($intento, ['status' => 'rejected']));
        $this->assertSame('aprobado', $this->intento()['estado']);
        $this->assertCount(1, $this->cobros($s['id']));
    }

    public function test_medio_de_pago_legible(): void
    {
        $this->assertSame('Mercado Pago · dinero en cuenta', mp_medio_texto('account_money'));
        $this->assertSame('Mercado Pago · tarjeta de débito', mp_medio_texto('debit_card'));
        $this->assertSame('Mercado Pago · pago', mp_medio_texto('raro'));
    }

    // ---- débito automático desde el portal ----

    public function test_activar_el_debito_desde_el_portal_lleva_al_alta_de_mercado_pago(): void
    {
        $this->mp['/preapproval'] = ['id' => 'pre-9', 'init_point' => 'https://www.mercadopago.com.ar/subscriptions/checkout?preapproval_id=pre-9'];
        $s = $this->susc();
        $this->assertStringContainsString('action="/clientes/debito"', portal_acciones_pago(portal_suscripcion($this->cliente, $s['id'])));

        $link = portal_activar_debito($this->cliente, $s['id']);
        $this->assertSame('https://www.mercadopago.com.ar/subscriptions/checkout?preapproval_id=pre-9', $link);
        $cuerpo = end($this->llamadas)[2];
        $this->assertSame(5, $cuerpo['auto_recurring']['billing_day']);
        $this->assertSame('2026-12-05T12:00:00.000-03:00', $cuerpo['auto_recurring']['start_date']);

        $p = portal_suscripcion($this->cliente, $s['id']);
        $this->assertSame($link, $p['debito_pendiente_link']);
        $html = portal_acciones_pago($p);
        $this->assertStringContainsString('Terminar de autorizar el débito', $html);
        $this->assertStringNotContainsString('action="/clientes/debito"', $html);
        $this->assertHttp(409, fn() => portal_activar_debito($this->cliente, $s['id']));
    }

    public function test_el_debito_no_se_ofrece_en_planes_anuales_ni_ajenos(): void
    {
        $anual = planes_create(['nombre' => 'Anual', 'monto' => '400000', 'moneda' => 'ARS', 'frecuencia' => 'anual']);
        $s = $this->susc(['plan_id' => $anual['id']]);
        $this->assertHttp(409, fn() => portal_activar_debito($this->cliente, $s['id']));
        $otro = clientes_create(['nombre' => 'Otro', 'email' => 'otro@sol.com'])['id'];
        $ajena = suscripciones_cliente_create(['cliente_id' => $otro, 'plan_id' => $this->plan['id'], 'fecha_proximo_cobro' => '2026-12-05']);
        $this->assertHttp(404, fn() => portal_activar_debito($this->cliente, $ajena['id']));
        $this->assertSame([], $this->llamadas);
    }
}
