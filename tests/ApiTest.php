<?php
declare(strict_types=1);

final class ApiTest extends DbTestCase
{
    private array $handlers;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION['admin'] = true;
        $this->handlers = [
            'list' => fn(array $get) => [['id' => 1]],
            'get' => fn(int $id) => ['id' => $id],
            'create' => fn(array $in) => ['id' => 9] + $in,
            'update' => fn(int $id, array $in) => ['id' => $id] + $in,
            'delete' => fn(int $id) => null,
        ];
    }

    private function llamar(string $metodo, ?string $id = null, ?string $cuerpo = null, bool $conCsrf = true, ?array $handlers = null): array
    {
        $_SERVER['REQUEST_METHOD'] = $metodo;
        $_GET = $id === null ? [] : ['id' => $id];
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        if ($conCsrf) {
            $_SERVER['HTTP_X_CSRF_TOKEN'] = csrf_token();
        }
        Http::$cuerpoPrueba = $cuerpo;
        ob_start();
        api_resource($handlers ?? $this->handlers);
        $salida = (string)ob_get_clean();
        Http::$cuerpoPrueba = null;
        return [Http::$ultimoStatus, $salida === '' ? null : json_decode($salida, true)];
    }

    public function test_sin_sesion_da_401(): void
    {
        $_SESSION = [];
        [$status, $cuerpo] = $this->llamar('GET');
        $this->assertSame(401, $status);
        $this->assertSame('Sesión expirada', $cuerpo['error']);
    }

    public function test_post_sin_csrf_da_403(): void
    {
        [$status] = $this->llamar('POST', null, '{}', false);
        $this->assertSame(403, $status);
    }

    public function test_get_lista_y_detalle(): void
    {
        $this->assertSame([200, ['data' => [['id' => 1]]]], $this->llamar('GET'));
        $this->assertSame([200, ['data' => ['id' => 4]]], $this->llamar('GET', '4'));
    }

    public function test_id_invalido_da_404_y_metodo_no_soportado_405(): void
    {
        $this->assertSame(404, $this->llamar('GET', 'abc')[0]);
        $this->assertSame(405, $this->llamar('PUT')[0]);
        $this->assertSame(405, $this->llamar('PATCH', '3')[0]);
    }

    public function test_crear_actualizar_borrar(): void
    {
        $this->assertSame([201, ['data' => ['id' => 9, 'a' => 1]]], $this->llamar('POST', null, '{"a":1}'));
        $this->assertSame([200, ['data' => ['id' => 3, 'b' => 2]]], $this->llamar('PUT', '3', '{"b":2}'));
        $this->assertSame([204, null], $this->llamar('DELETE', '3'));
    }

    public function test_json_invalido_da_400(): void
    {
        $this->assertSame(400, $this->llamar('POST', null, '{roto')[0]);
    }

    public function test_errores_de_validacion_y_genericos(): void
    {
        $h = $this->handlers;
        $h['create'] = fn() => throw new HttpError(422, 'Revisá', ['nombre' => 'Es obligatorio']);
        $this->assertSame([422, ['error' => 'Revisá', 'campos' => ['nombre' => 'Es obligatorio']]], $this->llamar('POST', null, '{}', true, $h));
        $h['list'] = fn() => throw new RuntimeException('detalle interno');
        $this->assertSame([500, ['error' => 'Error interno']], $this->llamar('GET', null, null, true, $h));
    }
}
