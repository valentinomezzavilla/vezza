<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ResponderYSeguirTest extends TestCase
{
    public function test_en_cli_no_rompe_y_deja_el_trabajo_posterior_en_marcha(): void
    {
        $antes = ignore_user_abort();
        try {
            responder_y_seguir();
            $this->assertSame(1, ignore_user_abort(), 'Tiene que seguir aunque el navegador corte la conexión');
        } finally {
            ignore_user_abort((bool)$antes);
        }
    }
}
