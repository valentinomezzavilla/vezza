<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * El workflow de n8n que manda los dos mails de las suscripciones. n8n no decide nada: solo entrega lo que arma la app, así que
 * lo que se prueba acá es que el archivo sea importable, que cubra exactamente las dos plantillas y que lea solo los campos que
 * la app manda (el contrato {plantilla, destino, datos} de mail_enviar).
 */
final class N8nWorkflowTest extends TestCase
{
    private const RUTA = __DIR__ . '/../docs/n8n/vezza-avisos-de-pago.json';

    private function workflow(): array
    {
        $crudo = file_get_contents(self::RUTA);
        $this->assertNotFalse($crudo, 'Falta docs/n8n/vezza-avisos-de-pago.json');
        $w = json_decode($crudo, true);
        $this->assertIsArray($w, 'El JSON no es válido: ' . json_last_error_msg());
        return $w;
    }

    /** @return list<array> */
    private function nodos(string $tipo): array
    {
        return array_values(array_filter($this->workflow()['nodes'], fn(array $n) => $n['type'] === "n8n-nodes-base.$tipo"));
    }

    public function test_el_json_es_valido_y_tiene_nombre_nodos_y_conexiones(): void
    {
        $w = $this->workflow();
        $this->assertSame('VEZZA · Avisos de pago', $w['name']);
        $this->assertNotEmpty($w['nodes']);
        $this->assertNotEmpty($w['connections']);
        $this->assertCount(1, $this->nodos('webhook'));
        $this->assertSame('POST', $this->nodos('webhook')[0]['parameters']['httpMethod']);
    }

    public function test_el_switch_cubre_las_dos_plantillas_y_nada_mas(): void
    {
        $switch = $this->nodos('switch');
        $this->assertCount(1, $switch);
        $plantillas = [];
        foreach ($switch[0]['parameters']['rules']['values'] as $regla) {
            foreach ($regla['conditions']['conditions'] as $c) {
                $this->assertSame('={{ $json.body.plantilla }}', $c['leftValue']);
                $plantillas[] = $c['rightValue'];
            }
        }
        $this->assertSame(['precio_cambiado', 'suscripcion_cancelada'], $plantillas);
    }

    public function test_hay_un_nodo_de_mail_por_plantilla_y_todos_responden(): void
    {
        $w = $this->workflow();
        $mails = $this->nodos('emailSend');
        $this->assertCount(2, $mails);
        $this->assertCount(1, $this->nodos('respondToWebhook'));

        $switch = $this->nodos('switch')[0]['name'];
        $salidas = $w['connections'][$switch]['main'];
        $this->assertCount(2, $salidas, 'El switch tiene que tener una salida por plantilla');
        $destinos = array_map(fn(array $s) => $s[0]['node'], $salidas);
        $this->assertSame(array_column($mails, 'name'), $destinos);

        $respuesta = $this->nodos('respondToWebhook')[0]['name'];
        foreach ($mails as $mail) {
            $this->assertSame($respuesta, $w['connections'][$mail['name']]['main'][0][0]['node'], $mail['name'] . ' tiene que terminar en la respuesta');
            $this->assertSame('info@vezzadev.com', $this->soloMail($mail['parameters']['fromEmail']));
            $this->assertSame('={{ $json.body.destino }}', $mail['parameters']['toEmail']);
        }
    }

    public function test_las_expresiones_solo_leen_campos_que_manda_la_app(): void
    {
        $permitidos = [
            'precio_cambiado' => ['nombre', 'plan', 'monto_anterior', 'monto_nuevo', 'desde'],
            'suscripcion_cancelada' => ['nombre', 'plan', 'motivo', 'acceso_hasta', 'vencida'],
        ];
        $mails = $this->nodos('emailSend');
        foreach (array_keys($permitidos) as $i => $plantilla) {
            $texto = $mails[$i]['parameters']['subject'] . "\n" . $mails[$i]['parameters']['text'];
            preg_match_all('/\$json\.body\.datos\.(\w+)/', $texto, $m);
            $usados = array_values(array_unique($m[1]));
            $this->assertNotEmpty($usados, "El mail de $plantilla no usa ningún dato");
            $this->assertSame([], array_diff($usados, $permitidos[$plantilla]), "El mail de $plantilla lee campos que la app no manda");
        }
    }

    public function test_el_mail_de_baja_distingue_la_baja_del_debito_caido(): void
    {
        $texto = $this->nodos('emailSend')[1]['parameters']['text'];
        $this->assertStringContainsString("motivo === 'debito'", $texto);
        $this->assertStringContainsString('vencida', $texto);
    }

    public function test_no_contiene_secretos(): void
    {
        $crudo = (string)file_get_contents(self::RUTA);
        $this->assertSame(0, preg_match('/(APP_USR|TEST-[0-9a-f]{8}|eyJ[A-Za-z0-9_-]{20,}|[A-Fa-f0-9]{32,}|password\s*"\s*:\s*"[^"]+")/i', $crudo), 'Parece haber un secreto en el JSON');
        foreach ($this->workflow()['nodes'] as $nodo) {
            foreach ($nodo['credentials'] ?? [] as $tipo => $cred) {
                $this->assertSame(['name'], array_keys($cred), "Las credenciales de {$nodo['name']} ($tipo) solo pueden ser una referencia por nombre");
            }
        }
    }

    private function soloMail(string $remitente): string
    {
        return preg_match('/<([^>]+)>/', $remitente, $m) ? $m[1] : $remitente;
    }
}
