<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

abstract class DbTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        db()->beginTransaction();
        $_SESSION = [];
        clock_set(null);
    }

    protected function tearDown(): void
    {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        clock_set(null);
        parent::tearDown();
    }

    protected function assertHttp(int $status, callable $fn): HttpError
    {
        try {
            $fn();
        } catch (HttpError $e) {
            $this->assertSame($status, $e->status, 'Mensaje: ' . $e->getMessage());
            return $e;
        }
        $this->fail("Se esperaba HttpError $status");
    }

    protected function errores422(callable $fn): array
    {
        return $this->assertHttp(422, $fn)->campos;
    }

    protected function crearCliente(string $nombre = 'Cliente Test'): int
    {
        return crud_insert('clientes', ['nombre' => $nombre]);
    }

    protected function crearUsuarioCliente(int $clienteId, string $email = 'ana@sol.com', ?string $clave = 'secreta1', bool $activo = true): int
    {
        return crud_insert('usuarios_cliente', [
            'cliente_id' => $clienteId,
            'email' => $email,
            'password_hash' => $clave === null ? null : password_hash($clave, PASSWORD_BCRYPT, ['cost' => 4]),
            'activo' => $activo ? 1 : 0,
        ]);
    }
}
