<?php
declare(strict_types=1);

final class PagosMpTest extends DbTestCase
{
    private const SECRETO = 'secreto-de-prueba';

    private int $cliente;
    /** @var array<string, array|Throwable> Lo que contesta la pasarela falsa, por ruta. */
    private array $mp = [];
    /** @var list<string> */
    private array $consultas = [];
    /** @var list<array{0: string, 1: string, 2: array}> */
    private array $mails = [];
    private bool $mailOk = true;

    protected function setUp(): void
    {
        parent::setUp();
        clock_set('2026-10-27');
        env_set('MP_WEBHOOK_SECRET', self::SECRETO);
        env_set('MAIL_WEBHOOK_URL', 'https://n8n.test/mail');
        env_set('PORTAL_URL', 'https://demo.test');
        $this->cliente = crud_insert('clientes', ['nombre' => 'Acme', 'email' => 'acme@sol.com']);
        $this->mp = [];
        $this->consultas = [];
        $this->mails = [];
        $this->mailOk = true;
        MercadoPago::$transporte = function (string $metodo, string $ruta, ?array $cuerpo): array {
            $this->consultas[] = "$metodo $ruta";
            $r = $this->mp[$ruta] ?? new RuntimeException("Mercado Pago respondió 404 a $metodo $ruta");
            if ($r instanceof Throwable) {
                throw $r;
            }
            return $r;
        };
        Mail::$transporte = function (string $url, string $secreto, array $payload): bool {
            $this->mails[] = [$payload['plantilla'], $payload['destino'], $payload['datos']];
            return $this->mailOk;
        };
    }

    protected function tearDown(): void
    {
        MercadoPago::$transporte = null;
        Mail::$transporte = null;
        env_set('MP_WEBHOOK_SECRET', null);
        env_set('MAIL_WEBHOOK_URL', null);
        env_set('PORTAL_URL', null);
        parent::tearDown();
    }

    // ---- ayudas -----------------------------------------------------------------------------------------------

    private function cobro(array $extra = []): int
    {
        return crud_insert('cobros', $extra + [
            'cliente_id' => $this->cliente, 'concepto' => 'Mantenimiento · octubre 2026', 'monto' => '85000.00',
            'moneda' => 'ARS', 'fecha_vencimiento' => '2026-11-01', 'estado' => 'pendiente',
        ]);
    }

    private function cobroDe(int $id): array
    {
        return crud_find('cobros', $id);
    }

    /** Una suscripción con débito automático ya autorizada en Mercado Pago. */
    private function debito(string $preapproval = 'pre-1', string $proximo = '2026-12-01'): int
    {
        $plan = planes_create(['nombre' => 'Mantenimiento', 'monto' => '85000', 'moneda' => 'ARS', 'frecuencia' => 'mensual']);
        $id = suscripciones_cliente_create([
            'cliente_id' => $this->cliente, 'plan_id' => $plan['id'], 'fecha_proximo_cobro' => $proximo,
        ])['id'];
        crud_update('suscripciones_cliente', $id, [
            'modalidad' => 'debito', 'mp_preapproval_id' => $preapproval, 'mp_preapproval_estado' => 'autorizada',
        ]);
        return $id;
    }

    private function cobroDeSuscripcion(int $suscId, string $periodo, string $monto = '85000.00', array $extra = []): int
    {
        return $this->cobro($extra + [
            'suscripcion_cliente_id' => $suscId, 'periodo' => $periodo, 'monto' => $monto,
            'fecha_vencimiento' => $periodo . '-05',
        ]);
    }

    /** Un pago como lo devuelve GET /v1/payments/{id}. */
    private function pagoMp(string $id, string $referencia, array $extra = []): array
    {
        return $extra + [
            'id' => (int)$id, 'status' => 'approved', 'transaction_amount' => 85000, 'currency_id' => 'ARS',
            'external_reference' => $referencia,
        ];
    }

    private function ofrecerPago(array $pago): void
    {
        $this->mp['/v1/payments/' . $pago['id']] = $pago;
    }

    private function firmar(string $dataId, ?int $ts = null, string $requestId = 'req-1', string $secreto = self::SECRETO): array
    {
        $ts ??= time() * 1000;
        $dataId = preg_match('/^[A-Za-z0-9]+$/', $dataId) ? strtolower($dataId) : $dataId;
        $manifiesto = ($dataId !== '' ? "id:$dataId;" : '') . "request-id:$requestId;ts:$ts;";
        $v1 = hash_hmac('sha256', $manifiesto, $secreto);
        return ['HTTP_X_SIGNATURE' => "ts=$ts,v1=$v1", 'HTTP_X_REQUEST_ID' => $requestId];
    }

