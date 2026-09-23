<?php
declare(strict_types=1);

final class ComprobantesTest extends DbTestCase
{
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private string $dir;
    private array $cobro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vezza_up_' . bin2hex(random_bytes(4));
        env_set('UPLOADS_DIR', $this->dir);
        $cliente = clientes_create(['nombre' => 'Acme'])['id'];
        $this->cobro = cobros_create(['cliente_id' => $cliente, 'monto' => '10', 'moneda' => 'ARS', 'fecha_vencimiento' => '2026-01-01']);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
        env_set('UPLOADS_DIR', null);
        parent::tearDown();
    }

    private function temporal(string $contenido): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'vz');
        file_put_contents($ruta, $contenido);
        return $ruta;
    }

    private function archivos(): array
    {
        return glob($this->dir . '/*') ?: [];
    }

    public function test_guardar_png_valido(): void
    {
        $tmp = $this->temporal(base64_decode(self::PNG_1X1));
        $c = comprobante_guardar($this->cobro['id'], $tmp, filesize($tmp));
        $this->assertTrue($c['tiene_archivo']);
        $this->assertCount(1, $this->archivos());
        [$ruta, $mime, $ext] = comprobante_archivo($this->cobro['id']);
        $this->assertSame('image/png', $mime);
        $this->assertSame('png', $ext);
        $this->assertFileExists($ruta);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}\.png$/', basename($ruta));
    }

    public function test_rechaza_tipo_no_permitido_aunque_la_extension_mienta(): void
    {
        $tmp = $this->temporal('<?php echo "hola";');
        $this->assertArrayHasKey('archivo', $this->errores422(fn() => comprobante_guardar($this->cobro['id'], $tmp, filesize($tmp))));
        $this->assertCount(0, $this->archivos());
    }

    public function test_rechaza_mas_de_5_mb(): void
    {
        $tmp = $this->temporal(base64_decode(self::PNG_1X1));
        $this->assertArrayHasKey('archivo', $this->errores422(fn() => comprobante_guardar($this->cobro['id'], $tmp, 5 * 1024 * 1024 + 1)));
    }

    public function test_reemplazar_borra_el_anterior_y_quitar_lo_elimina(): void
    {
        $tmp1 = $this->temporal(base64_decode(self::PNG_1X1));
        comprobante_guardar($this->cobro['id'], $tmp1, filesize($tmp1));
        $tmp2 = $this->temporal(base64_decode(self::PNG_1X1));
        comprobante_guardar($this->cobro['id'], $tmp2, filesize($tmp2));
        $this->assertCount(1, $this->archivos());
        comprobante_quitar($this->cobro['id']);
        $this->assertCount(0, $this->archivos());
        $this->assertFalse(cobros_get($this->cobro['id'])['tiene_archivo']);
        $this->assertHttp(404, fn() => comprobante_archivo($this->cobro['id']));
    }

    public function test_borrar_cobro_borra_el_archivo(): void
    {
        $tmp = $this->temporal(base64_decode(self::PNG_1X1));
        comprobante_guardar($this->cobro['id'], $tmp, filesize($tmp));
        cobros_delete($this->cobro['id']);
        $this->assertCount(0, $this->archivos());
    }

    public function test_cobro_inexistente_da_404(): void
    {
        $tmp = $this->temporal(base64_decode(self::PNG_1X1));
        $this->assertHttp(404, fn() => comprobante_guardar(999999, $tmp, filesize($tmp)));
    }
}
