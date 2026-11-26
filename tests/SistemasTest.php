<?php
declare(strict_types=1);

final class SistemasTest extends DbTestCase
{
    private int $cliente;
    private array $plan;

    protected function setUp(): void
    {
        parent::setUp();
        clock_set('2026-03-10');
        $this->cliente = clientes_create(['nombre' => 'Pescadería Valle Viejo', 'email' => 'pesca@sol.com'])['id'];
        $this->plan = planes_create(['nombre' => 'Mantenimiento', 'descripcion' => 'Soporte y hosting', 'monto' => '25000', 'moneda' => 'ARS', 'frecuencia' => 'mensual']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        parent::tearDown();
    }

    private function sistema(array $extra = []): array
    {
        return sistemas_create($extra + ['cliente_id' => $this->cliente, 'nombre' => 'Sistema de gestión']);
    }

    /** Llama al endpoint como lo hace un sistema: GET con Authorization: Bearer. */
    private function consultar(?string $token, string $metodo = 'GET'): array
    {
        $_SERVER['REQUEST_METHOD'] = $metodo;
        if ($token === null) {
            unset($_SERVER['HTTP_AUTHORIZATION']);
        } else {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        ob_start();
        sistema_api_run();
        $salida = (string)ob_get_clean();
        return [Http::$ultimoStatus, json_decode($salida, true)];
    }

    // ---- alta y token ----

    public function test_crear_devuelve_el_token_una_sola_vez_y_guarda_solo_el_hash(): void
    {
        $s = $this->sistema(['repo' => 'https://github.com/valentinomezzavilla/pesca_valle_viejo.git', 'url' => 'https://pesca.test']);
        $this->assertMatchesRegularExpression('/^vz_[0-9a-f]{48}$/', $s['token']);
        $this->assertTrue($s['conectado']);
        $this->assertSame('valentinomezzavilla/pesca_valle_viejo', $s['repo']);
        $this->assertArrayNotHasKey('token_hash', $s);

        $fila = q_one('SELECT * FROM sistemas WHERE id = ?', [$s['id']]);
        $this->assertSame(hash('sha256', $s['token']), $fila['token_hash']);
        $this->assertStringNotContainsString($s['token'], json_encode($fila));

        $leido = sistemas_get($s['id']);
        $this->assertArrayNotHasKey('token', $leido);
        $this->assertArrayNotHasKey('token_hash', $leido);
        $this->assertArrayNotHasKey('token', sistemas_list(['cliente_id' => (string)$this->cliente])[0]);
    }

    public function test_el_repo_tiene_que_ser_de_github(): void
    {
        foreach (['cualquier cosa', 'https://gitlab.com/dueno/repo', 'solo-un-nombre'] as $malo) {
            $this->assertArrayHasKey('repo', $this->errores422(fn() => $this->sistema(['repo' => $malo])), $malo);
        }
        $this->assertSame('dueno/repo', $this->sistema(['repo' => 'github.com/dueno/repo/'])['repo']);
        $this->assertNull($this->sistema(['repo' => ''])['repo']);
    }

    public function test_generar_un_token_nuevo_invalida_el_anterior(): void
    {
        $s = $this->sistema();
        $nuevo = sistema_token_generar($s['id'])['token'];
        $this->assertNotSame($s['token'], $nuevo);
        $this->assertNull(sistema_por_token($s['token']));
        $this->assertSame($s['id'], (int)sistema_por_token($nuevo)['id']);
    }

    public function test_editar_no_cambia_el_cliente_ni_el_token(): void
    {
        $otro = clientes_create(['nombre' => 'Otro'])['id'];
        $s = $this->sistema();
        $editado = sistemas_update($s['id'], ['nombre' => 'POS', 'cliente_id' => $otro]);
        $this->assertSame('POS', $editado['nombre']);
        $this->assertSame($this->cliente, (int)$editado['cliente_id']);
        $this->assertNotNull(sistema_por_token($s['token']));
    }

    // ---- endpoint de los sistemas ----

    public function test_sin_token_o_con_uno_inventado_da_401(): void
    {
        $this->sistema();
        $this->assertSame([401, ['error' => 'Token inválido']], $this->consultar(null));
        $this->assertSame(401, $this->consultar('vz_' . str_repeat('0', 48))[0]);
        $this->assertSame(401, $this->consultar("' OR 1=1 --")[0]);
    }

    public function test_solo_acepta_get(): void
    {
        $s = $this->sistema();
        $this->assertSame(405, $this->consultar($s['token'], 'POST')[0]);
    }

    public function test_tambien_lee_el_token_de_redirect_http_authorization(): void
    {
        $s = $this->sistema();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer ' . $s['token'];
        ob_start();
        sistema_api_run();
        ob_end_clean();
        $this->assertSame(200, Http::$ultimoStatus);
    }

    public function test_devuelve_la_cuenta_del_cliente_sin_datos_internos(): void
    {
        $s = $this->sistema();
        $susc = suscripciones_cliente_create(['cliente_id' => $this->cliente, 'plan_id' => $this->plan['id'], 'fecha_proximo_cobro' => '2026-03-15']);
        crud_update('suscripciones_cliente', $susc['id'], ['mp_preapproval_id' => 'pre-secreto', 'mp_init_point' => 'https://mp.test/secreto']);
        crud_insert('cobros', ['cliente_id' => $this->cliente, 'suscripcion_cliente_id' => $susc['id'], 'concepto' => 'Febrero', 'monto' => '25000',
            'moneda' => 'ARS', 'fecha_vencimiento' => '2026-02-15', 'fecha_pago' => '2026-02-14', 'estado' => 'pagado', 'metodo_pago' => 'Transferencia',
            'mp_payment_id' => 'pay-secreto']);
        crud_insert('cobros', ['cliente_id' => $this->cliente, 'concepto' => 'Cambio de diseño', 'monto' => '40000', 'moneda' => 'ARS',
            'fecha_vencimiento' => '2026-03-20']);

        [$status, $cuerpo] = $this->consultar($s['token']);
        $this->assertSame(200, $status);
        $c = $cuerpo['data'];
        $this->assertSame(['nombre' => 'Pescadería Valle Viejo'], $c['cliente']);
        $this->assertSame(['nombre' => 'Sistema de gestión'], $c['sistema']);
        $this->assertSame([[
            'plan' => 'Mantenimiento', 'descripcion' => 'Soporte y hosting', 'monto' => '25000.00', 'moneda' => 'ARS', 'frecuencia' => 'mensual',
            'modalidad' => 'transferencia', 'debito_activo' => false, 'estado' => 'activa', 'fecha_inicio' => '2026-03-10', 'dia_vencimiento' => 15,
            'pago_hasta' => '2026-03-15', 'cobro_estado' => 'por_vencer', 'cobro_dias' => 5,
        ]], $c['suscripciones']);
        $this->assertSame([['fecha' => '2026-02-14', 'concepto' => 'Febrero', 'monto' => '25000.00', 'moneda' => 'ARS', 'metodo' => 'Transferencia']], $c['pagos']);
        $this->assertSame('https://vezzadev.com/clientes', $c['portal_url']);
        $this->assertSame([['concepto' => 'Cambio de diseño', 'monto' => '40000.00', 'moneda' => 'ARS', 'vencimiento' => '2026-03-20']], $c['pendientes']);

        $json = json_encode($cuerpo);
        foreach (['secreto', 'pesca@sol.com', 'cliente_id', 'token'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $json, "La respuesta no debe incluir $prohibido");
        }
    }

    public function test_solo_ve_la_cuenta_de_su_propio_cliente(): void
    {
        $otro = clientes_create(['nombre' => 'Otro cliente'])['id'];
        suscripciones_cliente_create(['cliente_id' => $otro, 'plan_id' => $this->plan['id'], 'fecha_proximo_cobro' => '2026-03-15']);
        crud_insert('cobros', ['cliente_id' => $otro, 'concepto' => 'Ajeno', 'monto' => '1', 'moneda' => 'ARS', 'fecha_vencimiento' => '2026-03-20']);
        $s = $this->sistema();

        $c = $this->consultar($s['token'])[1]['data'];
        $this->assertSame([], $c['suscripciones']);
        $this->assertSame([], $c['pendientes']);
    }

    public function test_una_suscripcion_pausada_o_cancelada_no_figura_como_vencida(): void
    {
        $s = $this->sistema();
        $pausada = suscripciones_cliente_create(['cliente_id' => $this->cliente, 'plan_id' => $this->plan['id'], 'fecha_proximo_cobro' => '2026-01-01']);
        crud_update('suscripciones_cliente', $pausada['id'], ['pausada' => 1]);
        $cancelada = suscripciones_cliente_create(['cliente_id' => $this->cliente, 'plan_id' => $this->plan['id'], 'fecha_proximo_cobro' => '2026-01-01']);
        crud_update('suscripciones_cliente', $cancelada['id'], ['estado' => 'cancelada', 'fecha_baja' => '2026-02-01']);

        $subs = $this->consultar($s['token'])[1]['data']['suscripciones'];
        $this->assertSame(['pausada', 'cancelada'], array_column($subs, 'estado'));
        $this->assertSame([null, null], array_column($subs, 'cobro_estado'));
    }

    public function test_registra_el_ultimo_contacto_sin_escribir_en_cada_consulta(): void
    {
        $s = $this->sistema();
        $this->assertNull(sistemas_get($s['id'])['ultimo_contacto']);
        $this->consultar($s['token']);
        $primero = sistemas_get($s['id'])['ultimo_contacto'];
        $this->assertNotNull($primero);

        db()->prepare('UPDATE sistemas SET ultimo_contacto = NOW() - INTERVAL 2 MINUTE WHERE id = ?')->execute([$s['id']]);
        $haceDos = sistemas_get($s['id'])['ultimo_contacto'];
        $this->consultar($s['token']);
        $this->assertSame($haceDos, sistemas_get($s['id'])['ultimo_contacto'], 'Dentro de los 5 minutos no se vuelve a escribir');

        db()->prepare('UPDATE sistemas SET ultimo_contacto = NOW() - INTERVAL 10 MINUTE WHERE id = ?')->execute([$s['id']]);
        $this->consultar($s['token']);
        $this->assertGreaterThan($haceDos, sistemas_get($s['id'])['ultimo_contacto']);
    }

    public function test_borrar_el_cliente_borra_sus_sistemas(): void
    {
        $s = $this->sistema();
        crud_delete('clientes', $this->cliente);
        $this->assertNull(sistema_por_token($s['token']));
    }

    // ---- copia de los comprobantes ----

    private function pedirComprobantes(string $token, string $desde = ''): array
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        $_GET = $desde === '' ? [] : ['desde' => $desde];
        ob_start();
        sistema_api_run('comprobantes');
        $cuerpo = json_decode((string)ob_get_clean(), true);
        $_GET = [];
        return [Http::$ultimoStatus, $cuerpo];
    }

    private function pagar(int $suscripcionId): array
    {
        return suscripcion_pago_manual($suscripcionId, ['metodo_pago' => 'Transferencia']);
    }

    public function test_el_sistema_recibe_los_comprobantes_de_su_cliente_sin_ids_internos(): void
    {
        $s = $this->sistema();
        $susc = suscripciones_cliente_create(['cliente_id' => $this->cliente, 'plan_id' => $this->plan['id'], 'fecha_proximo_cobro' => '2026-03-15']);
        $this->pagar($susc['id']);
        $otro = clientes_create(['nombre' => 'Otro'])['id'];
        $ajena = suscripciones_cliente_create(['cliente_id' => $otro, 'plan_id' => $this->plan['id'], 'fecha_proximo_cobro' => '2026-03-15']);
        $this->pagar($ajena['id']);

        [$status, $cuerpo] = $this->pedirComprobantes($s['token']);
        $this->assertSame(200, $status);
        $lista = $cuerpo['data']['comprobantes'];
        $this->assertCount(1, $lista);
        $this->assertSame(['numero', 'emitido_en', 'anulado', 'anulado_en', 'motivo_anulacion', 'actualizado_en', 'datos'], array_keys($lista[0]));
        $this->assertSame('Pescadería Valle Viejo', $lista[0]['datos']['cliente']['nombre']);
        $this->assertArrayNotHasKey('cobro_id', $lista[0]['datos']);
        $this->assertFalse($cuerpo['data']['hay_mas']);
        $this->assertNotNull($cuerpo['data']['siguiente']);
    }

    public function test_con_el_cursor_trae_solo_lo_nuevo_y_las_anulaciones(): void
    {
        $s = $this->sistema();
        $susc = suscripciones_cliente_create(['cliente_id' => $this->cliente, 'plan_id' => $this->plan['id'], 'fecha_proximo_cobro' => '2026-03-15']);
        $primero = $this->pagar($susc['id']);
        $cursor = $this->pedirComprobantes($s['token'])[1]['data']['siguiente'];

        $this->assertSame([], $this->pedirComprobantes($s['token'], $cursor)[1]['data']['comprobantes']);
        $this->assertSame($cursor, $this->pedirComprobantes($s['token'], $cursor)[1]['data']['siguiente'], 'Sin novedades, el cursor no cambia');

        // Un comprobante nuevo y la anulación del primero (un segundo después, como pasaría en la realidad).
        db()->exec("UPDATE comprobantes_pago SET actualizado_en = actualizado_en - INTERVAL 5 SECOND, emitido_en = emitido_en - INTERVAL 5 SECOND");
        $cursor = $this->pedirComprobantes($s['token'])[1]['data']['siguiente'];
        $this->pagar($susc['id']);
        cobros_delete($primero['cobro']['id']);

        $nuevos = $this->pedirComprobantes($s['token'], $cursor)[1]['data']['comprobantes'];
        $this->assertCount(2, $nuevos);
        $porNumero = array_column($nuevos, null, 'numero');
        $this->assertTrue($porNumero[$primero['cobro']['comprobante_pago']['numero']]['anulado']);
        $this->assertSame('Se borró el pago', $porNumero[$primero['cobro']['comprobante_pago']['numero']]['motivo_anulacion']);
    }

    public function test_un_cursor_invalido_trae_todo_desde_el_principio(): void
    {
        $s = $this->sistema();
        $susc = suscripciones_cliente_create(['cliente_id' => $this->cliente, 'plan_id' => $this->plan['id'], 'fecha_proximo_cobro' => '2026-03-15']);
        $this->pagar($susc['id']);
        $this->assertCount(1, $this->pedirComprobantes($s['token'], "'; DROP TABLE x")[1]['data']['comprobantes']);
    }

    public function test_pagina_de_a_200(): void
    {
        $s = $this->sistema();
        $cobro = fn() => crud_insert('cobros', ['cliente_id' => $this->cliente, 'monto' => '1', 'moneda' => 'ARS', 'fecha_vencimiento' => '2026-03-01',
            'fecha_pago' => '2026-03-01', 'estado' => 'pagado']);
        for ($i = 0; $i < 201; $i++) {
            comprobante_pago_emitir($cobro());
        }
        $pagina = $this->pedirComprobantes($s['token'])[1]['data'];
        $this->assertCount(200, $pagina['comprobantes']);
        $this->assertTrue($pagina['hay_mas']);
        $resto = $this->pedirComprobantes($s['token'], $pagina['siguiente'])[1]['data'];
        $this->assertCount(1, $resto['comprobantes']);
        $this->assertFalse($resto['hay_mas']);
    }

    public function test_la_ficha_sabe_cuantos_le_faltan_copiar(): void
    {
        $s = $this->sistema();
        $susc = suscripciones_cliente_create(['cliente_id' => $this->cliente, 'plan_id' => $this->plan['id'], 'fecha_proximo_cobro' => '2026-03-15']);
        $this->pagar($susc['id']);
        $this->assertSame(1, sistemas_get($s['id'])['comprobantes_sin_copiar']);
        $cursor = $this->pedirComprobantes($s['token'])[1]['data']['siguiente'];
        $this->assertSame(1, sistemas_get($s['id'])['comprobantes_sin_copiar'], 'Recién cuando vuelve con el cursor se sabe que lo guardó');
        $this->pedirComprobantes($s['token'], $cursor);
        $this->assertSame(0, sistemas_get($s['id'])['comprobantes_sin_copiar']);
    }
}
