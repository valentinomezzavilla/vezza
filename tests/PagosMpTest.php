<?php
declare(strict_types=1);

final class PagosMpTest extends DbTestCase
{
    private const SECRETO = 'secreto-de-prueba';

    private int $cliente;
    private array $plan;
    /** @var array<string, array|Throwable> Lo que contesta Mercado Pago (falso), por ruta. */
    private array $mp = [];
    /** @var list<string> */
    private array $consultas = [];
    /** @var list<array{0: string, 1: string, 2: array}> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();
        clock_set('2026-03-10');
        env_set('MP_WEBHOOK_SECRET', self::SECRETO);
        env_set('MAIL_WEBHOOK_URL', 'https://n8n.test/mail');
        env_set('PORTAL_URL', 'https://demo.test');
        $this->cliente = clientes_create(['nombre' => 'Acme', 'email' => 'acme@sol.com'])['id'];
        $this->plan = planes_create(['nombre' => 'Mantenimiento', 'monto' => '25000', 'moneda' => 'ARS', 'frecuencia' => 'mensual']);
        $this->mp = [];
        $this->consultas = [];
        $this->mails = [];
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
            return true;
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

    /** Una suscripción con débito en Mercado Pago en el estado dado (autorizada por defecto, y entonces en modalidad débito). */
    private function debito(string $estado = 'autorizada', string $preapproval = 'pre-1', string $proximo = '2026-04-01'): int
    {
        $id = suscripciones_cliente_create([
            'cliente_id' => $this->cliente, 'plan_id' => $this->plan['id'], 'fecha_proximo_cobro' => $proximo,
        ])['id'];
        $cambios = ['mp_preapproval_id' => $preapproval, 'mp_preapproval_estado' => $estado];
        if (in_array($estado, ['autorizada', 'pausada'], true)) {
            $cambios['modalidad'] = 'debito';
        }
        crud_update('suscripciones_cliente', $id, $cambios);
        return $id;
    }

    private function fila(int $id): array
    {
        return q_one('SELECT * FROM suscripciones_cliente WHERE id = ?', [$id]);
    }

    /** Un pago como lo devuelve GET /v1/payments/{id}. */
    private function pagoMp(int $id, int $suscId, array $extra = []): array
    {
        return $extra + [
            'id' => $id, 'status' => 'approved', 'transaction_amount' => 25000, 'currency_id' => 'ARS',
            'external_reference' => "susc-$suscId",
        ];
    }

    /** Deja listo lo que Mercado Pago contestaría por un cobro recurrente y por su pago. */
    private function ofrecerCobro(string $cobroId, array $pago, string $preapproval = 'pre-1'): void
    {
        $this->mp["/authorized_payments/$cobroId"] = [
            'id' => (int)$cobroId, 'preapproval_id' => $preapproval, 'status' => 'processed',
            'payment' => ['id' => $pago['id'], 'status' => $pago['status']],
        ];
        $this->mp['/v1/payments/' . $pago['id']] = $pago;
    }

    private function ofrecerPreapproval(string $id, string $status): void
    {
        $this->mp["/preapproval/$id"] = ['id' => $id, 'status' => $status];
    }

