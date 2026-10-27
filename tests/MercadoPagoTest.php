<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MercadoPagoTest extends TestCase
{
    /** @var list<array{0: string, 1: string, 2: ?array}> */
    private array $llamadas = [];

    protected function tearDown(): void
    {
        MercadoPago::$transporte = null;
        env_set('MP_ACCESS_TOKEN', null);
        env_set('PORTAL_URL', null);
        parent::tearDown();
    }

    /** Instala un transporte falso que registra (método, ruta, cuerpo) y devuelve siempre $respuesta. */
    private function transporte(array $respuesta = []): void
    {
        $this->llamadas = [];
        MercadoPago::$transporte = function (string $metodo, string $ruta, ?array $cuerpo) use ($respuesta): array {
            $this->llamadas[] = [$metodo, $ruta, $cuerpo];
            return $respuesta;
        };
    }

    public function test_la_preferencia_lleva_el_id_del_cobro_como_referencia_externa(): void
    {
        env_set('PORTAL_URL', 'https://demo.test');
        $this->transporte(['id' => '123-abc', 'init_point' => 'https://mp.test/pagar/123-abc', 'otro' => 'x']);

        $r = mp_crear_preferencia(['id' => 42, 'concepto' => 'Hosting octubre', 'monto' => '1500.50'], 'ana@sol.com');

        $this->assertSame(['id' => '123-abc', 'init_point' => 'https://mp.test/pagar/123-abc'], $r);
        $this->assertCount(1, $this->llamadas);
        [$metodo, $ruta, $cuerpo] = $this->llamadas[0];
        $this->assertSame('POST', $metodo);
        $this->assertSame('/checkout/preferences', $ruta);
        $this->assertSame('cobro-42', $cuerpo['external_reference']);
        $this->assertSame('https://demo.test/api/mp-webhook', $cuerpo['notification_url']);
        $this->assertSame('ana@sol.com', $cuerpo['payer']['email']);
        $this->assertSame('ARS', $cuerpo['items'][0]['currency_id']);
        $this->assertSame(1500.5, $cuerpo['items'][0]['unit_price']);
        $this->assertSame(1, $cuerpo['items'][0]['quantity']);
        $this->assertSame('Hosting octubre', $cuerpo['items'][0]['title']);
        $pagos = 'https://demo.test/clientes/pagos';
        $this->assertSame(['success' => $pagos, 'pending' => $pagos, 'failure' => $pagos], $cuerpo['back_urls']);
    }

    public function test_una_respuesta_sin_init_point_lanza(): void
    {
        $this->transporte(['id' => '123-abc']);
        $this->expectException(RuntimeException::class);
        mp_crear_preferencia(['id' => 1, 'concepto' => 'x', 'monto' => '10'], 'ana@sol.com');
    }

    public function test_invalidar_una_preferencia_la_vence_ahora(): void
    {
        $this->transporte(['id' => 'pref-9']);
        $antes = time();

        mp_invalidar_preferencia('pref-9');

        [$metodo, $ruta, $cuerpo] = $this->llamadas[0];
        $this->assertSame('PUT', $metodo);
        $this->assertSame('/checkout/preferences/pref-9', $ruta);
        $this->assertTrue($cuerpo['expires']);
        $vence = strtotime($cuerpo['expiration_date_to']);
        $this->assertGreaterThanOrEqual($antes - 1, $vence);
        $this->assertLessThanOrEqual(time() + 1, $vence);
    }

    public function test_obtener_pago_pide_el_pago_por_id(): void
    {
        $this->transporte(['id' => 777, 'status' => 'approved']);
        $this->assertSame(['id' => 777, 'status' => 'approved'], mp_obtener_pago('777'));
        $this->assertSame(['GET', '/v1/payments/777', null], $this->llamadas[0]);
    }

    public function test_buscar_pagos_filtra_por_referencia_externa_y_devuelve_la_lista(): void
    {
        $this->transporte(['paging' => ['total' => 2], 'results' => [['id' => 1], ['id' => 2]]]);

        $this->assertSame([['id' => 1], ['id' => 2]], mp_buscar_pagos('42'));
        [$metodo, $ruta, $cuerpo] = $this->llamadas[0];
        $this->assertSame('GET', $metodo);
        $this->assertStringStartsWith('/v1/payments/search?', $ruta);
        $this->assertStringContainsString('external_reference=42', $ruta);
        $this->assertNull($cuerpo);
    }

    public function test_el_preapproval_arranca_en_la_fecha_pedida_con_monto_y_moneda(): void
    {
        env_set('PORTAL_URL', 'https://demo.test');
        $this->transporte(['id' => 'pre-1', 'init_point' => 'https://mp.test/suscribir/pre-1']);
        $inicio = '2026-11-05T00:00:00.000-03:00';

        $r = mp_crear_preapproval(
            ['id' => 7, 'monto' => '25000.00', 'moneda' => 'ARS', 'plan_nombre' => 'Mantenimiento'],
            'ana@sol.com',
            $inicio
        );

        $this->assertSame(['id' => 'pre-1', 'init_point' => 'https://mp.test/suscribir/pre-1'], $r);
        [$metodo, $ruta, $cuerpo] = $this->llamadas[0];
        $this->assertSame('POST', $metodo);
        $this->assertSame('/preapproval', $ruta);
        $this->assertSame('ana@sol.com', $cuerpo['payer_email']);
        $this->assertSame('susc-7', $cuerpo['external_reference']);
        $this->assertSame('pending', $cuerpo['status']);
        $this->assertSame('https://demo.test/clientes/pagos', $cuerpo['back_url']);
        $this->assertSame('Mantenimiento', $cuerpo['reason']);
        $this->assertSame(
            ['frequency' => 1, 'frequency_type' => 'months', 'start_date' => $inicio, 'transaction_amount' => 25000.0, 'currency_id' => 'ARS'],
            $cuerpo['auto_recurring']
        );
    }

    public function test_obtener_preapproval_lo_pide_por_id(): void
    {
        $this->transporte(['id' => 'pre-1', 'status' => 'authorized']);
        $this->assertSame(['id' => 'pre-1', 'status' => 'authorized'], mp_obtener_preapproval('pre-1'));
        $this->assertSame(['GET', '/preapproval/pre-1', null], $this->llamadas[0]);
    }

    public function test_actualizar_pausar_reanudar_y_cancelar_mandan_el_estado_correcto(): void
    {
        $this->transporte(['id' => 'pre-1']);

        $this->assertSame(['id' => 'pre-1'], mp_actualizar_preapproval('pre-1', ['auto_recurring' => ['transaction_amount' => 30000.0, 'currency_id' => 'ARS']]));
        mp_pausar_preapproval('pre-1');
        mp_reanudar_preapproval('pre-1');
        mp_cancelar_preapproval('pre-1');

        $this->assertSame(
            [
                ['PUT', '/preapproval/pre-1', ['auto_recurring' => ['transaction_amount' => 30000.0, 'currency_id' => 'ARS']]],
                ['PUT', '/preapproval/pre-1', ['status' => 'paused']],
                ['PUT', '/preapproval/pre-1', ['status' => 'authorized']],
                ['PUT', '/preapproval/pre-1', ['status' => 'cancelled']],
            ],
            $this->llamadas
        );
    }

    public function test_sin_token_lanza_runtime_exception(): void
    {
        MercadoPago::$transporte = null;
        env_set('MP_ACCESS_TOKEN', null);
        $this->expectException(RuntimeException::class);
        mp_request('GET', '/v1/payments/1');
    }

    private function firmar(string $secreto, string $dataId, string $requestId, string $ts): string
    {
        return hash_hmac('sha256', "id:$dataId;request-id:$requestId;ts:$ts;", $secreto);
    }

    public function test_firma_valida(): void
    {
        $v1 = $this->firmar('secreto-de-prueba', '123456', 'req-abc', '1704908010');
        $headers = ['x-signature' => "ts=1704908010,v1=$v1", 'x-request-id' => 'req-abc'];

        $this->assertTrue(mp_firma_valida($headers, '123456', 'secreto-de-prueba'));
        // Los nombres de header no distinguen mayúsculas.
        $this->assertTrue(mp_firma_valida(['X-Signature' => "ts=1704908010,v1=$v1", 'X-Request-Id' => 'req-abc'], '123456', 'secreto-de-prueba'));
    }

    public function test_firma_alterada_o_ausente_se_rechaza(): void
    {
        $v1 = $this->firmar('secreto-de-prueba', '123456', 'req-abc', '1704908010');
        $req = ['x-request-id' => 'req-abc'];
        $ok = $req + ['x-signature' => "ts=1704908010,v1=$v1"];
        $this->assertTrue(mp_firma_valida($ok, '123456', 'secreto-de-prueba'));

        // ts cambiado
        $this->assertFalse(mp_firma_valida($req + ['x-signature' => "ts=1704908011,v1=$v1"], '123456', 'secreto-de-prueba'));
        // dataId cambiado
        $this->assertFalse(mp_firma_valida($ok, '123457', 'secreto-de-prueba'));
        // v1 cambiado
        $this->assertFalse(mp_firma_valida($req + ['x-signature' => 'ts=1704908010,v1=' . str_repeat('0', 64)], '123456', 'secreto-de-prueba'));
        // x-request-id cambiado o ausente
        $this->assertFalse(mp_firma_valida(['x-request-id' => 'otro'] + $ok, '123456', 'secreto-de-prueba'));
        $this->assertFalse(mp_firma_valida(['x-signature' => "ts=1704908010,v1=$v1"], '123456', 'secreto-de-prueba'));
        // secreto equivocado o vacío
        $this->assertFalse(mp_firma_valida($ok, '123456', 'otro-secreto'));
        $this->assertFalse(mp_firma_valida($ok, '123456', ''));
        // sin header de firma, o malformado
        $this->assertFalse(mp_firma_valida($req, '123456', 'secreto-de-prueba'));
        $this->assertFalse(mp_firma_valida($req + ['x-signature' => 'basura'], '123456', 'secreto-de-prueba'));
        $this->assertFalse(mp_firma_valida($req + ['x-signature' => "v1=$v1"], '123456', 'secreto-de-prueba'));
    }
}
