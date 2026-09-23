<?php
declare(strict_types=1);

function q_all(string $sql, array $params = []): array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function q_one(string $sql, array $params = []): ?array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $fila = $st->fetch();
    return $fila === false ? null : $fila;
}

function q_val(string $sql, array $params = []): mixed
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $valor = $st->fetchColumn();
    return $valor === false ? null : $valor;
}

function crud_exists(string $tabla, int $id): bool
{
    return q_val("SELECT 1 FROM `$tabla` WHERE id = ?", [$id]) !== null;
}

function crud_find(string $tabla, int $id): array
{
    $fila = q_one("SELECT * FROM `$tabla` WHERE id = ?", [$id]);
    if ($fila === null) {
        throw new HttpError(404, 'No encontrado');
    }
    return $fila;
}

function crud_insert(string $tabla, array $datos): int
{
    $columnas = array_keys($datos);
    $sql = sprintf(
        'INSERT INTO `%s` (%s) VALUES (%s)',
        $tabla,
        implode(', ', array_map(fn(string $c) => "`$c`", $columnas)),
        implode(', ', array_fill(0, count($columnas), '?'))
    );
    db()->prepare($sql)->execute(array_values($datos));
    return (int)db()->lastInsertId();
}

function crud_update(string $tabla, int $id, array $datos): void
{
    crud_find($tabla, $id);
    if (!$datos) {
        return;
    }
    $sets = implode(', ', array_map(fn(string $c) => "`$c` = ?", array_keys($datos)));
    db()->prepare("UPDATE `$tabla` SET $sets WHERE id = ?")->execute([...array_values($datos), $id]);
}

function crud_delete(string $tabla, int $id): void
{
    crud_find($tabla, $id);
    db()->prepare("DELETE FROM `$tabla` WHERE id = ?")->execute([$id]);
}

function where_eq(array $filtros, array $columnas): array
{
    $partes = [];
    $params = [];
    foreach ($columnas as $clave => $columna) {
        if (!array_key_exists($clave, $filtros) || $filtros[$clave] === null) {
            continue;
        }
        $partes[] = "$columna = ?";
        $params[] = $filtros[$clave];
    }
    return [$partes, $params];
}

function sql_where(array $partes): string
{
    return $partes ? ' WHERE ' . implode(' AND ', $partes) : '';
}

function siguiente_orden(string $tabla, string $grupoCol, int|string $grupoVal): int
{
    return (int)q_val("SELECT COALESCE(MAX(orden), -1) + 1 FROM `$tabla` WHERE `$grupoCol` = ?", [$grupoVal]);
}

/** Ubica $id justo antes de $antesDe (o al final si es null o no está en el grupo) y renumera el grupo. */
function reordenar(string $tabla, int $id, string $grupoCol, int|string $grupoVal, ?int $antesDe): void
{
    $filas = q_all("SELECT id FROM `$tabla` WHERE `$grupoCol` = ? AND id <> ? ORDER BY orden, id", [$grupoVal, $id]);
    $ids = array_map('intval', array_column($filas, 'id'));
    $pos = $antesDe === null ? false : array_search($antesDe, $ids, true);
    array_splice($ids, $pos === false ? count($ids) : $pos, 0, [$id]);
    $st = db()->prepare("UPDATE `$tabla` SET orden = ? WHERE id = ?");
    foreach ($ids as $i => $rid) {
        $st->execute([$i, $rid]);
    }
}

function tx(callable $fn): mixed
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        return $fn();
    }
    $pdo->beginTransaction();
    try {
        $resultado = $fn();
        $pdo->commit();
        return $resultado;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