    /** Llega una notificación bien firmada (PHP convierte el punto de data.id en guion bajo en $_GET). */
    private function notificar(string $dataId, string $tipo = 'payment', ?array $headers = null): array
    {
        $cuerpo = json_encode(['action' => $tipo . '.updated', 'type' => $tipo, 'data' => ['id' => $dataId]]);
        return mp_webhook_procesar($headers ?? $this->firmar($dataId), ['data_id' => $dataId, 'type' => $tipo], $cuerpo);
    }

    private function notificaciones(): array
    {
        return q_all('SELECT * FROM mp_notificaciones ORDER BY id');
    }

    private function avisos(int $cobroId, string $tipo): array
    {
        return q_all('SELECT * FROM avisos_pago WHERE cobro_id = ? AND tipo = ?', [$cobroId, $tipo]);
    }

    private function mailsDe(string $plantilla): array
    {
        return array_values(array_filter($this->mails, fn(array $m) => $m[0] === $plantilla));
    }

    // ---- firma ------------------------------------------------------------------------------------------------

    public function test_firma_invalida_responde_401_y_no_cambia_nada(): void
    {
        $cobro = $this->cobro();
        $this->ofrecerPago($this->pagoMp('555', (string)$cobro));

        $r = $this->notificar('555', 'payment', $this->firmar('555', null, 'req-1', 'otro-secreto'));

        $this->assertSame(401, $r['http']);
        $this->assertSame('pendiente', $this->cobroDe($cobro)['estado']);
        $this->assertSame([], $this->notificaciones());
        $this->assertSame([], $this->consultas, 'ni siquiera consulta a Mercado Pago');
        $this->assertSame([], $this->mails);
    }

    public function test_sin_firma_o_sin_secreto_configurado_responde_401(): void
    {
        $cobro = $this->cobro();
        $this->ofrecerPago($this->pagoMp('555', (string)$cobro));

        $sinFirma = mp_webhook_procesar([], ['data_id' => '555', 'type' => 'payment'], '{}');
        env_set('MP_WEBHOOK_SECRET', null);
        $sinSecreto = $this->notificar('555');

        $this->assertSame(401, $sinFirma['http']);
        $this->assertSame(401, $sinSecreto['http']);
        $this->assertSame('pendiente', $this->cobroDe($cobro)['estado']);
        $this->assertSame([], $this->notificaciones());
    }

    public function test_una_firma_de_hace_mas_de_cinco_minutos_responde_401(): void
    {
        $cobro = $this->cobro();
        $this->ofrecerPago($this->pagoMp('555', (string)$cobro));

        $vieja = $this->notificar('555', 'payment', $this->firmar('555', (time() - 301) * 1000));
        $this->assertSame(401, $vieja['http']);
        $this->assertSame([], $this->notificaciones());

        $reciente = $this->notificar('555', 'payment', $this->firmar('555', (time() - 240) * 1000));
        $this->assertSame(200, $reciente['http']);
        $this->assertSame('pagado', $this->cobroDe($cobro)['estado']);
    }

    public function test_la_firma_acepta_el_ts_en_segundos_y_en_milisegundos(): void
    {
        $manifiesto = fn(int $ts) => hash_hmac('sha256', "id:555;request-id:r;ts:$ts;", self::SECRETO);
        $seg = time();
        $ms = time() * 1000;

        $this->assertTrue(mp_firma_valida(['x-signature' => "ts=$seg,v1=" . $manifiesto($seg), 'x-request-id' => 'r'], '555', self::SECRETO, 300));
        $this->assertTrue(mp_firma_valida(['x-signature' => "ts=$ms,v1=" . $manifiesto($ms), 'x-request-id' => 'r'], '555', self::SECRETO, 300));
        $viejo = time() - 3600;
        $this->assertFalse(mp_firma_valida(['x-signature' => "ts=$viejo,v1=" . $manifiesto($viejo), 'x-request-id' => 'r'], '555', self::SECRETO, 300));
        // sin tolerancia pedida no mira la hora (es lo que usan los tests de la firma sola)
        $this->assertTrue(mp_firma_valida(['x-signature' => "ts=$viejo,v1=" . $manifiesto($viejo), 'x-request-id' => 'r'], '555', self::SECRETO));
    }

    public function test_un_data_id_alfanumerico_se_pasa_a_minusculas_para_la_firma(): void
    {
        $ts = time() * 1000;
        $v1 = hash_hmac('sha256', "id:abc123;request-id:r;ts:$ts;", self::SECRETO);
        $headers = ['x-signature' => "ts=$ts,v1=$v1", 'x-request-id' => 'r'];

        $this->assertTrue(mp_firma_valida($headers, 'ABC123', self::SECRETO));
        $this->assertTrue(mp_firma_valida($headers, 'abc123', self::SECRETO));
        $this->assertFalse(mp_firma_valida($headers, 'abc124', self::SECRETO));
    }

