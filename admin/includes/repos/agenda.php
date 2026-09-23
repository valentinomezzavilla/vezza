<?php
declare(strict_types=1);

const AGENDA_ORDEN_TIPO = ['evento' => 0, 'entrega' => 1, 'cobro' => 2, 'suscripcion' => 3, 'tarea' => 4];

function agenda_items(array $get): array
{
    $f = filtros($get, [
        'desde' => ['type' => 'date', 'required' => true],
        'hasta' => ['type' => 'date', 'required' => true],
    ]);
    $desde = $f['desde'];
    $hasta = $f['hasta'];
    if ($hasta < $desde) {
        throw new HttpError(422, 'Revisá las fechas', ['hasta' => 'Tiene que ser igual o posterior a "desde"']);
    }
    if (dias_entre($desde, $hasta) > 92) {
        throw new HttpError(422, 'El rango máximo es de 93 días', ['hasta' => 'Rango demasiado largo']);
    }
    $hoy = hoy();
    $items = [];

    $eventos = q_all(
        'SELECT e.id, e.titulo, e.fecha_hora, e.tipo, c.nombre AS cliente_nombre
         FROM eventos e LEFT JOIN clientes c ON c.id = e.cliente_id
         WHERE e.fecha_hora >= ? AND e.fecha_hora < ?',
        [$desde . ' 00:00:00', dia_siguiente($hasta) . ' 00:00:00']
    );
    foreach ($eventos as $e) {
        $items[] = [
            'tipo' => 'evento', 'fecha' => substr($e['fecha_hora'], 0, 10), 'hora' => substr($e['fecha_hora'], 11, 5),
            'titulo' => $e['titulo'], 'subtipo' => $e['tipo'], 'cliente_nombre' => $e['cliente_nombre'], 'ref' => (int)$e['id'],
        ];
    }

    $cobros = q_all(
        "SELECT c.id, c.fecha_vencimiento, c.monto, c.moneda, c.concepto, c.cliente_id, cl.nombre AS cliente_nombre
         FROM cobros c JOIN clientes cl ON cl.id = c.cliente_id
         WHERE c.estado = 'pendiente' AND c.fecha_vencimiento BETWEEN ? AND ?",
        [$desde, $hasta]
    );
    foreach ($cobros as $c) {
        $items[] = [
            'tipo' => 'cobro', 'fecha' => $c['fecha_vencimiento'], 'hora' => null,
            'titulo' => $c['cliente_nombre'] . ($c['concepto'] ? ' · ' . $c['concepto'] : ''),
            'monto' => $c['monto'], 'moneda' => $c['moneda'], 'cliente_id' => (int)$c['cliente_id'],
            'vencido' => $c['fecha_vencimiento'] < $hoy, 'ref' => (int)$c['id'],
        ];
    }

    $suscripciones = q_all(
        'SELECT id, servicio, monto, moneda, fecha_proximo_cobro FROM suscripciones
         WHERE activa = 1 AND fecha_proximo_cobro BETWEEN ? AND ?',
        [$desde, $hasta]
    );
    foreach ($suscripciones as $s) {
        $items[] = [
            'tipo' => 'suscripcion', 'fecha' => $s['fecha_proximo_cobro'], 'hora' => null,
            'titulo' => $s['servicio'], 'monto' => $s['monto'], 'moneda' => $s['moneda'], 'ref' => (int)$s['id'],
        ];
    }

    $entregas = q_all(
        "SELECT p.id, p.titulo, p.fecha_entrega_estimada, c.nombre AS cliente_nombre
         FROM procesos p JOIN clientes c ON c.id = p.cliente_id
         WHERE p.estado <> 'entregado' AND p.fecha_entrega_estimada BETWEEN ? AND ?",
        [$desde, $hasta]
    );
    foreach ($entregas as $p) {
        $items[] = [
            'tipo' => 'entrega', 'fecha' => $p['fecha_entrega_estimada'], 'hora' => null,
            'titulo' => $p['titulo'], 'cliente_nombre' => $p['cliente_nombre'], 'ref' => (int)$p['id'],
        ];
    }

    $tareas = q_all(
        "SELECT id, titulo, fecha_vencimiento FROM tareas_personales
         WHERE estado <> 'hecha' AND fecha_vencimiento BETWEEN ? AND ?",
        [$desde, $hasta]
    );
    foreach ($tareas as $t) {
        $items[] = ['tipo' => 'tarea', 'fecha' => $t['fecha_vencimiento'], 'hora' => null, 'titulo' => $t['titulo'], 'ref' => (int)$t['id']];
    }

    usort($items, fn(array $a, array $b) =>
        [$a['fecha'], $a['hora'] ?? '', AGENDA_ORDEN_TIPO[$a['tipo']]]
        <=> [$b['fecha'], $b['hora'] ?? '', AGENDA_ORDEN_TIPO[$b['tipo']]]);
    return $items;
}
