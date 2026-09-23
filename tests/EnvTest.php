<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EnvTest extends TestCase
{
    public function test_parsea_claves_valores_y_comentarios(): void
    {
        $vars = env_parse("# comentario\nA=1\n\nB = hola mundo \nLINEA_INVALIDA\n");
        $this->assertSame(['A' => '1', 'B' => 'hola mundo'], $vars);
    }

    public function test_quita_comillas_y_no_interpola_signos_pesos(): void
    {
        $hash = '$2y$12$abcdefghijklmnopqrstuuJ0vYpG6o5b8qF9m0xZkq3yQy1u2W3e';
        $vars = env_parse('H1=' . $hash . "\n" . "H2='" . $hash . "'\n" . 'H3="' . $hash . '"' . "\n");
        $this->assertSame($hash, $vars['H1']);
        $this->assertSame($hash, $vars['H2']);
        $this->assertSame($hash, $vars['H3']);
    }

    public function test_valor_con_signo_igual_se_conserva(): void
    {
        $this->assertSame('a=b=c', env_parse('DSN=a=b=c')['DSN']);
    }

    public function test_env_devuelve_default_si_falta_o_esta_vacio(): void
    {
        env_set('X_VACIA', '');
        $this->assertSame('def', env('X_VACIA', 'def'));
        $this->assertNull(env('X_NO_EXISTE'));
        env_set('X_VACIA', null);
    }

    public function test_hoy_se_puede_fijar_para_tests(): void
    {
        clock_set('2026-01-31');
        $this->assertSame('2026-01-31', hoy());
        clock_set(null);
        $this->assertSame(date('Y-m-d'), hoy());
    }

    public function test_dias_entre_tiene_signo(): void
    {
        $this->assertSame(3, dias_entre('2026-02-26', '2026-03-01'));
        $this->assertSame(-1, dias_entre('2026-03-01', '2026-02-28'));
        $this->assertSame(0, dias_entre('2026-03-01', '2026-03-01'));
    }
}
