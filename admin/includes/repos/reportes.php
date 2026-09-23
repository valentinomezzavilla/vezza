<?php
declare(strict_types=1);

function reporte_balance(array $get): array
{
    $f = filtros($get, [
        'desde' => ['type' => 'date', 'required' => true],
        'hasta' => ['type' => 'date', 'required' => true],
        'cliente_id' => ['type' => 'fk', 'table' => 'clientes'],
    ]);
    if ($f['hasta'] < $f['desde']) {
        throw new HttpError(422, 'Revisá las fechas', ['hasta' => 'Tiene que ser igual o posterior a "desde"']);
    }
    if (dias_entre($f['desde'], $f['hasta']) > 366 * 5) {
        throw new HttpError(422, 'El rango máximo es de 5 años', ['hasta' => 'Rango demasiado largo']);
    }
    $cliente = $f['cliente_id'] ?? null;
    $sinGastos = fn(array $fila) => $cliente === null ? $fila : array_diff_key($fila, ['gastos' => true]);

    $meses = [];
    $cursor = new DateTimeImmutable(substr($f['desde'], 0, 7) . '-01');
    $ultimo = substr($f['hasta'], 0, 7);
    while ($cursor->format('Y-m') <= $ultimo) {
        $meses[$cursor->format('Y-m')] = ['mes' => $cursor->format('Y-m'), 'monedas' => []];
        $cursor = $cursor->modify('+1 month');
    }
    foreach (balance_por_moneda($f['desde'], $f['hasta'], $cliente, true) as $fila) {
        $mes = $fila['mes'];
        unset($fila['mes']);
        $meses[$mes]['monedas'][] = $sinGastos($fila);
    }

    return [
        'desde' => $f['desde'],
        'hasta' => $f['hasta'],
        'cliente_id' => $cliente,
        'incluye_gastos' => $cliente === null,
        'meses' => array_values($meses),
        'totales' => array_map($sinGastos, balance_por_moneda($f['desde'], $f['hasta'], $cliente)),
    ];
}
