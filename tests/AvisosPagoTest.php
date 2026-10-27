<?php
declare(strict_types=1);

final class AvisosPagoTest extends DbTestCase
{
    /** @var list<array{0: string, 1: string, 2: array}> */
    private array $mails = [];
    private bool $mailOk = true;

    protected function setUp(): void
    {
        parent::setUp();
        clock_set('2026-10-27');
        env_set('MAIL_WEBHOOK_URL', 'https://n8n.test/mail');
        env_set('PORTAL_URL', 'https://demo.test');
        $this->mails = [];
        $this->mailOk = true;
        Mail::$transporte = function (string $url, string $secreto, array $payload): bool {
            $this->mails[] = [$payload['plantilla'], $payload['destino'], $payload['datos']];
            return $this->mailOk;
        };
    }

    protected function tearDown(): void
    {
        Mail::$transporte = null;
        env_set('MAIL_WEBHOOK_URL', null);
        env_set('PORTAL_URL', null);
        parent::tearDown();
    }

    private function cobro(array $extra = [], ?string $email = 'acme@sol.com'): array
    {
        $cliente = crud_insert('clientes', ['nombre' => 'Acme', 'email' => $email]);
        $datos = $extra + [
            'cliente_id' => $cliente, 'concepto' => 'Mantenimiento · octubre 2026', 'monto' => '85000.00',
            'moneda' => 'ARS', 'fecha_vencimiento' => '2026-11-01', 'link_pago' => 'https://mp.test/pagar/1',
        ];
        $datos['id'] = crud_insert('cobros', $datos);
        return $datos;
    }

    private function filas(int $cobroId): array
    {
        return q_all('SELECT * FROM avisos_pago WHERE cobro_id = ? ORDER BY id', [$cobroId]);
    }

    public function test_monto_formato_ar(): void
    {
        $this->assertSame('$85.000', monto_formato_ar('85000'));
        $this->assertSame('$85.000', monto_formato_ar('85000.00'));
        $this->assertSame('$85.000', monto_formato_ar(85000.0));
        $this->assertSame('$85.000,50', monto_formato_ar('85000.50'));
        $this->assertSame('$1.250.000', monto_formato_ar(1250000));
        $this->assertSame('$999', monto_formato_ar('999'));
        $this->assertSame('$0,05', monto_formato_ar('0.05'));
        $this->assertSame('$0', monto_formato_ar('0'));
    }

    public function test_aviso_datos_cobro_arma_los_datos_de_la_plantilla(): void
    {
        $c = $this->cobro();

        $this->assertSame([
            'nombre' => 'Acme',
            'concepto' => 'Mantenimiento · octubre 2026',
            'monto' => '$85.000',
            'fecha_vencimiento' => '1 nov',
            'dias' => 5,
            'link_pago' => 'https://mp.test/pagar/1',
            'portal' => 'https://demo.test/clientes/pagos',
        ], aviso_datos_cobro($c));
    }

    public function test_aviso_datos_cobro_dias_es_negativo_si_esta_vencido_y_el_link_puede_faltar(): void
    {
        $c = $this->cobro(['fecha_vencimiento' => '2026-10-20', 'link_pago' => null]);

        $datos = aviso_datos_cobro($c);

        $this->assertSame(-7, $datos['dias']);
        $this->assertSame('20 oct', $datos['fecha_vencimiento']);
        $this->assertSame('', $datos['link_pago']);
    }

    public function test_aviso_registrar_crea_una_fila_pendiente_por_mail(): void
    {
        $c = $this->cobro();

        aviso_registrar($c['id'], 'confirmacion', 0);

        $filas = $this->filas($c['id']);
        $this->assertCount(1, $filas);
        $this->assertSame('confirmacion', $filas[0]['tipo']);
        $this->assertSame('mail', $filas[0]['canal']);
        $this->assertSame(0, (int)$filas[0]['numero']);
        $this->assertSame(0, (int)$filas[0]['ok']);
        $this->assertSame([], $this->mails, 'registrar no manda nada');
    }