    private function ofrecerBusqueda(int $suscId, array $pagos): void
    {
        $query = http_build_query(['external_reference' => "susc-$suscId", 'sort' => 'date_created', 'criteria' => 'desc', 'limit' => 50]);
        $this->mp['/v1/payments/search?' . $query] = ['results' => $pagos];
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
    private function notificar(string $dataId, string $tipo, ?array $headers = null): array
    {
        $cuerpo = json_encode(['action' => $tipo . '.updated', 'type' => $tipo, 'data' => ['id' => $dataId]]);
        return mp_webhook_procesar($headers ?? $this->firmar($dataId), ['data_id' => $dataId, 'type' => $tipo], $cuerpo);
    }

    private function cobrar(string $cobroId, string $tipo = 'subscription_authorized_payment'): array
    {
        return $this->notificar($cobroId, $tipo);
    }

    private function notificaciones(): array
    {
        return q_all('SELECT * FROM mp_notificaciones ORDER BY id');
    }

    private function cobrosDe(int $suscId): array
    {
        return q_all('SELECT * FROM cobros WHERE suscripcion_cliente_id = ? ORDER BY id', [$suscId]);
    }

    // ---- firma ------------------------------------------------------------------------------------------------

    public function test_firma_invalida_responde_401_y_no_cambia_nada(): void
    {
        $s = $this->debito();
        $this->ofrecerCobro('900', $this->pagoMp(9001, $s));

        $r = $this->notificar('900', 'subscription_authorized_payment', $this->firmar('900', null, 'req-1', 'otro-secreto'));

        $this->assertSame(['http' => 401, 'resultado' => 'firma_invalida'], $r);
        $this->assertSame([], $this->notificaciones());
        $this->assertSame([], $this->consultas);
        $this->assertSame([], $this->cobrosDe($s));
        $this->assertSame('2026-04-01', $this->fila($s)['fecha_proximo_cobro']);
    }

    public function test_sin_firma_ni_secreto_tampoco_pasa(): void
    {
        $s = $this->debito();
        $r = mp_webhook_procesar([], ['data_id' => '900', 'type' => 'subscription_authorized_payment'], '{}');
        $this->assertSame(401, $r['http']);

        env_set('MP_WEBHOOK_SECRET', null);
        $this->assertSame(401, $this->notificar('900', 'subscription_authorized_payment', $this->firmar('900'))['http']);
        $this->assertSame([], $this->cobrosDe($s));
    }

    public function test_un_aviso_sin_data_id_responde_200_sin_datos(): void
    {
        $r = mp_webhook_procesar($this->firmar(''), [], '{}');
        $this->assertSame(['http' => 200, 'resultado' => 'sin_datos'], $r);
        $this->assertSame([], $this->notificaciones());
    }

    // ---- cobros del débito ------------------------------------------------------------------------------------

    public function test_el_cobro_aprobado_de_un_debito_crea_el_cobro_pagado_y_adelanta_un_mes(): void
    {
        $s = $this->debito('autorizada', 'pre-1', '2026-03-07'); // venció hace 3 días y el aviso llega recién hoy
        $this->ofrecerCobro('900', $this->pagoMp(9001, $s));

        $r = $this->cobrar('900');

        $this->assertSame(['http' => 200, 'resultado' => 'registrado'], $r);
        $cobros = $this->cobrosDe($s);
        $this->assertCount(1, $cobros);
        $this->assertSame('pagado', $cobros[0]['estado']);
        $this->assertSame('9001', $cobros[0]['mp_payment_id']);
        $this->assertSame('Mercado Pago · débito', $cobros[0]['metodo_pago']);
        $this->assertSame('25000.00', $cobros[0]['monto']);
        $this->assertSame('ARS', $cobros[0]['moneda']);
        $this->assertSame('2026-03-10', $cobros[0]['fecha_pago']);
        $this->assertSame('2026-03-07', $cobros[0]['fecha_vencimiento']);
        $this->assertSame('2026-04-07', $this->fila($s)['fecha_proximo_cobro']);
        $n = $this->notificaciones();
        $this->assertCount(1, $n);
        $this->assertSame('subscription_authorized_payment', $n[0]['tipo']);
        $this->assertNotNull($n[0]['procesada_en']);
        $this->assertSame(0, (int)$n[0]['sin_cobro']);
        $this->assertNull($n[0]['error']);
    }

    public function test_el_mismo_aviso_cinco_veces_suma_una_sola_vez(): void
    {
        $s = $this->debito();
        $this->ofrecerCobro('900', $this->pagoMp(9001, $s));

        $resultados = [];
        for ($i = 0; $i < 5; $i++) {
            $resultados[] = $this->cobrar('900')['resultado'];
        }

        $this->assertSame(['registrado', 'repetido', 'repetido', 'repetido', 'repetido'], $resultados);
        $this->assertCount(1, $this->cobrosDe($s));
        $this->assertSame('2026-05-01', $this->fila($s)['fecha_proximo_cobro']);
        $this->assertCount(5, $this->notificaciones());
    }

    public function test_dos_cobros_distintos_suman_dos_meses(): void
    {
        $s = $this->debito();
        $this->ofrecerCobro('900', $this->pagoMp(9001, $s));
        $this->ofrecerCobro('901', $this->pagoMp(9002, $s));

        $this->cobrar('900');
        $this->cobrar('901');

        $this->assertCount(2, $this->cobrosDe($s));
        $this->assertSame('2026-06-01', $this->fila($s)['fecha_proximo_cobro']);
    }

    public function test_un_pago_ya_registrado_por_otro_camino_no_se_suma_de_nuevo(): void
    {
        $s = $this->debito();
        $pago = mp_pago_normalizar($this->pagoMp(9001, $s));
        $this->assertSame('registrado', mp_pago_registrar($pago)['resultado']);

        $this->ofrecerCobro('900', $this->pagoMp(9001, $s));
        $this->assertSame('repetido', $this->cobrar('900')['resultado']);

        $this->assertCount(1, $this->cobrosDe($s));
        $this->assertSame('2026-05-01', $this->fila($s)['fecha_proximo_cobro']);
    }

    public function test_el_pago_se_asigna_por_la_referencia_si_el_preapproval_no_coincide(): void
    {
        // Un cobro del preapproval viejo, de antes de reactivar: la referencia susc-{id} sigue siendo nuestra.
        $s = $this->debito('autorizada', 'pre-nuevo');
        $this->ofrecerCobro('900', $this->pagoMp(9001, $s), 'pre-viejo');

        $this->assertSame('registrado', $this->cobrar('900')['resultado']);
        $this->assertCount(1, $this->cobrosDe($s));
    }

    public function test_un_pago_de_otra_moneda_queda_sin_cobro(): void
    {
        $s = $this->debito();
        $this->ofrecerCobro('900', $this->pagoMp(9001, $s, ['currency_id' => 'USD']));

        $r = $this->cobrar('900');

        $this->assertSame(['http' => 200, 'resultado' => 'sin_cobro'], $r);
        $this->assertSame([], $this->cobrosDe($s));
        $this->assertSame('2026-04-01', $this->fila($s)['fecha_proximo_cobro']);
        $n = $this->notificaciones();
        $this->assertSame(1, (int)$n[0]['sin_cobro']);
        $this->assertStringContainsString('USD', (string)$n[0]['error']);
    }

    public function test_un_pago_sin_monto_queda_sin_cobro(): void
    {
        $s = $this->debito();
        $pago = $this->pagoMp(9001, $s);
        unset($pago['transaction_amount']);
        $this->ofrecerCobro('900', $pago);

        $this->assertSame('sin_cobro', $this->cobrar('900')['resultado']);
        $this->assertSame([], $this->cobrosDe($s));
    }

    public function test_un_pago_de_un_preapproval_desconocido_queda_sin_cobro(): void
    {
        $this->ofrecerCobro('900', $this->pagoMp(9001, 999999, ['external_reference' => 'otra-cosa']), 'pre-desconocido');

        $r = $this->cobrar('900');

        $this->assertSame(['http' => 200, 'resultado' => 'sin_cobro'], $r);
        $this->assertSame(0, (int)q_val('SELECT COUNT(*) FROM cobros WHERE mp_payment_id IS NOT NULL'));
        $n = $this->notificaciones();
        $this->assertSame(1, (int)$n[0]['sin_cobro']);
        $this->assertNotSame('', (string)$n[0]['error']);
    }

    public function test_un_pago_rechazado_o_pendiente_no_cambia_nada(): void
    {
        $s = $this->debito();
        foreach (['rejected', 'pending', 'in_process', 'cancelled'] as $i => $status) {
            $this->ofrecerCobro((string)(900 + $i), $this->pagoMp(9001 + $i, $s, ['status' => $status]));
            $this->assertSame('no_aprobado', $this->cobrar((string)(900 + $i))['resultado'], $status);
        }
        $this->assertSame([], $this->cobrosDe($s));
        $this->assertSame('2026-04-01', $this->fila($s)['fecha_proximo_cobro']);
        $this->assertSame([], $this->mails);
    }

    public function test_un_cobro_sin_pago_asociado_no_cambia_nada(): void
    {
        $s = $this->debito();
        $this->mp['/authorized_payments/900'] = ['id' => 900, 'preapproval_id' => 'pre-1', 'status' => 'recycling'];

        $this->assertSame(['http' => 200, 'resultado' => 'sin_pago'], $this->cobrar('900'));
        $this->assertSame([], $this->cobrosDe($s));
    }

    public function test_un_cobro_aprobado_de_una_suscripcion_cancelada_igual_se_registra(): void
    {
        $s = $this->debito();
        crud_update('suscripciones_cliente', $s, ['estado' => 'cancelada', 'fecha_baja' => '2026-03-09', 'pausada' => 1]);
        $this->ofrecerCobro('900', $this->pagoMp(9001, $s));

        $this->assertSame('registrado', $this->cobrar('900')['resultado']);
        $this->assertCount(1, $this->cobrosDe($s));
    }

    public function test_si_mercado_pago_no_responde_devuelve_500_y_guarda_el_error(): void
    {
        $s = $this->debito();
        $this->mp['/authorized_payments/900'] = new RuntimeException('Mercado Pago no respondió (GET /authorized_payments/900)');

        $r = $this->cobrar('900');

        $this->assertSame(['http' => 500, 'resultado' => 'error_consulta'], $r);
        $n = $this->notificaciones();
        $this->assertCount(1, $n);
        $this->assertStringContainsString('No se pudo consultar a Mercado Pago', (string)$n[0]['error']);
        $this->assertNull($n[0]['procesada_en']);
        $this->assertSame([], $this->cobrosDe($s));
    }

    // ---- la suscripción en Mercado Pago -----------------------------------------------------------------------

    public function test_la_autorizacion_pasa_la_suscripcion_a_debito(): void
    {
        $s = $this->debito('pendiente');
        $this->ofrecerPreapproval('pre-1', 'authorized');

        $r = $this->notificar('pre-1', 'subscription_preapproval');

        $this->assertSame(['http' => 200, 'resultado' => 'autorizada'], $r);
        $fila = $this->fila($s);
        $this->assertSame('autorizada', $fila['mp_preapproval_estado']);
        $this->assertSame('debito', $fila['modalidad']);
    }

    public function test_un_aviso_viejo_de_pendiente_no_retrocede_una_suscripcion_autorizada(): void
    {
        $s = $this->debito('autorizada');
        $this->ofrecerPreapproval('pre-1', 'pending');

        $this->assertSame('sin_cambios', $this->notificar('pre-1', 'subscription_preapproval')['resultado']);

        $fila = $this->fila($s);
        $this->assertSame('autorizada', $fila['mp_preapproval_estado']);
        $this->assertSame('debito', $fila['modalidad']);
    }

    public function test_la_pausa_externa_marca_pausada_y_la_reanudacion_la_saca(): void
    {
        $s = $this->debito('autorizada');
        $this->ofrecerPreapproval('pre-1', 'paused');
        $this->assertSame('pausada', $this->notificar('pre-1', 'subscription_preapproval')['resultado']);
        $this->assertSame(1, (int)$this->fila($s)['pausada']);
        $this->assertSame('pausada', $this->fila($s)['mp_preapproval_estado']);

        $this->ofrecerPreapproval('pre-1', 'authorized');
        $this->notificar('pre-1', 'subscription_preapproval');
        $this->assertSame(0, (int)$this->fila($s)['pausada']);
        $this->assertSame('autorizada', $this->fila($s)['mp_preapproval_estado']);
    }

    public function test_la_cancelacion_externa_vuelve_a_transferencia_manda_un_solo_mail_y_deja_la_suscripcion_activa(): void
    {
        $s = $this->debito('autorizada');
        $this->ofrecerPreapproval('pre-1', 'cancelled');

        $this->assertSame('cancelada', $this->notificar('pre-1', 'subscription_preapproval')['resultado']);
        $this->notificar('pre-1', 'subscription_preapproval');

        $fila = $this->fila($s);
        $this->assertSame('activa', $fila['estado']);
        $this->assertSame('transferencia', $fila['modalidad']);
        $this->assertSame('cancelada', $fila['mp_preapproval_estado']);
        $this->assertCount(1, $this->mails);
        $this->assertSame('suscripcion_cancelada', $this->mails[0][0]);
        $this->assertSame('acme@sol.com', $this->mails[0][1]);
        $this->assertSame('debito', $this->mails[0][2]['motivo']);
        $this->assertSame('1 de abril de 2026', $this->mails[0][2]['acceso_hasta']);
    }

    public function test_aceptar_las_dos_ortografias_de_cancelado(): void
    {
        $this->assertSame('cancelada', mp_estado_preapproval('cancelled'));
        $this->assertSame('cancelada', mp_estado_preapproval('canceled'));
        $this->assertSame('autorizada', mp_estado_preapproval('authorized'));
        $this->assertSame('pausada', mp_estado_preapproval('paused'));
        $this->assertSame('pendiente', mp_estado_preapproval('pending'));
        $this->assertNull(mp_estado_preapproval('algo-nuevo'));
    }

    public function test_si_ya_se_cancelo_desde_el_admin_el_aviso_de_mercado_pago_no_manda_otro_mail(): void
    {
        $s = $this->debito('autorizada');
        $this->mp['/preapproval/pre-1'] = ['id' => 'pre-1', 'status' => 'cancelled'];
        suscripcion_cliente_cancelar($s); // PUT a Mercado Pago, que contesta con el estado
        $this->assertCount(1, $this->mails);

        $this->notificar('pre-1', 'subscription_preapproval');

        $this->assertCount(1, $this->mails);
        $this->assertSame('cancelada', $this->fila($s)['mp_preapproval_estado']);
    }

    public function test_la_cancelacion_de_un_debito_que_nunca_se_autorizo_no_manda_mail(): void
    {
        $s = $this->debito('pendiente');
        $this->ofrecerPreapproval('pre-1', 'cancelled');

        $this->notificar('pre-1', 'subscription_preapproval');

        $this->assertSame('cancelada', $this->fila($s)['mp_preapproval_estado']);
        $this->assertSame('transferencia', $this->fila($s)['modalidad']);
        $this->assertSame([], $this->mails);
    }

    public function test_un_aviso_de_un_preapproval_desconocido_queda_sin_cobro_y_no_consulta(): void
    {
        $r = $this->notificar('pre-x', 'subscription_preapproval');

        $this->assertSame(['http' => 200, 'resultado' => 'sin_suscripcion'], $r);
        $this->assertSame(1, (int)$this->notificaciones()[0]['sin_cobro']);
    }

    public function test_los_demas_temas_se_ignoran_con_200(): void
    {
        $s = $this->debito();
        $this->ofrecerCobro('900', $this->pagoMp(9001, $s));
        foreach (['payment', 'plan', 'subscription_preapproval_plan', 'topic-raro'] as $tema) {
            $this->assertSame(['http' => 200, 'resultado' => 'ignorado'], $this->notificar('900', $tema), $tema);
        }
        $this->assertSame([], $this->cobrosDe($s));
        $this->assertSame([], $this->consultas);
    }

    // ---- Sincronizar -------------------------------------------------------------------------------------------

    public function test_sincronizar_registra_los_pagos_que_faltan_y_es_idempotente(): void
    {
        $s = $this->debito('pendiente');
        $this->ofrecerPreapproval('pre-1', 'authorized');
        // Mercado Pago contesta lo más nuevo primero.
        $this->ofrecerBusqueda($s, [$this->pagoMp(9002, $s), $this->pagoMp(9001, $s), $this->pagoMp(9000, $s, ['status' => 'rejected'])]);

        $primera = suscripcion_sincronizar($s);

        $this->assertSame(['estado' => 'autorizada', 'pagos' => 2], $primera);
        $this->assertSame('debito', $this->fila($s)['modalidad']);
        $this->assertSame('2026-06-01', $this->fila($s)['fecha_proximo_cobro']);
        $this->assertSame(['9001', '9002'], array_column($this->cobrosDe($s), 'mp_payment_id'));

        $segunda = suscripcion_sincronizar($s);

        $this->assertSame(['estado' => 'autorizada', 'pagos' => 0], $segunda);
        $this->assertCount(2, $this->cobrosDe($s));
        $this->assertSame('2026-06-01', $this->fila($s)['fecha_proximo_cobro']);
    }

    public function test_sincronizar_tras_el_webhook_no_duplica(): void
    {
        $s = $this->debito();
        $this->ofrecerCobro('900', $this->pagoMp(9001, $s));
        $this->cobrar('900');
        $this->ofrecerPreapproval('pre-1', 'authorized');
        $this->ofrecerBusqueda($s, [$this->pagoMp(9001, $s)]);

        $r = suscripcion_sincronizar($s);

        $this->assertSame(0, $r['pagos']);
        $this->assertCount(1, $this->cobrosDe($s));
        $this->assertSame('2026-05-01', $this->fila($s)['fecha_proximo_cobro']);
    }

    public function test_sincronizar_sin_debito_da_409(): void
    {
        $id = suscripciones_cliente_create([
            'cliente_id' => $this->cliente, 'plan_id' => $this->plan['id'], 'fecha_proximo_cobro' => '2026-04-01',
        ])['id'];
        $this->assertHttp(409, fn() => suscripcion_sincronizar($id));
        $this->assertSame([], $this->consultas);
    }

    public function test_sincronizar_con_mercado_pago_caido_da_502_y_no_cambia_nada(): void
    {
        $s = $this->debito('pendiente');
        $this->mp['/preapproval/pre-1'] = new RuntimeException('Mercado Pago no respondió');

        $this->assertHttp(502, fn() => suscripcion_sincronizar($s));

        $this->assertSame('pendiente', $this->fila($s)['mp_preapproval_estado']);
        $this->assertSame([], $this->cobrosDe($s));
    }
}
