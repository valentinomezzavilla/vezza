<?php
declare(strict_types=1);

final class ValidateTest extends DbTestCase
{
    public function test_requerido_falta_en_alta(): void
    {
        $campos = $this->errores422(fn() => validate([], ['nombre' => ['type' => 'string', 'required' => true]]));
        $this->assertSame(['nombre' => 'Es obligatorio'], $campos);
    }

    public function test_parcial_ignora_requeridos_ausentes_pero_no_vacios(): void
    {
        $schema = ['nombre' => ['type' => 'string', 'required' => true]];
        $this->assertSame([], validate([], $schema, true));
        $this->assertArrayHasKey('nombre', $this->errores422(fn() => validate(['nombre' => '  '], $schema, true)));
    }

    public function test_string_vacio_en_opcional_devuelve_null(): void
    {
        $this->assertSame(['email' => null], validate(['email' => ''], ['email' => ['type' => 'email']], true));
    }

    public function test_notnull_permite_ausente_pero_no_vacio(): void
    {
        $schema = ['estado' => ['type' => 'enum', 'values' => ['a', 'b'], 'notnull' => true]];
        $this->assertSame([], validate([], $schema));
        $this->assertArrayHasKey('estado', $this->errores422(fn() => validate(['estado' => ''], $schema)));
    }

    public function test_recorta_espacios_e_ignora_campos_desconocidos(): void
    {
        $this->assertSame(['nombre' => 'Ana'], validate(['nombre' => '  Ana ', 'hackeo' => 'x'], ['nombre' => ['type' => 'string']]));
    }

    public function test_decimal_acepta_coma_y_normaliza(): void
    {
        $schema = ['monto' => ['type' => 'decimal']];
        $this->assertSame('1500.50', validate(['monto' => '1500,5'], $schema)['monto']);
        $this->assertSame('1500.00', validate(['monto' => 1500], $schema)['monto']);
        $this->assertSame('0.10', validate(['monto' => '0.1'], $schema)['monto']);
        $this->assertArrayHasKey('monto', $this->errores422(fn() => validate(['monto' => '-1'], $schema)));
        $this->assertArrayHasKey('monto', $this->errores422(fn() => validate(['monto' => 'abc'], $schema)));
    }

    public function test_decimal_con_punto_de_miles_no_se_divide_por_mil(): void
    {
        $schema = ['monto' => ['type' => 'decimal']];
        $this->assertSame('1500.00', validate(['monto' => '1.500'], $schema)['monto']);
        $this->assertSame('15000.00', validate(['monto' => '15.000'], $schema)['monto']);
        $this->assertSame('1500000.00', validate(['monto' => '1.500.000'], $schema)['monto']);
        $this->assertSame('1500.50', validate(['monto' => '1.500,50'], $schema)['monto']);
        // Valores que ya vienen del servidor (formularios de edición) no cambian.
        $this->assertSame('1500.50', validate(['monto' => '1500.50'], $schema)['monto']);
        $this->assertSame('0.50', validate(['monto' => '0.500'], $schema)['monto']);
        $this->assertArrayHasKey('monto', $this->errores422(fn() => validate(['monto' => '1.50.0'], $schema)));
    }

    public function test_fechas(): void
    {
        $schema = ['f' => ['type' => 'date']];
        $this->assertSame('2028-02-29', validate(['f' => '2028-02-29'], $schema)['f']);
        $this->assertArrayHasKey('f', $this->errores422(fn() => validate(['f' => '2026-02-30'], $schema)));
        $this->assertArrayHasKey('f', $this->errores422(fn() => validate(['f' => '22/09/2026'], $schema)));
    }

    public function test_datetime_acepta_formato_de_input_datetime_local(): void
    {
        $schema = ['fh' => ['type' => 'datetime']];
        $this->assertSame('2026-09-22 14:30:00', validate(['fh' => '2026-09-22T14:30'], $schema)['fh']);
        $this->assertSame('2026-09-22 09:05:10', validate(['fh' => '2026-09-22 09:05:10'], $schema)['fh']);
        $this->assertArrayHasKey('fh', $this->errores422(fn() => validate(['fh' => '2026-09-22 25:00'], $schema)));
    }

    public function test_enum_moneda_bool_email_url_int(): void
    {
        $this->assertArrayHasKey('e', $this->errores422(fn() => validate(['e' => 'z'], ['e' => ['type' => 'enum', 'values' => ['a']]])));
        $this->assertSame('USD', validate(['m' => 'usd'], ['m' => ['type' => 'currency']])['m']);
        $this->assertArrayHasKey('m', $this->errores422(fn() => validate(['m' => 'US'], ['m' => ['type' => 'currency']])));
        $this->assertSame(1, validate(['b' => true], ['b' => ['type' => 'bool']])['b']);
        $this->assertSame(0, validate(['b' => false], ['b' => ['type' => 'bool']])['b']);
        $this->assertSame(0, validate(['b' => '0'], ['b' => ['type' => 'bool']])['b']);
        $this->assertArrayHasKey('b', $this->errores422(fn() => validate(['b' => 'x'], ['b' => ['type' => 'bool']])));
        $this->assertArrayHasKey('c', $this->errores422(fn() => validate(['c' => 'no-es-mail'], ['c' => ['type' => 'email']])));
        $this->assertSame('https://x.com/a', validate(['u' => 'https://x.com/a'], ['u' => ['type' => 'url']])['u']);
        $this->assertArrayHasKey('u', $this->errores422(fn() => validate(['u' => 'javascript:alert(1)'], ['u' => ['type' => 'url']])));
        $this->assertSame(7, validate(['n' => '7'], ['n' => ['type' => 'int']])['n']);
        $this->assertArrayHasKey('n', $this->errores422(fn() => validate(['n' => '0'], ['n' => ['type' => 'int', 'min' => 1]])));
        $this->assertArrayHasKey('n', $this->errores422(fn() => validate(['n' => ['1']], ['n' => ['type' => 'int']])));
    }

    public function test_string_respeta_maximo(): void
    {
        $this->assertArrayHasKey('t', $this->errores422(fn() => validate(['t' => str_repeat('a', 51)], ['t' => ['type' => 'string', 'max' => 50]])));
        $this->assertSame(str_repeat('ñ', 50), validate(['t' => str_repeat('ñ', 50)], ['t' => ['type' => 'string', 'max' => 50]])['t']);
    }

    public function test_filtros_ignora_vacios_y_valida(): void
    {
        $schema = ['estado' => ['type' => 'enum', 'values' => ['a']], 'q' => ['type' => 'string']];
        $this->assertSame(['estado' => 'a'], filtros(['estado' => 'a', 'q' => '', 'otro' => 'x'], $schema));
        $this->assertArrayHasKey('estado', $this->errores422(fn() => filtros(['estado' => 'z'], $schema)));
    }
}
