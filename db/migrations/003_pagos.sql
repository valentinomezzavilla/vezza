-- VEZZA · Cobro de suscripciones con Mercado Pago · esquema (spec 2026-10-27-pagos-suscripciones)
-- Aditiva: no renombra ni borra nada existente. Se aplica desde /admin/migraciones.

-- Cada suscripción guarda su propio monto y moneda (el precio del plan puede cambiar
-- sin tocar lo que ya se acordó) y cómo se le cobra.
ALTER TABLE suscripciones_cliente
  ADD COLUMN monto DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER plan_id,
  ADD COLUMN moneda CHAR(3) NOT NULL DEFAULT 'ARS' AFTER monto,
  ADD COLUMN modalidad ENUM('debito','link','transferencia') NOT NULL DEFAULT 'transferencia' AFTER moneda,
  ADD COLUMN mp_preapproval_id VARCHAR(100) NULL AFTER modalidad,
  ADD COLUMN mp_preapproval_estado ENUM('pendiente','autorizada','pausada','cancelada') NULL AFTER mp_preapproval_id,
  ADD COLUMN mp_init_point VARCHAR(500) NULL AFTER mp_preapproval_estado,
  ADD COLUMN pausada TINYINT(1) NOT NULL DEFAULT 0 AFTER mp_init_point,
  ADD UNIQUE KEY uk_susc_cliente_preapproval (mp_preapproval_id);

-- Las suscripciones que ya existen toman el precio y la moneda de su plan.
UPDATE suscripciones_cliente sc
  JOIN planes p ON p.id = sc.plan_id
  SET sc.monto = p.monto, sc.moneda = p.moneda;

-- Un cobro generado por una suscripción se identifica por (suscripción, período YYYY-MM).
-- Los cobros sueltos (sin suscripción ni período) no chocan entre sí: NULL no cuenta como repetido.
ALTER TABLE cobros
  ADD COLUMN suscripcion_cliente_id INT UNSIGNED NULL AFTER proceso_id,
  ADD COLUMN periodo CHAR(7) NULL AFTER suscripcion_cliente_id,
  ADD COLUMN mp_payment_id VARCHAR(100) NULL,
  ADD COLUMN mp_preference_id VARCHAR(100) NULL,
  ADD COLUMN link_pago VARCHAR(500) NULL,
  ADD UNIQUE KEY uk_cobros_susc_periodo (suscripcion_cliente_id, periodo),
  ADD UNIQUE KEY uk_cobros_mp_payment (mp_payment_id),
  ADD CONSTRAINT fk_cobros_suscripcion_cliente FOREIGN KEY (suscripcion_cliente_id) REFERENCES suscripciones_cliente (id) ON DELETE SET NULL;

-- Registro de cada notificación (webhook) que manda Mercado Pago.
CREATE TABLE mp_notificaciones (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tipo VARCHAR(60) NOT NULL,
  recurso_id VARCHAR(100) NOT NULL,
  payload TEXT NULL,
  cobro_id INT UNSIGNED NULL,
  estado_pago VARCHAR(30) NULL,
  recibida_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  procesada_en DATETIME NULL,
  sin_cobro TINYINT(1) NOT NULL DEFAULT 0,
  error VARCHAR(255) NULL,
  KEY idx_mp_notif_recurso (tipo, recurso_id),
  KEY idx_mp_notif_cobro (cobro_id),
  CONSTRAINT fk_mp_notif_cobro FOREIGN KEY (cobro_id) REFERENCES cobros (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Avisos de pago enviados por cobro, para no mandar dos veces el mismo.
CREATE TABLE avisos_pago (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cobro_id INT UNSIGNED NOT NULL,
  tipo ENUM('previo','vencimiento','vencido','confirmacion','rechazo') NOT NULL,
  canal ENUM('mail','whatsapp') NOT NULL,
  numero INT NOT NULL DEFAULT 0,
  enviado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ok TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uk_avisos_pago (cobro_id, tipo, canal, numero),
  CONSTRAINT fk_avisos_pago_cobro FOREIGN KEY (cobro_id) REFERENCES cobros (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cambios de precio de una suscripción, hasta que se le avisa al cliente.
CREATE TABLE suscripciones_precio_historial (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  suscripcion_cliente_id INT UNSIGNED NOT NULL,
  monto_anterior DECIMAL(12,2) NOT NULL,
  monto_nuevo DECIMAL(12,2) NOT NULL,
  moneda CHAR(3) NOT NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  avisado_en DATETIME NULL,
  KEY idx_precio_historial_susc (suscripcion_cliente_id, creado_en),
  CONSTRAINT fk_precio_historial_susc FOREIGN KEY (suscripcion_cliente_id) REFERENCES suscripciones_cliente (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO migraciones (nombre) VALUES ('003_pagos');
