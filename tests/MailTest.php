<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MailTest extends TestCase
{
    protected function tearDown(): void
    {
        Mail::$transporte = null;
        env_set('MAIL_WEBHOOK_URL', null);
        env_set('MAIL_WEBHOOK_SECRET', null);
        env_set('PORTAL_URL', null);
        parent::tearDown();
    }

    public function test_portal_url_usa_el_env_sin_barra_final(): void
    {
        env_set('PORTAL_URL', 'https://demo.test/');
        $this->assertSame('https://demo.test/clientes/activar?token=abc', portal_url('/clientes/activar?token=abc'));
    }

    public function test_portal_url_por_defecto_es_el_dominio_de_vezza(): void
    {
        env_set('PORTAL_URL', null);
        $this->assertSame('https://vezzadev.com/clientes', portal_url('/clientes'));
    }

    public function test_sin_webhook_configurado_devuelve_false(): void
    {
        env_set('MAIL_WEBHOOK_URL', null);
        Mail::$transporte = fn() => throw new LogicException('No debería intentar enviar');
        $this->assertFalse(mail_enviar('invitacion', 'ana@sol.com', ['link' => 'x']));
    }

    public function test_arma_el_payload_y_manda_el_secreto(): void
    {
        env_set('MAIL_WEBHOOK_URL', 'https://n8n.test/webhook/vezza-portal-mail');
        env_set('MAIL_WEBHOOK_SECRET', 'shh');
        $visto = [];
        Mail::$transporte = function (string $url, string $secreto, array $payload) use (&$visto): bool {
            $visto = [$url, $secreto, $payload];
            return true;
        };
        $this->assertTrue(mail_enviar('invitacion', 'ana@sol.com', ['nombre' => 'Sol', 'link' => 'https://x/y']));
        $this->assertSame('https://n8n.test/webhook/vezza-portal-mail', $visto[0]);
        $this->assertSame('shh', $visto[1]);
        $this->assertSame(
            ['plantilla' => 'invitacion', 'destino' => 'ana@sol.com', 'datos' => ['nombre' => 'Sol', 'link' => 'https://x/y']],
            $visto[2]
        );
    }

    public function test_si_el_transporte_falla_o_lanza_devuelve_false_sin_romper(): void
    {
        env_set('MAIL_WEBHOOK_URL', 'https://n8n.test/hook');
        Mail::$transporte = fn() => false;
        $this->assertFalse(mail_enviar('recuperacion', 'ana@sol.com', ['link' => 'x']));
        Mail::$transporte = fn() => throw new RuntimeException('se cayó n8n');
        $this->assertFalse(mail_enviar('recuperacion', 'ana@sol.com', ['link' => 'x']));
    }
}
