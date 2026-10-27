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

    public function test_registra_la_fila_antes_de_enviar_y_la_pasa_a_ok(): void
    {
        $c = $this->cobro();
        $visto = null;
        Mail::$transporte = function (string $url, string $secreto, array $payload) use ($c, &$visto): bool {
            $visto = $this->filas($c['id']);
            return true;
        };

        $this->assertTrue(aviso_registrar_y_enviar_mail($c['id'], 'confirmacion', 0, 'pago_confirmado', $c));

        $this->assertCount(1, $visto, 'la fila tiene que existir antes de mandar el mail');
        $this->assertSame(0, (int)$visto[0]['ok']);
        $filas = $this->filas($c['id']);
        $this->assertCount(1, $filas);
        $this->assertSame('confirmacion', $filas[0]['tipo']);
        $this->assertSame('mail', $filas[0]['canal']);
        $this->assertSame(0, (int)$filas[0]['numero']);
        $this->assertSame(1, (int)$filas[0]['ok']);
        $this->assertNotNull($filas[0]['enviado_en']);
    }

    public function test_manda_la_plantilla_al_mail_del_cliente_con_los_datos_del_cobro(): void
    {
        $c = $this->cobro();

        aviso_registrar_y_enviar_mail($c['id'], 'confirmacion', 0, 'pago_confirmado', $c);

        $this->assertSame([['pago_confirmado', 'acme@sol.com', aviso_datos_cobro($c)]], $this->mails);
    }

    public function test_si_ya_se_envio_no_lo_manda_de_nuevo(): void
    {
        $c = $this->cobro();

        $this->assertTrue(aviso_registrar_y_enviar_mail($c['id'], 'confirmacion', 0, 'pago_confirmado', $c));
        $this->assertTrue(aviso_registrar_y_enviar_mail($c['id'], 'confirmacion', 0, 'pago_confirmado', $c));

        $this->assertCount(1, $this->mails);
        $this->assertCount(1, $this->filas($c['id']));
    }

    public function test_si_el_mail_falla_la_fila_queda_en_cero_y_se_reintenta(): void
    {
        $c = $this->cobro();
        $this->mailOk = false;

        $this->assertFalse(aviso_registrar_y_enviar_mail($c['id'], 'rechazo', 0, 'debito_rechazado', $c));

        $filas = $this->filas($c['id']);
        $this->assertCount(1, $filas);
        $this->assertSame(0, (int)$filas[0]['ok']);

        $this->mailOk = true;
        $this->assertTrue(aviso_registrar_y_enviar_mail($c['id'], 'rechazo', 0, 'debito_rechazado', $c));

        $this->assertCount(2, $this->mails);
        $filas = $this->filas($c['id']);
        $this->assertCount(1, $filas);
        $this->assertSame(1, (int)$filas[0]['ok']);
    }

    public function test_cada_tipo_y_numero_es_un_aviso_distinto(): void
    {
        $c = $this->cobro();

        aviso_registrar_y_enviar_mail($c['id'], 'vencido', 1, 'pago_vencido', $c);
        aviso_registrar_y_enviar_mail($c['id'], 'vencido', 2, 'pago_vencido', $c);
        aviso_registrar_y_enviar_mail($c['id'], 'confirmacion', 0, 'pago_confirmado', $c);

        $this->assertCount(3, $this->mails);
        $this->assertCount(3, $this->filas($c['id']));
    }

    public function test_un_cliente_sin_mail_no_recibe_nada_y_no_deja_fila(): void
    {
        $c = $this->cobro([], null);

        $this->assertFalse(aviso_registrar_y_enviar_mail($c['id'], 'confirmacion', 0, 'pago_confirmado', $c));

        $this->assertSame([], $this->mails);
        $this->assertSame([], $this->filas($c['id']));
    }
}
