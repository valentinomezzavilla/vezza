<?php
declare(strict_types=1);

final class MonitorTest extends DbTestCase
{
    private const T0 = '2026-03-10 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->limpiar();
    }

    protected function tearDown(): void
    {
        $this->limpiar();
        parent::tearDown();
    }

    private function limpiar(): void
    {
        Monitor::$sonda = null;
        Monitor::$resolver = null;
        Monitor::$permitirPrivados = false;
        Mail::$transporte = null;
        env_set('MONITOR_ALERTA_EMAIL', null);
        env_set('MAIL_WEBHOOK_URL', null);
        env_set('MAIL_WEBHOOK_SECRET', null);
        env_set('PORTAL_URL', null);
    }

    private function mas(string $desde, int $segundos): string
    {
        return date('Y-m-d H:i:s', strtotime($desde) + $segundos);
    }

    private function servicio(array $extra = []): array
    {
        return monitor_create($extra + ['nombre' => 'Web', 'tipo' => 'http', 'destino' => 'https://ejemplo.com']);
    }

    /** Hace que toda sonda devuelva el resultado indicado, sin tocar la red. */
    private function sonda(bool $ok, ?int $ms = 120, string $detalle = 'listo', ?string $sslVence = null): void
    {
        Monitor::$sonda = fn(array $s): array => monitor_resultado($ok, $ms, $detalle, $sslVence);
    }

    private function fresco(array $s): array
    {
        return crud_find('monitor_servicios', (int)$s['id']);
    }

    // ---------- Esquema ----------

    public function test_existen_las_tablas_del_monitor(): void
    {
        $tablas = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach (['monitor_servicios', 'monitor_chequeos', 'monitor_eventos'] as $tabla) {
            $this->assertContains($tabla, $tablas, "Falta la tabla $tabla");
        }
    }

    // ---------- Validación ----------

    public function test_crear_http_usa_los_valores_por_defecto(): void
    {
        $s = $this->servicio();
        $this->assertSame('http', $s['tipo']);
        $this->assertNull($s['puerto']);
        $this->assertSame('desconocido', $s['estado']);
        $this->assertSame(5, $s['intervalo_min']);
        $this->assertSame(10, $s['timeout_seg']);
        $this->assertSame(2, $s['fallos_para_caer']);
        $this->assertSame(14, $s['ssl_dias_aviso']);
        $this->assertSame(1, $s['activo']);
    }

    public function test_crear_pide_nombre_tipo_y_destino(): void
    {
        $campos = $this->errores422(fn() => monitor_create([]));
        foreach (['nombre', 'tipo', 'destino'] as $campo) {
            $this->assertArrayHasKey($campo, $campos);
        }
        $this->assertArrayHasKey('tipo', $this->errores422(fn() => monitor_create(['nombre' => 'X', 'tipo' => 'ping', 'destino' => 'a.com'])));
    }

    public function test_http_exige_una_url_completa_sin_credenciales(): void
    {
        foreach (['ejemplo.com', 'ftp://ejemplo.com', 'https://', 'https://user:clave@ejemplo.com'] as $malo) {
            $campos = $this->errores422(fn() => $this->servicio(['destino' => $malo]));
            $this->assertArrayHasKey('destino', $campos, "Debería rechazar $malo");
        }
        $ok = $this->servicio(['destino' => 'https://ejemplo.com:8443/salud?x=1']);
        $this->assertSame('https://ejemplo.com:8443/salud?x=1', $ok['destino']);
    }

    public function test_http_descarta_el_puerto_porque_va_en_la_url(): void
    {
        $this->assertNull($this->servicio(['puerto' => 8080])['puerto']);
    }

    public function test_tcp_exige_host_sin_esquema_y_puerto(): void
    {
        $base = ['nombre' => 'DB', 'tipo' => 'tcp'];
        $this->assertArrayHasKey('destino', $this->errores422(fn() => monitor_create($base + ['destino' => 'https://db.ejemplo.com', 'puerto' => 5432])));
        $this->assertArrayHasKey('destino', $this->errores422(fn() => monitor_create($base + ['destino' => 'db.ejemplo.com/x', 'puerto' => 5432])));
        $this->assertArrayHasKey('puerto', $this->errores422(fn() => monitor_create($base + ['destino' => 'db.ejemplo.com'])));
        $this->assertArrayHasKey('puerto', $this->errores422(fn() => monitor_create($base + ['destino' => 'db.ejemplo.com', 'puerto' => 70000])));
        $s = monitor_create($base + ['destino' => '203.0.113.7', 'puerto' => '5432', 'codigo_esperado' => 200]);
        $this->assertSame(5432, $s['puerto']);
        $this->assertNull($s['codigo_esperado']);
    }

    public function test_ssl_usa_443_si_no_se_indica_puerto(): void
    {
        $s = monitor_create(['nombre' => 'Cert', 'tipo' => 'ssl', 'destino' => 'ejemplo.com']);
        $this->assertSame(443, $s['puerto']);
    }

    public function test_rangos_numericos(): void
    {
        $malos = ['intervalo_min' => 0, 'timeout_seg' => 31, 'fallos_para_caer' => 0, 'ssl_dias_aviso' => 0, 'codigo_esperado' => 99];
        $campos = $this->errores422(fn() => $this->servicio($malos));
        foreach (array_keys($malos) as $campo) {
            $this->assertArrayHasKey($campo, $campos);
        }
        $this->assertArrayHasKey('intervalo_min', $this->errores422(fn() => $this->servicio(['intervalo_min' => 1441])));
    }

    public function test_host_valido(): void
    {
        foreach (['ejemplo.com', 'sub.ejemplo.com', 'localhost', '203.0.113.7', '2001:db8::1', 'a-b.ejemplo.com'] as $ok) {
            $this->assertTrue(monitor_host_valido($ok), $ok);
        }
        foreach (['', 'ejemplo.com/ruta', 'https://ejemplo.com', 'ejemplo.com:80', '-malo.com', 'con espacio.com', 'a..com', str_repeat('a', 64) . '.com'] as $malo) {
            $this->assertFalse(monitor_host_valido($malo), $malo);
        }
    }

    public function test_ip_publica_rechaza_redes_privadas_y_reservadas(): void
    {
        foreach (['8.8.8.8', '203.0.114.7', '2001:4860:4860::8888'] as $ok) {
            $this->assertTrue(monitor_ip_publica($ok), $ok);
        }
        foreach (['127.0.0.1', '10.0.0.5', '172.16.0.1', '192.168.1.10', '169.254.169.254', '0.0.0.0', '::1', 'fe80::1', 'fd00::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', 'no-es-ip'] as $malo) {
            $this->assertFalse(monitor_ip_publica($malo), $malo);
        }
    }

    // ---------- CRUD ----------

    public function test_actualizar_parcial_conserva_el_estado_si_el_destino_no_cambia(): void
    {
        $s = $this->servicio();
        db()->prepare("UPDATE monitor_servicios SET estado = 'caido', fallos_seguidos = 3 WHERE id = ?")->execute([$s['id']]);
        $nuevo = monitor_update($s['id'], ['nombre' => 'Web principal', 'intervalo_min' => 10]);
        $this->assertSame('Web principal', $nuevo['nombre']);
        $this->assertSame(10, $nuevo['intervalo_min']);
        $this->assertSame('caido', $nuevo['estado']);
        $this->assertSame(3, $nuevo['fallos_seguidos']);
    }

    public function test_cambiar_el_destino_reinicia_el_estado(): void
    {
        $s = $this->servicio();
        db()->prepare("UPDATE monitor_servicios SET estado = 'caido', fallos_seguidos = 3, ultimo_chequeo = ?, ultimo_detalle = 'x', ssl_vence = '2027-01-01' WHERE id = ?")->execute([self::T0, $s['id']]);
        $nuevo = monitor_update($s['id'], ['destino' => 'https://otro.com']);
        $this->assertSame('desconocido', $nuevo['estado']);
        $this->assertSame(0, $nuevo['fallos_seguidos']);
        $this->assertNull($nuevo['ultimo_chequeo']);
        $this->assertNull($nuevo['ultimo_detalle']);
        $this->assertNull($nuevo['ssl_vence']);
    }

    public function test_actualizar_valida_contra_lo_que_ya_estaba_guardado(): void
    {
        $s = $this->servicio();
        $this->assertArrayHasKey('puerto', $this->errores422(fn() => monitor_update($s['id'], ['tipo' => 'tcp', 'destino' => 'db.ejemplo.com'])));
        $this->assertArrayHasKey('destino', $this->errores422(fn() => monitor_update($s['id'], ['destino' => 'sin-esquema.com'])));
    }

    public function test_get_inexistente_da_404(): void
    {
        $this->assertHttp(404, fn() => monitor_get(999999));
    }

    public function test_borrar_elimina_su_historial_y_sus_eventos(): void
    {
        $s = $this->servicio(['fallos_para_caer' => 1]);
        $this->sonda(false, null, 'sin respuesta');
        monitor_chequear($this->fresco($s), self::T0);
        $this->assertSame(1, (int)q_val('SELECT COUNT(*) FROM monitor_chequeos WHERE servicio_id = ?', [$s['id']]));
        $this->assertSame(1, (int)q_val('SELECT COUNT(*) FROM monitor_eventos WHERE servicio_id = ?', [$s['id']]));
        monitor_delete($s['id']);
        $this->assertSame(0, (int)q_val('SELECT COUNT(*) FROM monitor_chequeos WHERE servicio_id = ?', [$s['id']]));
        $this->assertSame(0, (int)q_val('SELECT COUNT(*) FROM monitor_eventos WHERE servicio_id = ?', [$s['id']]));
    }

    // ---------- Máquina de estados ----------

    public function test_el_primer_chequeo_exitoso_marca_ok_sin_generar_evento(): void
    {
        $s = $this->servicio();
        $this->sonda(true, 85, 'HTTP 200', null);
        $this->assertNull(monitor_chequear($this->fresco($s), self::T0));
        $f = $this->fresco($s);
        $this->assertSame('ok', $f['estado']);
        $this->assertSame(85, $f['ultima_latencia_ms']);
        $this->assertSame('HTTP 200', $f['ultimo_detalle']);
        $this->assertSame(self::T0, $f['ultimo_chequeo']);
        $this->assertSame(self::T0, $f['ultimo_cambio']);
        $this->assertSame(0, (int)q_val('SELECT COUNT(*) FROM monitor_eventos'));
    }

    public function test_cae_recien_al_alcanzar_el_umbral_de_fallos(): void
    {
        $s = $this->servicio(['fallos_para_caer' => 3]);
        $this->sonda(true);
        monitor_chequear($this->fresco($s), self::T0);
        $this->sonda(false, null, 'Timeout');

        $this->assertNull(monitor_chequear($this->fresco($s), $this->mas(self::T0, 60)));
        $this->assertSame('ok', $this->fresco($s)['estado']);
        $this->assertNull(monitor_chequear($this->fresco($s), $this->mas(self::T0, 120)));
        $this->assertSame('ok', $this->fresco($s)['estado']);

        $evento = monitor_chequear($this->fresco($s), $this->mas(self::T0, 180));
        $this->assertNotNull($evento);
        $this->assertSame('caida', $evento['tipo']);
        $this->assertSame('Timeout', $evento['detalle']);
        $f = $this->fresco($s);
        $this->assertSame('caido', $f['estado']);
        $this->assertSame(3, $f['fallos_seguidos']);
        $this->assertSame($this->mas(self::T0, 180), $f['ultimo_cambio']);
    }

    public function test_un_exito_en_el_medio_reinicia_la_cuenta_de_fallos(): void
    {
        $s = $this->servicio(['fallos_para_caer' => 2]);
        $this->sonda(false);
        monitor_chequear($this->fresco($s), self::T0);
        $this->sonda(true);
        monitor_chequear($this->fresco($s), $this->mas(self::T0, 60));
        $this->sonda(false);
        $this->assertNull(monitor_chequear($this->fresco($s), $this->mas(self::T0, 120)));
        $this->assertSame('ok', $this->fresco($s)['estado']);
    }

    public function test_seguir_caido_no_repite_el_evento(): void
    {
        $s = $this->servicio(['fallos_para_caer' => 1]);
        $this->sonda(false, null, 'Caído');
        $this->assertNotNull(monitor_chequear($this->fresco($s), self::T0));
        $this->assertNull(monitor_chequear($this->fresco($s), $this->mas(self::T0, 60)));
        $this->assertNull(monitor_chequear($this->fresco($s), $this->mas(self::T0, 120)));
        $this->assertSame(1, (int)q_val("SELECT COUNT(*) FROM monitor_eventos WHERE tipo = 'caida'"));
        // El momento de la caída no se pisa mientras sigue caído.
        $this->assertSame(self::T0, $this->fresco($s)['ultimo_cambio']);
    }

    public function test_la_recuperacion_registra_cuanto_estuvo_caido(): void
    {
        $s = $this->servicio(['fallos_para_caer' => 1]);
        $this->sonda(false, null, 'Caído');
        monitor_chequear($this->fresco($s), self::T0);
        $this->sonda(true, 90, 'HTTP 200');
        $evento = monitor_chequear($this->fresco($s), $this->mas(self::T0, 750));
        $this->assertSame('recuperacion', $evento['tipo']);
        $this->assertSame(750, $evento['duracion_seg']);
        $f = $this->fresco($s);
        $this->assertSame('ok', $f['estado']);
        $this->assertSame(0, $f['fallos_seguidos']);
        $this->assertSame($this->mas(self::T0, 750), $f['ultimo_cambio']);
    }

    public function test_un_servicio_nuevo_que_falla_tambien_cae(): void
    {
        $s = $this->servicio(['fallos_para_caer' => 2]);
        $this->sonda(false, null, 'Conexión rechazada');
        $this->assertNull(monitor_chequear($this->fresco($s), self::T0));
        $this->assertSame('desconocido', $this->fresco($s)['estado']);
        $evento = monitor_chequear($this->fresco($s), $this->mas(self::T0, 60));
        $this->assertSame('caida', $evento['tipo']);
        $this->assertSame('caido', $this->fresco($s)['estado']);
    }

    public function test_el_contador_de_fallos_tiene_tope(): void
    {
        $s = $this->servicio(['fallos_para_caer' => 1]);
        db()->prepare("UPDATE monitor_servicios SET estado = 'caido', fallos_seguidos = 1000, ultimo_cambio = ? WHERE id = ?")->execute([self::T0, $s['id']]);
        $this->sonda(false);
        monitor_chequear($this->fresco($s), $this->mas(self::T0, 60));
        $this->assertSame(1000, $this->fresco($s)['fallos_seguidos']);
    }

    public function test_cada_chequeo_queda_en_el_historial_y_guarda_el_vencimiento_ssl(): void
    {
        $s = monitor_create(['nombre' => 'Cert', 'tipo' => 'ssl', 'destino' => 'ejemplo.com']);
        $this->sonda(true, 200, 'Válido', '2026-09-01');
        monitor_chequear($this->fresco($s), self::T0);
        $fila = q_one('SELECT * FROM monitor_chequeos WHERE servicio_id = ?', [$s['id']]);
        $this->assertSame(1, $fila['ok']);
        $this->assertSame(200, $fila['latencia_ms']);
        $this->assertSame(self::T0, $fila['hecho_en']);
        $this->assertSame('2026-09-01', $this->fresco($s)['ssl_vence']);
    }

    // ---------- Planificación ----------

    public function test_correr_vencidos_respeta_el_intervalo_con_holgura(): void
    {
        $nuevo = $this->servicio(['nombre' => 'Nuevo']);
        $reciente = $this->servicio(['nombre' => 'Reciente', 'intervalo_min' => 5]);
        $vencido = $this->servicio(['nombre' => 'Vencido', 'intervalo_min' => 5]);
        $justo = $this->servicio(['nombre' => 'Justo', 'intervalo_min' => 5]);
        $set = db()->prepare('UPDATE monitor_servicios SET ultimo_chequeo = ? WHERE id = ?');
        $set->execute([$this->mas(self::T0, -120), $reciente['id']]);
        $set->execute([$this->mas(self::T0, -301), $vencido['id']]);
        // El cron pasa cada minuto: un chequeo de hace 4:45 ya cuenta como vencido (holgura de 30 s).
        $set->execute([$this->mas(self::T0, -285), $justo['id']]);

        $vistos = [];
        Monitor::$sonda = function (array $s) use (&$vistos): array {
            $vistos[] = $s['nombre'];
            return monitor_resultado(true, 10, 'ok');
        };
        $r = monitor_correr_vencidos(self::T0);
        sort($vistos);
        $this->assertSame(['Justo', 'Nuevo', 'Vencido'], $vistos);
        $this->assertSame(3, $r['chequeados']);
        $this->assertFalse($r['omitido']);
    }

    public function test_correr_vencidos_ignora_los_pausados(): void
    {
        $this->servicio(['activo' => false]);
        $this->sonda(true);
        $this->assertSame(0, monitor_correr_vencidos(self::T0)['chequeados']);
    }

    public function test_chequear_ahora_ignora_el_intervalo_y_puede_filtrar_por_servicio(): void
    {
        $a = $this->servicio(['nombre' => 'A']);
        $b = $this->servicio(['nombre' => 'B']);
        db()->prepare('UPDATE monitor_servicios SET ultimo_chequeo = ?')->execute([self::T0]);
        $vistos = [];
        Monitor::$sonda = function (array $s) use (&$vistos): array {
            $vistos[] = $s['nombre'];
            return monitor_resultado(true, 10, 'ok');
        };
        $this->assertSame(2, monitor_chequear_ahora(null, self::T0)['chequeados']);
        $vistos = [];
        $this->assertSame(1, monitor_chequear_ahora((int)$b['id'], self::T0)['chequeados']);
        $this->assertSame(['B'], $vistos);
        $this->assertHttp(404, fn() => monitor_chequear_ahora(999999, self::T0));
        unset($a);
    }

    public function test_chequear_ahora_cuenta_los_caidos(): void
    {
        $this->servicio(['fallos_para_caer' => 1]);
        $this->sonda(false, null, 'Caído');
        $r = monitor_chequear_ahora(null, self::T0);
        $this->assertSame(1, $r['chequeados']);
        $this->assertSame(1, $r['caidos']);
    }

    public function test_si_una_sonda_explota_el_chequeo_cuenta_como_fallo_y_sigue(): void
    {
        $a = $this->servicio(['nombre' => 'A', 'fallos_para_caer' => 1]);
        $b = $this->servicio(['nombre' => 'B']);
        Monitor::$sonda = function (array $s): array {
            if ($s['nombre'] === 'A') {
                throw new RuntimeException('boom');
            }
            return monitor_resultado(true, 5, 'ok');
        };
        $r = monitor_chequear_ahora(null, self::T0);
        $this->assertSame(2, $r['chequeados']);
        $this->assertSame('caido', $this->fresco($a)['estado']);
        $this->assertSame('ok', $this->fresco($b)['estado']);
        $this->assertStringContainsString('Error al chequear', $this->fresco($a)['ultimo_detalle']);
    }

    // ---------- Notificaciones ----------

    public function test_la_caida_manda_un_mail_con_los_datos_del_servicio(): void
    {
        env_set('MONITOR_ALERTA_EMAIL', 'yo@vezzadev.com');
        env_set('MAIL_WEBHOOK_URL', 'https://n8n.test/webhook/mail');
        env_set('PORTAL_URL', 'https://demo.test');
        $enviados = [];
        Mail::$transporte = function (string $url, string $secreto, array $payload) use (&$enviados): bool {
            $enviados[] = $payload;
            return true;
        };
        $s = $this->servicio(['nombre' => 'Sitio', 'grupo' => 'VPS', 'fallos_para_caer' => 1]);
        $this->sonda(false, null, 'HTTP 502');
        $evento = monitor_chequear($this->fresco($s), self::T0);

        $this->assertCount(1, $enviados);
        $this->assertSame('monitor_alerta', $enviados[0]['plantilla']);
        $this->assertSame('yo@vezzadev.com', $enviados[0]['destino']);
        $d = $enviados[0]['datos'];
        $this->assertSame('caida', $d['evento']);
        $this->assertSame('[CAÍDO] Sitio', $d['asunto']);
        $this->assertSame('Sitio', $d['servicio']);
        $this->assertSame('VPS', $d['grupo']);
        $this->assertSame('https://ejemplo.com', $d['destino']);
        $this->assertSame('HTTP 502', $d['detalle']);
        $this->assertSame(self::T0, $d['cuando']);
        $this->assertSame('https://demo.test/admin/monitor', $d['panel']);
        $this->assertSame(1, (int)q_val('SELECT notificado FROM monitor_eventos WHERE id = ?', [$evento['id']]));
    }

    public function test_la_recuperacion_avisa_con_la_duracion(): void
    {
        env_set('MONITOR_ALERTA_EMAIL', 'yo@vezzadev.com');
        env_set('MAIL_WEBHOOK_URL', 'https://n8n.test/webhook/mail');
        $enviados = [];
        Mail::$transporte = function (string $url, string $secreto, array $payload) use (&$enviados): bool {
            $enviados[] = $payload['datos'];
            return true;
        };
        $s = $this->servicio(['nombre' => 'Sitio', 'fallos_para_caer' => 1]);
        $this->sonda(false);
        monitor_chequear($this->fresco($s), self::T0);
        $this->sonda(true);
        monitor_chequear($this->fresco($s), $this->mas(self::T0, 3720));
        $this->assertCount(2, $enviados);
        $this->assertSame('recuperacion', $enviados[1]['evento']);
        $this->assertSame('[RECUPERADO] Sitio', $enviados[1]['asunto']);
        $this->assertSame('1 h 2 min', $enviados[1]['duracion']);
    }

    public function test_sin_mail_de_alerta_configurado_no_se_manda_nada(): void
    {
        env_set('MAIL_WEBHOOK_URL', 'https://n8n.test/webhook/mail');
        Mail::$transporte = fn() => throw new LogicException('No debería enviar');
        $s = $this->servicio(['fallos_para_caer' => 1]);
        $this->sonda(false);
        $evento = monitor_chequear($this->fresco($s), self::T0);
        $this->assertNotNull($evento);
        $this->assertSame(0, (int)q_val('SELECT notificado FROM monitor_eventos WHERE id = ?', [$evento['id']]));
    }

    public function test_si_el_mail_falla_el_evento_queda_sin_notificar_y_el_chequeo_sigue(): void
    {
        env_set('MONITOR_ALERTA_EMAIL', 'yo@vezzadev.com');
        env_set('MAIL_WEBHOOK_URL', 'https://n8n.test/webhook/mail');
        Mail::$transporte = fn() => false;
        $s = $this->servicio(['fallos_para_caer' => 1]);
        $this->sonda(false);
        $evento = monitor_chequear($this->fresco($s), self::T0);
        $this->assertSame('caido', $this->fresco($s)['estado']);
        $this->assertSame(0, (int)q_val('SELECT notificado FROM monitor_eventos WHERE id = ?', [$evento['id']]));
    }

    // ---------- Sondas ----------

    public function test_status_http_esperado(): void
    {
        $this->assertTrue(monitor_status_ok(200, null));
        $this->assertTrue(monitor_status_ok(301, null));
        $this->assertTrue(monitor_status_ok(399, null));
        $this->assertFalse(monitor_status_ok(404, null));
        $this->assertFalse(monitor_status_ok(500, null));
        $this->assertFalse(monitor_status_ok(199, null));
        $this->assertTrue(monitor_status_ok(401, 401));
        $this->assertFalse(monitor_status_ok(200, 401));
    }

    public function test_evaluar_certificado(): void
    {
        $ahora = strtotime('2026-03-10 12:00:00');
        $r = monitor_evaluar_cert($ahora + 40 * 86400, $ahora, 14, true);
        $this->assertTrue($r['ok']);
        $this->assertSame('2026-04-19', $r['ssl_vence']);
        $this->assertStringContainsString('40 días', $r['detalle']);

        $cerca = monitor_evaluar_cert($ahora + 5 * 86400, $ahora, 14, true);
        $this->assertFalse($cerca['ok']);
        $this->assertStringContainsString('Vence en 5 días', $cerca['detalle']);

        $vencido = monitor_evaluar_cert($ahora - 3 * 86400, $ahora, 14, true);
        $this->assertFalse($vencido['ok']);
        $this->assertStringContainsString('Venció hace 3 días', $vencido['detalle']);

        $noConfiable = monitor_evaluar_cert($ahora + 90 * 86400, $ahora, 14, false);
        $this->assertFalse($noConfiable['ok']);
        $this->assertStringContainsString('no es confiable', $noConfiable['detalle']);

        $this->assertStringContainsString('1 día', monitor_evaluar_cert($ahora + 86400 + 60, $ahora, 14, true)['detalle']);
    }

    public function test_un_certificado_vencido_y_no_confiable_dice_que_esta_vencido(): void
    {
        $ahora = strtotime('2026-03-10 12:00:00');
        $r = monitor_evaluar_cert($ahora - 3 * 86400, $ahora, 14, false);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('no es confiable: venció hace 3 días', $r['detalle']);
        $otro = monitor_evaluar_cert($ahora + 90 * 86400, $ahora, 14, false);
        $this->assertStringContainsString('no corresponde al dominio', $otro['detalle']);
    }

    public function test_traduce_los_motivos_de_error_de_red(): void
    {
        $this->assertSame('tiempo de espera agotado', monitor_traducir_error('Connection timed out'));
        $this->assertSame('conexión rechazada', monitor_traducir_error('Connection refused'));
        $this->assertSame('no se pudo resolver el dominio', monitor_traducir_error('php_network_getaddresses: getaddrinfo for x failed'));
        $this->assertSame('sin detalle', monitor_traducir_error(''));
        $this->assertSame(
            'certificado inválido',
            monitor_traducir_error('fopen(): SSL operation failed with code 1. OpenSSL Error messages: error:0A000086:SSL routines::certificate verify failed | Failed to enable crypto')
        );
    }

    public function test_con_avisos_recoge_el_motivo_que_php_deja_en_un_warning(): void
    {
        [$resultado, $avisos] = monitor_con_avisos(fn() => file_get_contents('/ruta/que/no/existe/vezza'));
        $this->assertFalse($resultado);
        $this->assertStringContainsString('file_get_contents', $avisos);
    }

    public function test_texto_de_duracion(): void
    {
        $this->assertSame('menos de 1 min', monitor_duracion_texto(20));
        $this->assertSame('5 min', monitor_duracion_texto(300));
        $this->assertSame('1 h 2 min', monitor_duracion_texto(3720));
        $this->assertSame('2 h', monitor_duracion_texto(7200));
        $this->assertSame('1 d 3 h', monitor_duracion_texto(86400 + 3 * 3600 + 120));
    }

    public function test_la_sonda_no_sale_a_la_red_si_el_destino_es_privado(): void
    {
        Monitor::$resolver = fn(string $host): array => ['10.0.0.5'];
        foreach ([['tcp', 'interno.local', 5432], ['ssl', 'interno.local', 443], ['http', 'http://interno.local/', null]] as [$tipo, $destino, $puerto]) {
            $r = monitor_sondear(['tipo' => $tipo, 'destino' => $destino, 'puerto' => $puerto, 'timeout_seg' => 2, 'codigo_esperado' => null, 'ssl_dias_aviso' => 14]);
            $this->assertFalse($r['ok'], $tipo);
            $this->assertStringContainsString('no permitido', $r['detalle'], $tipo);
        }
    }

    public function test_la_sonda_rechaza_si_alguna_ip_resuelta_es_privada(): void
    {
        Monitor::$resolver = fn(string $host): array => ['8.8.8.8', '192.168.0.9'];
        $r = monitor_sondear(['tipo' => 'tcp', 'destino' => 'mixto.test', 'puerto' => 80, 'timeout_seg' => 2]);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('no permitido', $r['detalle']);
    }

    public function test_la_sonda_avisa_si_el_dominio_no_resuelve(): void
    {
        Monitor::$resolver = fn(string $host): array => [];
        $r = monitor_sondear(['tipo' => 'tcp', 'destino' => 'no-existe.test', 'puerto' => 80, 'timeout_seg' => 2]);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('resolver', $r['detalle']);
    }

    public function test_sonda_tcp_contra_un_puerto_abierto_y_uno_cerrado(): void
    {
        Monitor::$permitirPrivados = true;
        $servidor = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($servidor, "$errno $errstr");
        $puerto = (int)substr((string)strrchr((string)stream_socket_get_name($servidor, false), ':'), 1);
        try {
            $abierto = monitor_sondear(['tipo' => 'tcp', 'destino' => '127.0.0.1', 'puerto' => $puerto, 'timeout_seg' => 2]);
            $this->assertTrue($abierto['ok'], $abierto['detalle']);
            $this->assertIsInt($abierto['latencia_ms']);
        } finally {
            fclose($servidor);
        }
        $cerrado = monitor_sondear(['tipo' => 'tcp', 'destino' => '127.0.0.1', 'puerto' => $puerto, 'timeout_seg' => 2]);
        $this->assertFalse($cerrado['ok']);
        $this->assertStringContainsString('no responde', $cerrado['detalle']);
    }

    public function test_el_detalle_se_recorta_a_255_caracteres(): void
    {
        $r = monitor_resultado(false, null, str_repeat('á', 400));
        $this->assertSame(255, mb_strlen($r['detalle']));
    }

    // ---------- Listados ----------

    public function test_list_incluye_uptime_24h_ultimos_chequeos_y_antiguedad(): void
    {
        $s = $this->servicio();
        $ahora = date('Y-m-d H:i:s');
        $ins = db()->prepare('INSERT INTO monitor_chequeos (servicio_id, hecho_en, ok) VALUES (?, ?, ?)');
        $ins->execute([$s['id'], date('Y-m-d H:i:s', strtotime($ahora) - 30 * 3600), 0]); // fuera de las 24 h
        $ins->execute([$s['id'], date('Y-m-d H:i:s', strtotime($ahora) - 3 * 3600), 1]);
        $ins->execute([$s['id'], date('Y-m-d H:i:s', strtotime($ahora) - 2 * 3600), 1]);
        $ins->execute([$s['id'], date('Y-m-d H:i:s', strtotime($ahora) - 3600), 0]);
        $ins->execute([$s['id'], date('Y-m-d H:i:s', strtotime($ahora) - 1800), 1]);
        db()->prepare('UPDATE monitor_servicios SET ultimo_chequeo = ? WHERE id = ?')->execute([date('Y-m-d H:i:s', strtotime($ahora) - 90), $s['id']]);

        $fila = monitor_list([])[0];
        $this->assertSame(75.0, $fila['uptime_24h']);
        $this->assertSame(4, $fila['chequeos_24h']);
        $this->assertSame([0, 1, 1, 0, 1], $fila['recientes']);
        $this->assertEqualsWithDelta(90, $fila['hace_seg'], 3);
    }

    public function test_list_sin_datos_no_inventa_uptime(): void
    {
        $this->servicio();
        $fila = monitor_list([])[0];
        $this->assertNull($fila['uptime_24h']);
        $this->assertSame(0, $fila['chequeos_24h']);
        $this->assertSame([], $fila['recientes']);
        $this->assertNull($fila['hace_seg']);
    }

    public function test_list_agrupa_y_ordena_por_grupo_y_nombre(): void
    {
        $this->servicio(['nombre' => 'Z sin grupo']);
        $this->servicio(['nombre' => 'B', 'grupo' => 'VPS']);
        $this->servicio(['nombre' => 'A', 'grupo' => 'VPS']);
        $this->servicio(['nombre' => 'C', 'grupo' => 'Apps']);
        $this->assertSame(['C', 'A', 'B', 'Z sin grupo'], array_column(monitor_list([]), 'nombre'));
    }

    public function test_eventos_list_filtra_por_servicio_y_viene_del_mas_nuevo(): void
    {
        $a = $this->servicio(['nombre' => 'A', 'fallos_para_caer' => 1]);
        $b = $this->servicio(['nombre' => 'B', 'fallos_para_caer' => 1]);
        $this->sonda(false, null, 'Caído');
        monitor_chequear($this->fresco($a), self::T0);
        monitor_chequear($this->fresco($b), $this->mas(self::T0, 60));
        $this->sonda(true);
        monitor_chequear($this->fresco($a), $this->mas(self::T0, 120));

        $todos = monitor_eventos_list([]);
        $this->assertSame(['recuperacion', 'caida', 'caida'], array_column($todos, 'tipo'));
        $this->assertSame(['A', 'B', 'A'], array_column($todos, 'servicio_nombre'));
        $this->assertCount(2, monitor_eventos_list(['servicio_id' => (string)$a['id']]));
        $this->assertCount(1, monitor_eventos_list(['limite' => '1']));
        $this->assertArrayHasKey('limite', $this->errores422(fn() => monitor_eventos_list(['limite' => '500'])));
    }

    // ---------- Retención ----------

    public function test_la_limpieza_borra_solo_los_chequeos_viejos(): void
    {
        $s = $this->servicio();
        $ins = db()->prepare('INSERT INTO monitor_chequeos (servicio_id, hecho_en, ok) VALUES (?, ?, 1)');
        $ins->execute([$s['id'], $this->mas(self::T0, -31 * 86400)]);
        $ins->execute([$s['id'], $this->mas(self::T0, -29 * 86400)]);
        monitor_limpiar(self::T0);
        $this->assertSame(1, (int)q_val('SELECT COUNT(*) FROM monitor_chequeos WHERE servicio_id = ?', [$s['id']]));
    }
}
