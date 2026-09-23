<?php
declare(strict_types=1);

function suscripciones_schema(): array
{
    return [
        'servicio' => ['type' => 'string', 'required' => true],
        'categoria' => ['type' => 'string', 'max' => 100],
        'monto' => ['type' => 'decimal', 'required' => true],
        'moneda' => ['type' => 'currency', 'required' => true],
        'frecuencia' => ['type' => 'enum', 'values' => ['mensual', 'anual'], 'required' => true],
        'fecha_proximo_cobro' => ['type' => 'date', 'required' => true],
        'activa' => ['type' => 'bool', 'notnull' => true],
    ];
}

/** Suma 1 mes o 1 año; si el día no existe en el mes destino, usa el último día. */
function sumar_periodo(string $fecha, string $frecuencia): string
{
    [$anio, $mes, $dia] = array_map('intval', explode('-', $fecha));
    if ($frecuencia === 'anual') {
        $anio++;
    } else {
        $mes++;
        if ($mes > 12) {
            $mes = 1;
            $anio++;
        }
    }
    $ultimo = (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $anio, $mes)))->format('t');
    return sprintf('%04d-%02d-%02d', $anio, $mes, min($dia, $ultimo));
}

function suscripcion_publica(array $s): array
{
    $s['dias_restantes'] = dias_entre(hoy(), $s['fecha_proximo_cobro']);
    return $s;
}

function suscripciones_list(array $get): array
{
    $f = filtros($get, ['activa' => ['type' => 'bool']]);
    [$partes, $params] = where_eq($f, ['activa' => 'activa']);
    $sql = 'SELECT * FROM suscripciones' . sql_where($partes) . ' ORDER BY activa DESC, fecha_proximo_cobro, servicio';
    return array_map('suscripcion_publica', q_all($sql, $params));
}

function suscripciones_get(int $id): array
{
    return suscripcion_publica(crud_find('suscripciones', $id));
}

function suscripciones_create(array $input): array
{
    return suscripciones_get(crud_insert('suscripciones', validate($input, suscripciones_schema())));
}

function suscripciones_update(int $id, array $input): array
{
    crud_update('suscripciones', $id, validate($input, suscripciones_schema(), true));
    return suscripciones_get($id);
}

function suscripciones_delete(int $id): void
{
    crud_delete('suscripciones', $id);
}

function suscripciones_pagar(int $id, array $input): array
{
    $s = crud_find('suscripciones', $id);
    if (!(int)$s['activa']) {
        throw new HttpError(409, 'La suscripción está pausada. Activala para registrar pagos.');
    }
    $datos = validate($input, ['fecha' => ['type' => 'date'], 'monto' => ['type' => 'decimal']], true);
    return tx(function () use ($s, $datos, $id): array {
        $gastoId = crud_insert('gastos', [
            'suscripcion_id' => $id,
            'concepto' => $s['servicio'],
            'categoria' => $s['categoria'],
            'monto' => $datos['monto'] ?? $s['monto'],
            'moneda' => $s['moneda'],
            'fecha' => $datos['fecha'] ?? $s['fecha_proximo_cobro'],
        ]);
        crud_update('suscripciones', $id, ['fecha_proximo_cobro' => sumar_periodo($s['fecha_proximo_cobro'], $s['frecuencia'])]);
        return ['gasto' => gastos_get($gastoId), 'suscripcion' => suscripciones_get($id)];
    });
}
