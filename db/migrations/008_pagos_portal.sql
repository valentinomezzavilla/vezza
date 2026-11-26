-- VEZZA · Pagos con Mercado Pago (Checkout Pro) iniciados por el cliente desde su portal
-- (spec 2026-11-26-vencimiento-fijo-y-pagos-portal). Aditiva: no renombra ni borra nada existente.
-- Cada "Pagar con Mercado Pago" deja un intento con el monto que puso el servidor y su preferencia. El pago se registra recién
-- cuando Mercado Pago avisa (tema payment), se lo consulta a su API y está aprobado con ese mismo monto: referencia portal-{id}.

CREATE TABLE pagos_portal (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  suscripcion_cliente_id INT UNSIGNED NOT NULL,
  cliente_id INT UNSIGNED NOT NULL,
  monto DECIMAL(12,2) NOT NULL,
  moneda CHAR(3) NOT NULL,
  vencimiento DATE NOT NULL,
  mp_preference_id VARCHAR(100) NULL,
  estado ENUM('creado','pendiente','aprobado','rechazado') NOT NULL DEFAULT 'creado',
  mp_payment_id VARCHAR(100) NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NULL,
  KEY idx_pagos_portal_cliente (cliente_id, creado_en),
  CONSTRAINT fk_pagos_portal_suscripcion FOREIGN KEY (suscripcion_cliente_id) REFERENCES suscripciones_cliente (id) ON DELETE CASCADE,
  CONSTRAINT fk_pagos_portal_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
