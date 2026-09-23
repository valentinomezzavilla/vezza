<?php
declare(strict_types=1);

function balance_por_moneda(string $desde, string $hasta, ?int $clienteId = null, bool $porMes = false): array
{
    $mesIngreso = $porMes ? "DATE_FORMAT(fecha_pago, '%Y-%m') AS mes, " : '';
    $mesGasto = $porMes ? "DATE_FORMAT(fecha, '%Y-%m') AS mes, " : '';
    $cero = 'CAST(0 AS DECIMAL(12,2))';

    $union = "SELECT {$mesIngreso}moneda, monto AS ingresos, $cero AS gastos
              FROM cobros WHERE estado = 'pagado' AND fecha_pago BETWEEN ? AND ?";
    $params = [$desde, $hasta];
    if ($clienteId !== null) {
        $union .= ' AND cliente_id = ?';
        $params[] = $clienteId;
    } else {
        $union .= " UNION ALL SELECT {$mesGasto}moneda, $cero, monto FROM gastos WHERE fecha BETWEEN ? AND ?";
        array_push($params, $desde, $hasta);
    }
    $grupo = $porMes ? 'mes, moneda' : 'moneda';
    $sql = 'SELECT ' . ($porMes ? 'mes, ' : '') . "moneda,
                   SUM(ingresos) AS ingresos, SUM(gastos) AS gastos, SUM(ingresos) - SUM(gastos) AS balance
            FROM ($union) t GROUP BY $grupo ORDER BY $grupo";
    return q_all($sql, $params);
}

function dashboard_resumen(): array
{
    $hoy = hoy();
    $inicioMes = substr($hoy, 0, 8) . '01';
    $finMes = (new DateTimeImmutable($inicioMes))->format('Y-m-t');
    $en14 = (new DateTimeImmutable($hoy))->modify('+14 days')->format('Y-m-d');

    $procesos = q_all(
        "SELECT p.id, p.titulo, p.estado, p.prioridad, p.fecha_entrega_estimada, c.id AS cliente_id, c.nombre AS cliente_nombre
         FROM procesos p JOIN clientes c ON c.id = p.cliente_id
         WHERE p.estado <> 'entregado'
         ORDER BY c.nombre, c.id, FIELD(p.estado, 'por_hacer', 'en_curso', 'en_revision'), p.orden, p.id"
    );
    $porCliente = [];
    foreach ($procesos as $p) {
        $cid = (int)$p['cliente_id'];
        $porCliente[$cid] ??= ['cliente_id' => $cid, 'cliente_nombre' => $p['cliente_nombre'], 'procesos' => []];
        unset($p['cliente_id'], $p['cliente_nombre']);
        $porCliente[$cid]['procesos'][] = $p;
    }

    return [
        'mes' => substr($hoy, 0, 7),
        'balance_mes' => balance_por_moneda($inicioMes, $finMes),
        'cobros_pendientes' => array_map('cobro_publico', q_all(
            cobros_select() . " WHERE c.estado = 'pendiente' ORDER BY c.fecha_vencimiento, c.id LIMIT 10",
            [$hoy]
        )),
        'cobros_pendientes_totales' => q_all(
            "SELECT moneda,
                    SUM(CASE WHEN fecha_vencimiento < ? THEN monto ELSE 0 END) AS vencido,
                    SUM(CASE WHEN fecha_vencimiento >= ? THEN monto ELSE 0 END) AS por_vencer
             FROM cobros WHERE estado = 'pendiente' GROUP BY moneda ORDER BY moneda",
            [$hoy, $hoy]
        ),
        'suscripciones_proximas' => array_map('suscripcion_publica', q_all(
            'SELECT * FROM suscripciones WHERE activa = 1 AND fecha_proximo_cobro <= ? ORDER BY fecha_proximo_cobro, servicio',
            [$en14]
        )),
        'procesos_por_cliente' => array_values($porCliente),
        'tareas_abiertas' => (int)q_val("SELECT COUNT(*) FROM tareas_personales WHERE estado <> 'hecha'"),
        'fixs_abiertos' => (int)q_val("SELECT COUNT(*) FROM fixs WHERE estado <> 'resuelto'"),
        'proximos_eventos' => q_all(EVENTOS_SELECT . ' WHERE e.fecha_hora >= ? ORDER BY e.fecha_hora, e.id LIMIT 5', [$hoy . ' 00:00:00']),
    ];
}