    public function test_el_data_id_puede_llegar_como_data_punto_id(): void
    {
        $cobro = $this->cobro();
        $this->ofrecerPago($this->pagoMp('555', (string)$cobro));

        $r = mp_webhook_procesar($this->firmar('555'), ['data.id' => '555', 'type' => 'payment'], '{}');

        $this->assertSame(200, $r['http']);
        $this->assertSame('pagado', $this->cobroDe($cobro)['estado']);
    }

    // ---- pago aprobado ----------------------------------------------------------------------------------------

    public function test_pago_aprobado_marca_pagado_con_fecha_metodo_e_id(): void
    {
        $cobro = $this->cobro();
        $this->ofrecerPago($this->pagoMp('555', (string)$cobro));

        $r = $this->notificar('555');

        $this->assertSame(['http' => 200, 'resultado' => 'pagado'], $r);
        $c = $this->cobroDe($cobro);
        $this->assertSame('pagado', $c['estado']);
        $this->assertSame('2026-10-27', $c['fecha_pago']);
        $this->assertSame('Mercado Pago · link', $c['metodo_pago']);
        $this->assertSame('555', $c['mp_payment_id']);
        $this->assertContains('GET /v1/payments/555', $this->consultas, 'el pago se verifica contra la API, no contra el cuerpo');
    }

    public function test_la_notificacion_queda_registrada_con_el_cobro_y_el_estado_del_pago(): void
    {
        $cobro = $this->cobro();
        $this->ofrecerPago($this->pagoMp('555', (string)$cobro));

        $this->notificar('555');

        $n = $this->notificaciones();
        $this->assertCount(1, $n);
        $this->assertSame('payment', $n[0]['tipo']);
        $this->assertSame('555', $n[0]['recurso_id']);
        $this->assertSame($cobro, (int)$n[0]['cobro_id']);
        $this->assertSame('approved', $n[0]['estado_pago']);
        $this->assertSame(0, (int)$n[0]['sin_cobro']);
        $this->assertNull($n[0]['error']);
        $this->assertNotNull($n[0]['procesada_en']);
        $this->assertStringContainsString('"id":"555"', (string)$n[0]['payload']);
    }

    public function test_el_pago_aprobado_manda_la_confirmacion_una_vez(): void
    {
        $cobro = $this->cobro();
        $this->ofrecerPago($this->pagoMp('555', (string)$cobro));

        $this->notificar('555');

        $mails = $this->mailsDe('pago_confirmado');
        $this->assertCount(1, $mails);
        $this->assertSame('acme@sol.com', $mails[0][1]);
        $this->assertSame('$85.000', $mails[0][2]['monto']);
        $avisos = $this->avisos($cobro, 'confirmacion');
        $this->assertCount(1, $avisos);
        $this->assertSame(1, (int)$avisos[0]['ok']);
    }

    public function test_el_mismo_aviso_cinco_veces_paga_una_sola_vez_y_confirma_una_sola_vez(): void
    {
        $cobro = $this->cobro();
        $this->ofrecerPago($this->pagoMp('555', (string)$cobro));

        $resultados = [];
        for ($i = 0; $i < 5; $i++) {
            $resultados[] = $this->notificar('555')['resultado'];
        }

        $this->assertSame(['pagado', 'ya_pagado', 'ya_pagado', 'ya_pagado', 'ya_pagado'], $resultados);
        $this->assertCount(1, $this->mailsDe('pago_confirmado'));
        $this->assertCount(1, $this->avisos($cobro, 'confirmacion'));
        $this->assertSame('555', $this->cobroDe($cobro)['mp_payment_id']);
        $this->assertSame(1, (int)q_val("SELECT COUNT(*) FROM cobros WHERE estado = 'pagado'"));
        $this->assertCount(5, $this->notificaciones());
    }

    public function test_si_la_confirmacion_no_salio_se_reintenta_cuando_llega_el_aviso_de_nuevo(): void
    {
        $cobro = $this->cobro();
        $this->ofrecerPago($this->pagoMp('555', (string)$cobro));
        $this->mailOk = false;

        $this->assertSame(200, $this->notificar('555')['http']);
        $this->assertSame('pagado', $this->cobroDe($cobro)['estado'], 'que falle el mail no deshace el pago');
        $this->assertSame(0, (int)$this->avisos($cobro, 'confirmacion')[0]['ok']);

        $this->mailOk = true;
        $this->assertSame('ya_pagado', $this->notificar('555')['resultado']);
        $this->assertSame(1, (int)$this->avisos($cobro, 'confirmacion')[0]['ok']);

        $this->notificar('555');
        $this->assertCount(2, $this->mailsDe('pago_confirmado'), 'dos intentos: el que falló y el que salió');
    }

