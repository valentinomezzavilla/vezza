<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SesionesTest extends TestCase
{
    protected function tearDown(): void
    {
        env_set('SESSIONS_DIR', null);
        parent::tearDown();
    }

    public function test_crea_la_carpeta_configurada_si_no_existe(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vezza_ses_' . bin2hex(random_bytes(4));
        env_set('SESSIONS_DIR', $dir);
        try {
            $this->assertSame($dir, sessions_dir());
            $this->assertDirectoryExists($dir);
        } finally {
            @rmdir($dir);
        }
    }

    public function test_por_defecto_queda_fuera_de_public_html(): void
    {
        env_set('SESSIONS_DIR', null);
        $raizRepo = dirname(__DIR__);
        $esperada = dirname($raizRepo) . DIRECTORY_SEPARATOR . 'vezza_sessions';
        $this->assertSame(str_replace('\\', '/', $esperada), str_replace('\\', '/', sessions_dir(false)));
    }
}
