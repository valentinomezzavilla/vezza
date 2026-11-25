-- VEZZA · Cobro de suscripciones con Mercado Pago · esquema (spec 2026-11-25-pagos-estilo-vezzashop)
-- Aditiva: no renombra ni borra nada existente. Se aplica desde /admin/migraciones.
-- Modelo: débito automático de Mercado Pago o pago registrado a mano. Cada pago deja un cobro `pagado` y adelanta
-- suscripciones_cliente.fecha_proximo_cobro ("pago hasta"); no hay cobros generados de antemano ni links de pago.

-- Cada suscripción guarda su propio monto y moneda (el precio del plan puede cambiar
-- sin tocar lo que ya se acordó) y cómo se le cobra. debito_al_cancelar recuerda si al darla de baja tenía un débito vivo
-- en Mercado Pago: solo en ese caso, al reactivarla, se le pide al cliente que autorice uno nuevo.
ALTER TABLE suscripciones_cliente
  ADD COLUMN monto DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER plan_id,
  ADD COLUMN moneda CHAR(3) NOT NULL DEFAULT 'ARS' AFTER monto,
  ADD COLUMN modalidad ENUM('debito','transferencia') NOT NULL DEFAULT 'transferencia' AFTER moneda,
  ADD COLUMN mp_preapproval_id VARCHAR(100) NULL AFTER modalidad,
  ADD COLUMN mp_preapproval_estado ENUM('pendiente','autorizada','pausada','cancelada') NULL AFTER mp_preapproval_id,
  ADD COLUMN mp_init_point VARCHAR(500) NULL AFTER mp_preapproval_estado,
  ADD COLUMN pausada TINYINT(1) NOT NULL DEFAULT 0 AFTER mp_init_point,
  ADD COLUMN aviso_baja_enviado_en DATETIME NULL AFTER pausada,
  ADD COLUMN debito_al_cancelar TINYINT(1) NOT NULL DEFAULT 0 AFTER aviso_baja_enviado_en,
  ADD UNIQUE KEY uk_susc_cliente_preapproval (mp_preapproval_id);

-- Las suscripciones que ya existen toman el precio y la moneda de su plan.
UPDATE suscripciones_cliente sc
  JOIN planes p ON p.id = sc.plan_id
  SET sc.monto = p.monto, sc.moneda = p.moneda;

-- Un cobro que deja un pago de una suscripción se vincula a ella; el pago de Mercado Pago es único, así que un
-- aviso repetido no puede registrarse dos veces. Los cobros sueltos (sin suscripción ni pago) no chocan: NULL no cuenta como repetido.
ALTER TABLE cobros
  ADD COLUMN suscripcion_cliente_id INT UNSIGNED NULL AFTER proceso_id,
  ADD COLUMN mp_payment_id VARCHAR(100) NULL,
  ADD UNIQUE KEY uk_cobros_mp_payment (mp_payment_id),
  ADD CONSTRAINT fk_cobros_suscripcion_cliente FOREIGN KEY (suscripcion_cliente_id) REFERENCES suscripciones_cliente (id) ON DELETE SET NULL;

-- Registro de cada notificación (webhook) que manda Mercado Pago: auditoría, y marca de los pagos que no
-- se pudieron asignar a ninguna suscripción (sin_cobro) para revisarlos a mano.
CREATE TABLE mp_notificaciones (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tipo VARCHAR(60) NOT NULL,
  recurso_id VARCHAR(100) NOT NULL,
  payload TEXT NULL,
  recibida_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  procesada_en DATETIME NULL,
  sin_cobro TINYINT(1) NOT NULL DEFAULT 0,
  error VARCHAR(255) NULL,
  KEY idx_mp_notif_recurso (tipo, recurso_id)
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