    public function test_pagar_no_mueve_fecha_proximo_cobro(): void
    {
        $susc = $this->debito('pre-1', '2026-11-05');
        $cobro = $this->cobroDeSuscripcion($susc, '2026-10', '85000.00', ['link_pago' => 'https://mp.test/pagar/1']);
        $this->ofrecerPago($this->pagoMp('555', (string)$cobro));

        $this->notificar('555');

        $this->assertSame('pagado', $this->cobroDe($cobro)['estado']);
        $this->assertSame('2026-11-05', crud_find('suscripciones_cliente', $susc)['fecha_proximo_cobro']);
    }

    public function test_un_cobro_ya_pagado_a_mano_no_se_toca_ni_se_confirma(): void
    {
        $cobro = $this->cobro(['estado' => 'pagado', 'fecha_pago' => '2026-10-20', 'metodo_pago' => 'Transferencia']);
        $this->ofrecerPago($this->pagoMp('555', (string)$cobro));

        $r = $this->notificar('555');

        $this->assertSame('ya_pagado', $r['resultado']);
        $c = $this->cobroDe($cobro);
        $this->assertSame('Transferencia', $c['metodo_pago']);
        $this->assertSame('2026-10-20', $c['fecha_pago']);
        $this->assertNull($c['mp_payment_id']);
        $this->assertSame([], $this->mails);
        $n = $this->notificaciones()[0];
        $this->assertSame($cobro, (int)$n['cobro_id']);
        $this->assertStringContainsString('ya estaba pagado', (string)$n['error'], 'queda a la vista para revisar un posible cobro doble');
    }

    // ---- monto, moneda y cobros que no existen -----------------------------------------------------------------

    public function test_un_pago_con_monto_distinto_no_marca_pagado(): void
    {
        $cobro = $this->cobro();
        $this->ofrecerPago($this->pagoMp('555', (string)$cobro, ['transaction_amount' => 1000]));

        $r = $this->notificar('555');

        $this->assertSame(['http' => 200, 'resultado' => 'monto_distinto'], $r);
        $c = $this->cobroDe($cobro);
        $this->assertSame('pendiente', $c['estado']);
        $this->assertNull($c['mp_payment_id']);
        $this->assertSame([], $this->mails);
        $n = $this->notificaciones()[0];
        $this->assertSame(1, (int)$n['sin_cobro']);
        $this->assertStringContainsString('monto', mb_strtolower((string)$n['error']));
        $this->assertSame($cobro, (int)$n['cobro_id']);
        $this->assertSame('approved', $n['estado_pago']);
    }

    public function test_un_pago_con_otra_moneda_no_marca_pagado(): void
    {
        $cobro = $this->cobro();
        $this->ofrecerPago($this->pagoMp('555', (string)$cobro, ['currency_id' => 'USD']));

        $r = $this->notificar('555');

        $this->assertSame('monto_distinto', $r['resultado']);
        $this->assertSame('pendiente', $this->cobroDe($cobro)['estado']);
        $n = $this->notificaciones()[0];
        $this->assertSame(1, (int)$n['sin_cobro']);
        $this->assertStringContainsString('USD', (string)$n['error']);
    }

    public function test_el_monto_se_compara_al_centavo(): void
    {
        $cobro = $this->cobro(['monto' => '85000.50']);
        $this->ofrecerPago($this->pagoMp('555', (string)$cobro, ['transaction_amount' => 85000.5]));
        $otro = $this->cobro(['monto' => '85000.50']);
        $this->ofrecerPago($this->pagoMp('556', (string)$otro, ['transaction_amount' => 85000.49]));

        $this->assertSame('pagado', $this->notificar('555')['resultado']);
        $this->assertSame('monto_distinto', $this->notificar('556')['resultado']);
    }

    public function test_un_pago_de_un_cobro_inexistente_queda_sin_cobro_asignado(): void
    {
        $this->ofrecerPago($this->pagoMp('555', '987654'));

        $r = $this->notificar('555');

        $this->assertSame(['http' => 200, 'resultado' => 'sin_cobro'], $r);
        $n = $this->notificaciones();
        $this->assertCount(1, $n);
        $this->assertSame(1, (int)$n[0]['sin_cobro']);
        $this->assertNull($n[0]['cobro_id']);
        $this->assertNotSame('', (string)$n[0]['error']);
        $this->assertSame([], $this->mails);
    }