    public function test_aviso_registrar_no_toca_una_fila_existente(): void
    {
        $c = $this->cobro();
        aviso_registrar($c['id'], 'confirmacion', 0);
        db()->exec('UPDATE avisos_pago SET ok = 1');

        aviso_registrar($c['id'], 'confirmacion', 0);

        $filas = $this->filas($c['id']);
        $this->assertCount(1, $filas);
        $this->assertSame(1, (int)$filas[0]['ok']);
    }

    public function test_cada_tipo_y_numero_es_un_aviso_distinto(): void
    {
        $c = $this->cobro();

        aviso_registrar($c['id'], 'vencido', 1);
        aviso_registrar($c['id'], 'vencido', 2);
        aviso_registrar($c['id'], 'confirmacion', 0);

        $this->assertCount(3, $this->filas($c['id']));
    }

    public function test_el_despachador_manda_la_plantilla_de_cada_tipo_al_mail_del_cliente(): void
    {
        $c = $this->cobro();
        aviso_registrar($c['id'], 'confirmacion', 0);
        aviso_registrar($c['id'], 'rechazo', 0);

        $this->assertSame(['enviados' => 2, 'fallidos' => 0], avisos_despachar_inmediatos());

        $this->assertSame([
            ['pago_confirmado', 'acme@sol.com', aviso_datos_cobro($c)],
            ['debito_rechazado', 'acme@sol.com', aviso_datos_cobro($c)],
        ], $this->mails);
        foreach ($this->filas($c['id']) as $fila) {
            $this->assertSame(1, (int)$fila['ok']);
            $this->assertNotNull($fila['enviado_en']);
        }
    }

    public function test_el_despachador_no_manda_dos_veces_lo_ya_enviado(): void
    {
        $c = $this->cobro();
        aviso_registrar($c['id'], 'confirmacion', 0);

        avisos_despachar_inmediatos();
        $segunda = avisos_despachar_inmediatos();

        $this->assertSame(['enviados' => 0, 'fallidos' => 0], $segunda);
        $this->assertCount(1, $this->mails);
    }

    public function test_si_el_mail_falla_queda_en_cero_y_se_reintenta(): void
    {
        $c = $this->cobro();
        aviso_registrar($c['id'], 'rechazo', 0);
        $this->mailOk = false;

        $this->assertSame(['enviados' => 0, 'fallidos' => 1], avisos_despachar_inmediatos());
        $this->assertSame(0, (int)$this->filas($c['id'])[0]['ok']);

        $this->mailOk = true;
        $this->assertSame(['enviados' => 1, 'fallidos' => 0], avisos_despachar_inmediatos());
        $this->assertSame(1, (int)$this->filas($c['id'])[0]['ok']);
        $this->assertCount(2, $this->mails);
    }

    public function test_un_cliente_sin_mail_queda_pendiente_y_cuenta_como_fallido(): void
    {
        $c = $this->cobro([], null);
        aviso_registrar($c['id'], 'confirmacion', 0);

        $this->assertSame(['enviados' => 0, 'fallidos' => 1], avisos_despachar_inmediatos());

        $this->assertSame([], $this->mails);
        $this->assertSame(0, (int)$this->filas($c['id'])[0]['ok']);
    }

    public function test_el_despachador_solo_toma_confirmacion_y_rechazo(): void
    {
        $c = $this->cobro();
        aviso_registrar($c['id'], 'previo', 0);
        aviso_registrar($c['id'], 'vencido', 1);

        $this->assertSame(['enviados' => 0, 'fallidos' => 0], avisos_despachar_inmediatos());

        $this->assertSame([], $this->mails);
    }

    public function test_el_despachador_libera_el_candado_al_terminar(): void
    {
        $c = $this->cobro();
        aviso_registrar($c['id'], 'confirmacion', 0);

        avisos_despachar_inmediatos();

        $this->assertSame('1', (string)q_val("SELECT IS_FREE_LOCK('avisos_pago_despacho')"));
    }
}
