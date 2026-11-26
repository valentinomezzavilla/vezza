-- VEZZA · Comprobantes de pago, no válidos como factura (spec 2026-11-26-vencimiento-fijo-y-pagos-portal)
-- Aditiva: no renombra ni borra nada existente. Se aplica desde /admin/migraciones.
-- Cada pago registrado emite un comprobante con número correlativo (el id, que se muestra como 0001-00000042). Lo que dice
-- queda fijo en `datos` (JSON) al emitirlo: nunca cambia ni se borra. Si el pago se deshace, el comprobante queda anulado.
-- `actualizado_en` marca la última vez que cambió (emisión o anulación): de ahí copian los sistemas de los clientes.
-- No es lo mismo que cobros.comprobante_archivo, que es el archivo de la transferencia que sube el cliente.

CREATE TABLE comprobantes_pago (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cobro_id INT UNSIGNED NULL,
  cliente_id INT UNSIGNED NOT NULL,
  datos TEXT NOT NULL,
  emitido_en DATETIME NOT NULL,
  destino VARCHAR(255) NULL,
  enviado_en DATETIME NULL,
  error VARCHAR(255) NULL,
  anulado_en DATETIME NULL,
  motivo_anulacion VARCHAR(255) NULL,
  actualizado_en DATETIME NOT NULL,
  UNIQUE KEY uk_comprobantes_pago_cobro (cobro_id),
  KEY idx_comprobantes_pago_cliente (cliente_id, actualizado_en),
  CONSTRAINT fk_comprobantes_pago_cobro FOREIGN KEY (cobro_id) REFERENCES cobros (id) ON DELETE SET NULL,
  CONSTRAINT fk_comprobantes_pago_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