    public function test_un_pago_sin_referencia_externa_queda_sin_cobro(): void
    {
        $this->cobro();
        $this->ofrecerPago($this->pagoMp('555', ''));

        $this->assertSame('sin_cobro', $this->notificar('555')['resultado']);
        $this->assertSame(1, (int)$this->notificaciones()[0]['sin_cobro']);
    }

    // ---- pendientes y rechazados ------------------------------------------------------------------------------

    public function test_pagos_pendientes_o_rechazados_no_cambian_el_cobro(): void
    {
        $cobro = $this->cobro();
        foreach (['pending', 'in_process', 'rejected', 'cancelled'] as $i => $estado) {
            $id = (string)(600 + $i);
            $this->ofrecerPago($this->pagoMp($id, (string)$cobro, ['status' => $estado]));

            $r = $this->notificar($id);

            $this->assertSame(['http' => 200, 'resultado' => 'no_aprobado'], $r, $estado);
        }

        $c = $this->cobroDe($cobro);
        $this->assertSame('pendiente', $c['estado']);
        $this->assertNull($c['mp_payment_id']);
        $this->assertNull($c['fecha_pago']);
        $this->assertSame([], $this->mails, 'un pago por link rechazado no dispara el aviso de débito');
        $n = $this->notificaciones();
        $this->assertSame(['pending', 'in_process', 'rejected', 'cancelled'], array_column($n, 'estado_pago'));
        $this->assertSame([$cobro, $cobro, $cobro, $cobro], array_map('intval', array_column($n, 'cobro_id')));
        $this->assertSame([0, 0, 0, 0], array_map('intval', array_column($n, 'sin_cobro')));
    }

    public function test_el_rechazo_de_un_debito_avisa_una_sola_vez_por_cobro(): void
    {
        $susc = $this->debito();
        $cobro = $this->cobroDeSuscripcion($susc, '2026-10');
        foreach (['700', '701', '702'] as $id) {
            $this->ofrecerPago($this->pagoMp($id, (string)$susc, ['status' => 'rejected', 'preapproval_id' => 'pre-1']));
            $this->assertSame('no_aprobado', $this->notificar($id)['resultado']);
        }

        $this->assertSame('pendiente', $this->cobroDe($cobro)['estado']);
        $mails = $this->mailsDe('debito_rechazado');
        $this->assertCount(1, $mails);
        $this->assertSame('acme@sol.com', $mails[0][1]);
        $avisos = $this->avisos($cobro, 'rechazo');
        $this->assertCount(1, $avisos);
        $this->assertSame(1, (int)$avisos[0]['ok']);
        $this->assertSame($cobro, (int)$this->notificaciones()[0]['cobro_id']);
    }

    // ---- débito ------------------------------------------------------------------------------------------------

    public function test_el_pago_de_un_debito_se_asigna_al_cobro_pendiente_mas_antiguo_con_ese_monto(): void
    {
        $susc = $this->debito();
        $otraMonto = $this->cobroDeSuscripcion($susc, '2026-08', '60000.00');
        $pagado = $this->cobroDeSuscripcion($susc, '2026-09', '85000.00', ['estado' => 'pagado', 'fecha_pago' => '2026-09-05']);
        $viejo = $this->cobroDeSuscripcion($susc, '2026-10');
        $nuevo = $this->cobroDeSuscripcion($susc, '2026-11');
        // Con preapproval_id manda la suscripción, aunque la referencia externa coincida con el id de otro cobro.
        $this->ofrecerPago($this->pagoMp('800', (string)$nuevo, ['preapproval_id' => 'pre-1']));

        $r = $this->notificar('800');

        $this->assertSame('pagado', $r['resultado']);
        $c = $this->cobroDe($viejo);
        $this->assertSame('pagado', $c['estado']);
        $this->assertSame('Mercado Pago · débito', $c['metodo_pago']);
        $this->assertSame('800', $c['mp_payment_id']);
        $this->assertSame('pendiente', $this->cobroDe($nuevo)['estado']);
        $this->assertSame('pendiente', $this->cobroDe($otraMonto)['estado']);
        $this->assertSame($viejo, (int)$this->notificaciones()[0]['cobro_id']);
        $this->assertCount(1, $this->avisos($viejo, 'confirmacion'));
        $this->assertSame([], $this->avisos($pagado, 'confirmacion'));
    }

