<?php
declare(strict_types=1);

function validate(array $input, array $schema, bool $parcial = false): array
{
    $datos = [];
    $errores = [];
    foreach ($schema as $campo => $regla) {
        if (!array_key_exists($campo, $input)) {
            if (!$parcial && !empty($regla['required'])) {
                $errores[$campo] = 'Es obligatorio';
            }
            continue;
        }
        $valor = is_string($input[$campo]) ? trim($input[$campo]) : $input[$campo];
        if ($valor === null || $valor === '') {
            if (!empty($regla['required']) || !empty($regla['notnull'])) {
                $errores[$campo] = 'Es obligatorio';
            } else {
                $datos[$campo] = null;
            }
            continue;
        }
        try {
            $datos[$campo] = validar_valor($valor, $regla);
        } catch (InvalidArgumentException $e) {
            $errores[$campo] = $e->getMessage();
        }
    }
    if ($errores) {
        throw new HttpError(422, 'Revisá los datos marcados', $errores);
    }
    return $datos;
}

function validar_valor(mixed $v, array $r): mixed
{
    switch ($r['type']) {
        case 'string':
        case 'text':
            if (!is_string($v) && !is_int($v) && !is_float($v)) {
                throw new InvalidArgumentException('Tiene que ser texto');
            }
            $v = (string)$v;
            $max = $r['max'] ?? ($r['type'] === 'string' ? 255 : 16000);
            if (mb_strlen($v) > $max) {
                throw new InvalidArgumentException("Máximo $max caracteres");
            }
            return $v;

        case 'email':
            if (!is_string($v) || mb_strlen($v) > 255 || filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException('Email inválido');
            }
            return $v;

        case 'url':
            $s = validar_valor($v, ['type' => 'string', 'max' => $r['max'] ?? 500]);
            $esquema = strtolower((string)parse_url($s, PHP_URL_SCHEME));
            if (filter_var($s, FILTER_VALIDATE_URL) === false || !in_array($esquema, ['http', 'https'], true)) {
                throw new InvalidArgumentException('Link inválido (tiene que empezar con https://)');
            }
            return $s;

        case 'int':
            if (!is_int($v) && !(is_string($v) && preg_match('/^-?\d{1,10}$/', $v))) {
                throw new InvalidArgumentException('Tiene que ser un número entero');
            }
            $v = (int)$v;
            if (isset($r['min']) && $v < $r['min']) {
                throw new InvalidArgumentException("Mínimo {$r['min']}");
            }
            if (isset($r['max']) && $v > $r['max']) {
                throw new InvalidArgumentException("Máximo {$r['max']}");
            }
            return $v;

        case 'decimal':
            $s = $v;
            if (is_string($v)) {
                // es-AR: "1.500" o "1.500,50" usan punto de miles; "1500.50" (lo que devuelve el servidor) no.
                $s = preg_match('/^[1-9]\d{0,2}(\.\d{3})+(,\d+)?$/', $v)
                    ? str_replace(['.', ','], ['', '.'], $v)
                    : str_replace(',', '.', $v);
            }
            if (!is_int($s) && !is_float($s) && !(is_string($s) && preg_match('/^\d+(\.\d+)?$/', $s))) {
                throw new InvalidArgumentException(is_string($s) && str_starts_with($s, '-') ? 'No puede ser negativo' : 'Tiene que ser un número (ej. 1500,50)');
            }
            $n = round((float)$s, 2);
            if ($n < 0) {
                throw new InvalidArgumentException('No puede ser negativo');
            }
            if ($n > 9999999999.99) {
                throw new InvalidArgumentException('Monto demasiado grande');
            }
            return number_format($n, 2, '.', '');

        case 'date':
            if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                throw new InvalidArgumentException('Fecha inválida');
            }
            return $v;

        case 'datetime':
            if (!is_string($v) || !preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?$/', $v, $m)) {
                throw new InvalidArgumentException('Fecha y hora inválidas');
            }
            validar_valor($m[1], ['type' => 'date']);
            $seg = $m[4] ?? '00';
            if ((int)$m[2] > 23 || (int)$m[3] > 59 || (int)$seg > 59) {
                throw new InvalidArgumentException('Hora inválida');
            }
            return "{$m[1]} {$m[2]}:{$m[3]}:$seg";

        case 'enum':
            if (!is_string($v) || !in_array($v, $r['values'], true)) {
                throw new InvalidArgumentException('Valor no permitido');
            }
            return $v;

        case 'bool':
            if (is_bool($v)) {
                return $v ? 1 : 0;
            }
            if ($v === 0 || $v === 1 || $v === '0' || $v === '1') {
                return (int)$v;
            }
            throw new InvalidArgumentException('Tiene que ser sí o no');

        case 'currency':
            if (!is_string($v) || !preg_match('/^[A-Za-z]{3}$/', $v)) {
                throw new InvalidArgumentException('Moneda inválida (3 letras, ej. ARS)');
            }
            return strtoupper($v);

        case 'fk':
            $id = validar_valor($v, ['type' => 'int', 'min' => 1]);
            if (!crud_exists($r['table'], $id)) {
                throw new InvalidArgumentException('No existe');
            }
            return $id;
    }
    throw new LogicException('Tipo de regla desconocido: ' . $r['type']);
}

function filtros(array $get, array $schema): array
{
    $presentes = array_filter(
        array_intersect_key($get, $schema),
        fn($v) => $v !== '' && $v !== null
    );
    return validate($presentes, $schema);
}
