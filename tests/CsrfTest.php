<?php
declare(strict_types=1);

final class CsrfTest extends DbTestCase
{
    public function test_token_estable_dentro_de_la_sesion(): void
    {
        $t = csrf_token();
        $this->assertSame(64, strlen($t));
        $this->assertSame($t, csrf_token());
        csrf_check($t);
        $this->addToAssertionCount(1);
    }

    public function test_token_invalido_o_ausente_da_403(): void
    {
        csrf_token();
        $this->assertHttp(403, fn() => csrf_check('otro'));
        $this->assertHttp(403, fn() => csrf_check(null));
    }

    public function test_sin_token_en_sesion_da_403(): void
    {
        $this->assertHttp(403, fn() => csrf_check(''));
    }
}