    public function test_repetir_el_aviso_de_un_debito_no_paga_el_cobro_del_mes_siguiente(): void
    {
        $susc = $this->debito();
        $octubre = $this->cobroDeSuscripcion($susc, '2026-10');
        $noviembre = $this->cobroDeSuscripcion($susc, '2026-11');
        $this->ofrecerPago($this->pagoMp('800', (string)$susc, ['preapproval_id' => 'pre-1']));

        $this->assertSame('pagado', $this->notificar('800')['resultado']);
        $this->assertSame('ya_pagado', $this->notificar('800')['resultado']);
        $this->assertSame('ya_pagado', $this->notificar('800')['resultado']);

        $this->assertSame('pagado', $this->cobroDe($octubre)['estado']);
        $this->assertSame('pendiente', $this->cobroDe($noviembre)['estado']);
        $this->assertCount(1, $this->mailsDe('pago_confirmado'));
        $this->assertSame($octubre, (int)$this->notificaciones()[2]['cobro_id']);
    }

    public function test_dos_pagos_de_debito_pagan_dos_cobros_distintos(): void
    {
        $susc = $this->debito();
        $octubre = $this->cobroDeSuscripcion($susc, '2026-10');
        $noviembre = $this->cobroDeSuscripcion($susc, '2026-11');
        $this->ofrecerPago($this->pagoMp('800', (string)$susc, ['preapproval_id' => 'pre-1']));
        $this->ofrecerPago($this->pagoMp('801', (string)$susc, ['preapproval_id' => 'pre-1']));

        $this->notificar('800');
        $this->notificar('801');

        $this->assertSame('800', $this->cobroDe($octubre)['mp_payment_id']);
        $this->assertSame('801', $this->cobroDe($noviembre)['mp_payment_id']);
    }

    public function test_un_pago_de_debito_sin_cobro_pendiente_con_ese_monto_queda_sin_cobro(): void
    {
        $susc = $this->debito();
        $cobro = $this->cobroDeSuscripcion($susc, '2026-10', '60000.00');
        $this->ofrecerPago($this->pagoMp('800', (string)$susc, ['preapproval_id' => 'pre-1']));
        $this->ofrecerPago($this->pagoMp('801', (string)$susc, ['preapproval_id' => 'pre-desconocida']));

        $this->assertSame('sin_cobro', $this->notificar('800')['resultado']);
        $this->assertSame('sin_cobro', $this->notificar('801')['resultado']);

        $this->assertSame('pendiente', $this->cobroDe($cobro)['estado']);
        $this->assertSame([1, 1], array_map('intval', array_column($this->notificaciones(), 'sin_cobro')));
    }

    public function test_el_cobro_de_un_debito_de_otra_suscripcion_no_se_toca(): void
    {
        $mia = $this->debito('pre-1');
        $otra = $this->debito('pre-2', '2026-12-10');
        $deOtra = $this->cobroDeSuscripcion($otra, '2026-09');
        $deMia = $this->cobroDeSuscripcion($mia, '2026-10');
        $this->ofrecerPago($this->pagoMp('800', (string)$mia, ['preapproval_id' => 'pre-1']));

        $this->notificar('800');

        $this->assertSame('pendiente', $this->cobroDe($deOtra)['estado']);
        $this->assertSame('pagado', $this->cobroDe($deMia)['estado']);
    }

    public function test_el_cobro_recurrente_se_resuelve_a_su_pago_y_se_registra_como_debito(): void
    {
        $susc = $this->debito();
        $cobro = $this->cobroDeSuscripcion($susc, '2026-10');
        $this->mp['/authorized_payments/900'] = [
            'id' => 900, 'preapproval_id' => 'pre-1', 'status' => 'processed',
            'payment' => ['id' => 800, 'status' => 'approved'],
        ];
        // el pago en sí no trae a qué suscripción pertenece: lo aporta el cobro recurrente
        $this->ofrecerPago($this->pagoMp('800', (string)$susc));

        $r = $this->notificar('900', 'subscription_authorized_payment');

        $this->assertSame('pagado', $r['resultado']);
        $c = $this->cobroDe($cobro);
        $this->assertSame('Mercado Pago · débito', $c['metodo_pago']);
        $this->assertSame('800', $c['mp_payment_id']);
        $n = $this->notificaciones()[0];
        $this->assertSame('subscription_authorized_payment', $n['tipo']);
        $this->assertSame($cobro, (int)$n['cobro_id']);
        $this->assertSame('approved', $n['estado_pago']);
    }

    public function test_un_cobro_recurrente_sin_pago_todavia_no_hace_nada(): void
    {
        $susc = $this->debito();
        $cobro = $this->cobroDeSuscripcion($susc, '2026-10');
        $this->mp['/authorized_payments/900'] = ['id' => 900, 'preapproval_id' => 'pre-1', 'status' => 'scheduled'];

        $r = $this->notificar('900', 'subscription_authorized_payment');

        $this->assertSame(['http' => 200, 'resultado' => 'sin_pago'], $r);
        $this->assertSame('pendiente', $this->cobroDe($cobro)['estado']);
    }

