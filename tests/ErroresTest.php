<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ErroresTest extends TestCase
{
    private string|false $antes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->antes = ini_get('display_errors');
    }

    protected function tearDown(): void
    {
        ini_set('display_errors', (string)$this->antes);
        restore_exception_handler();
        parent::tearDown();
    }

    public function test_en_web_no_muestra_errores_en_pantalla(): void
    {
        ini_set('display_errors', '1');
        configurar_errores('apache2handler');
        $this->assertSame('0', ini_get('display_errors'));
    }

    public function test_el_manejador_responde_500_generico_sin_detalles(): void
    {
        ob_start();
        responder_error_interno(new PDOException("SQLSTATE[HY000] [1045] Access denied for user 'u123'@'localhost'"));
        $salida = (string)ob_get_clean();
        $this->assertStringNotContainsString('u123', $salida);
        $this->assertStringContainsString('Algo salió mal', $salida);
    }
}
