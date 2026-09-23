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
}