    // ---- Mercado Pago caído ------------------------------------------------------------------------------------

    public function test_si_mercado_pago_no_responde_se_devuelve_500_y_se_registra_el_error(): void
    {
        $cobro = $this->cobro();
        $this->mp['/v1/payments/555'] = new RuntimeException('Mercado Pago no respondió (GET /v1/payments/555)');

        $r = $this->notificar('555');

        $this->assertSame(500, $r['http']);
        $this->assertSame('pendiente', $this->cobroDe($cobro)['estado']);
        $n = $this->notificaciones();
        $this->assertCount(1, $n);
        $this->assertStringContainsString('Mercado Pago', (string)$n[0]['error']);
        $this->assertNull($n[0]['procesada_en']);
        $this->assertSame([], $this->mails);

        // el reintento de Mercado Pago, ya con la API arriba, termina el trabajo
        $this->ofrecerPago($this->pagoMp('555', (string)$cobro));
        $this->assertSame(200, $this->notificar('555')['http']);
        $this->assertSame('pagado', $this->cobroDe($cobro)['estado']);
    }

    public function test_una_respuesta_de_mercado_pago_sin_id_se_trata_como_error_de_consulta(): void
    {
        $this->cobro();
        $this->mp['/v1/payments/555'] = ['status' => 'approved'];

        $this->assertSame(500, $this->notificar('555')['http']);
    }

    // ---- preapproval ------------------------------------------------------------------------------------------

    public function test_la_confirmacion_de_un_preapproval_pasa_la_suscripcion_a_debito(): void
    {
        $susc = $this->debito('pre-1');
        crud_update('suscripciones_cliente', $susc, ['modalidad' => 'link', 'mp_preapproval_estado' => 'pendiente']);
        $this->mp['/preapproval/pre-1'] = ['id' => 'pre-1', 'status' => 'authorized', 'external_reference' => (string)$susc];

        $r = $this->notificar('pre-1', 'subscription_preapproval');

        $this->assertSame(200, $r['http']);
        $s = crud_find('suscripciones_cliente', $susc);
        $this->assertSame('autorizada', $s['mp_preapproval_estado']);
        $this->assertSame('debito', $s['modalidad']);
        $n = $this->notificaciones()[0];
        $this->assertSame('subscription_preapproval', $n['tipo']);
        $this->assertSame(0, (int)$n['sin_cobro']);
        $this->assertNull($n['estado_pago']);
    }

    public function test_suscripcion_confirmar_preapproval_directo(): void
    {
        $susc = $this->debito('pre-1');
        crud_update('suscripciones_cliente', $susc, ['modalidad' => 'link', 'mp_preapproval_estado' => 'pendiente']);

        suscripcion_confirmar_preapproval('pre-1');
        suscripcion_confirmar_preapproval('pre-1');
        suscripcion_confirmar_preapproval('no-existe');

        $s = crud_find('suscripciones_cliente', $susc);
        $this->assertSame('autorizada', $s['mp_preapproval_estado']);
        $this->assertSame('debito', $s['modalidad']);
    }

    public function test_un_preapproval_que_mercado_pago_no_da_por_autorizado_no_cambia_nada(): void
    {
        $susc = $this->debito('pre-1');
        crud_update('suscripciones_cliente', $susc, ['modalidad' => 'link', 'mp_preapproval_estado' => 'pendiente']);
        $this->mp['/preapproval/pre-1'] = ['id' => 'pre-1', 'status' => 'pending'];

        $r = $this->notificar('pre-1', 'subscription_preapproval');

        $this->assertSame(200, $r['http']);
        $s = crud_find('suscripciones_cliente', $susc);
        $this->assertSame('pendiente', $s['mp_preapproval_estado']);
        $this->assertSame('link', $s['modalidad']);
    }

    public function test_un_preapproval_cancelado_en_la_app_no_se_reactiva_por_una_autorizacion_tardia(): void
    {
        $susc = $this->debito('pre-1');
        crud_update('suscripciones_cliente', $susc, ['modalidad' => 'transferencia', 'mp_preapproval_estado' => 'cancelada']);
        $this->mp['/preapproval/pre-1'] = ['id' => 'pre-1', 'status' => 'authorized'];

        $this->notificar('pre-1', 'subscription_preapproval');

        $s = crud_find('suscripciones_cliente', $susc);
        $this->assertSame('cancelada', $s['mp_preapproval_estado']);
        $this->assertSame('transferencia', $s['modalidad']);
    }

