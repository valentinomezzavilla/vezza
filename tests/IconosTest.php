<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class IconosTest extends TestCase
{
    public function test_icono_es_svg_decorativo_oculto_al_lector_de_pantalla(): void
    {
        $svg = icono('inicio');
        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString('aria-hidden="true"', $svg);
        $this->assertStringContainsString('focusable="false"', $svg);
        $this->assertStringContainsString('stroke="currentColor"', $svg);
    }

    public function test_hay_icono_para_cada_seccion_de_la_navegacion(): void
    {
        foreach (array_keys(NAV_PRINCIPAL + NAV_MAS) as $clave) {
            $this->assertNotSame('', icono($clave), "Falta el ícono de $clave");
        }
        $this->assertNotSame('', icono('mas'));
        $this->assertNotSame('', icono('salir'));
    }

    public function test_icono_desconocido_no_rompe(): void
    {
        $this->assertSame('', icono('no-existe'));
    }
}
