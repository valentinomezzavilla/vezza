<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    public function test_sql_split_separa_sentencias_e_ignora_comentarios(): void
    {
        $sql = "-- comentario\nCREATE TABLE a (id INT);\n\nINSERT INTO a VALUES (1);\n-- fin\n";
        $this->assertSame(['CREATE TABLE a (id INT)', 'INSERT INTO a VALUES (1)'], sql_split($sql));
    }

    public function test_esquema_inicial_completo_y_registrado(): void
    {
        $tablas = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $esperadas = ['migraciones', 'clientes', 'notas_cliente', 'procesos', 'subtareas', 'cobros',
            'suscripciones', 'gastos', 'tareas_personales', 'fixs', 'eventos', 'login_intentos'];
        foreach ($esperadas as $tabla) {
            $this->assertContains($tabla, $tablas);
        }
        $this->assertSame([], migraciones_pendientes(db()));
    }

    public function test_migrar_aplica_solo_las_pendientes(): void
    {
        $dir = sys_get_temp_dir() . '/vezza_mig_' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/900_prueba.sql", "CREATE TABLE IF NOT EXISTS prueba_mig (id INT);\n");
        try {
            $this->assertSame(['900_prueba'], migrar(db(), $dir));
            $this->assertSame([], migrar(db(), $dir));
        } finally {
            db()->exec('DROP TABLE IF EXISTS prueba_mig');
            db()->prepare('DELETE FROM migraciones WHERE nombre = ?')->execute(['900_prueba']);
            unlink("$dir/900_prueba.sql");
            rmdir($dir);
        }
    }
}