    public function test_un_preapproval_desconocido_queda_sin_cobro_marcado(): void
    {
        $this->mp['/preapproval/pre-x'] = ['id' => 'pre-x', 'status' => 'authorized'];

        $r = $this->notificar('pre-x', 'subscription_preapproval');

        $this->assertSame(200, $r['http']);
        $this->assertSame(1, (int)$this->notificaciones()[0]['sin_cobro']);
    }

    // ---- otros ------------------------------------------------------------------------------------------------

    public function test_un_tipo_que_no_se_procesa_se_registra_y_responde_200(): void
    {
        $r = $this->notificar('77', 'merchant_order');

        $this->assertSame(200, $r['http']);
        $this->assertSame([], $this->consultas);
        $n = $this->notificaciones();
        $this->assertCount(1, $n);
        $this->assertSame('merchant_order', $n[0]['tipo']);
        $this->assertNotNull($n[0]['procesada_en']);
    }

    public function test_una_notificacion_firmada_sin_data_id_responde_200_sin_hacer_nada(): void
    {
        $r = mp_webhook_procesar($this->firmar(''), ['type' => 'payment'], '{}');

        $this->assertSame(200, $r['http']);
        $this->assertSame([], $this->consultas);
    }

    public function test_el_tipo_tambien_puede_venir_como_topic_o_en_el_cuerpo(): void
    {
        $cobro = $this->cobro();
        $this->ofrecerPago($this->pagoMp('555', (string)$cobro));

        $r = mp_webhook_procesar($this->firmar('555'), ['data_id' => '555'], json_encode(['type' => 'payment', 'data' => ['id' => '555']]));

        $this->assertSame('pagado', $r['resultado']);
    }

    public function test_cobro_registrar_pago_mp_devuelve_cada_resultado(): void
    {
        $cobro = $this->cobro();
        $base = ['id' => '1', 'status' => 'approved', 'monto' => 85000.0, 'moneda' => 'ARS', 'referencia_externa' => (string)$cobro, 'preapproval_id' => ''];

        $this->assertSame('no_aprobado', cobro_registrar_pago_mp(['status' => 'pending'] + $base));
        $this->assertSame('monto_distinto', cobro_registrar_pago_mp(['monto' => 1.0] + $base));
        $this->assertSame('sin_cobro', cobro_registrar_pago_mp(['referencia_externa' => '99999'] + $base));
        $this->assertSame('pagado', cobro_registrar_pago_mp($base));
        $this->assertSame('ya_pagado', cobro_registrar_pago_mp($base));
    }

    public function test_cobro_de_pago_mp_resuelve_por_referencia_o_por_preapproval(): void
    {
        $susc = $this->debito('pre-1');
        $deSusc = $this->cobroDeSuscripcion($susc, '2026-10');
        $suelto = $this->cobro();
        $base = ['id' => '1', 'status' => 'approved', 'monto' => 85000.0, 'moneda' => 'ARS', 'referencia_externa' => '', 'preapproval_id' => ''];

        $this->assertSame($suelto, (int)cobro_de_pago_mp(['referencia_externa' => (string)$suelto] + $base)['id']);
        $this->assertSame($deSusc, (int)cobro_de_pago_mp(['preapproval_id' => 'pre-1'] + $base)['id']);
        $this->assertNull(cobro_de_pago_mp($base));
        $this->assertNull(cobro_de_pago_mp(['referencia_externa' => 'abc'] + $base));
    }

    public function test_el_mapper_lee_los_campos_de_un_pago_de_mercado_pago(): void
    {
        $this->assertSame(
            ['id' => '555', 'status' => 'approved', 'monto' => 85000.5, 'moneda' => 'ARS', 'referencia_externa' => '12', 'preapproval_id' => ''],
            mp_pago_normalizar(['id' => 555, 'status' => 'approved', 'transaction_amount' => 85000.5, 'currency_id' => 'ARS', 'external_reference' => '12'])
        );
        $this->assertSame('pre-9', mp_pago_normalizar(['id' => 1, 'preapproval_id' => 'pre-9'])['preapproval_id']);
        $this->assertSame('pre-8', mp_pago_normalizar(['id' => 1, 'metadata' => ['preapproval_id' => 'pre-8']])['preapproval_id']);
        $this->assertSame('pre-7', mp_pago_normalizar(['id' => 1, 'point_of_interaction' => ['transaction_data' => ['subscription_id' => 'pre-7']]])['preapproval_id']);
        $this->assertNull(mp_pago_normalizar(['id' => 1])['monto']);
    }
}
