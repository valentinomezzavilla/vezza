-- VEZZA · Transferencias que el cliente informa desde su portal (spec 2026-11-26-vencimiento-fijo-y-pagos-portal)
-- Aditiva: no renombra ni borra nada existente. Se aplica desde /admin/migraciones.
-- Un aviso del cliente no es un pago: queda pendiente hasta que el admin lo confirma (y recién ahí se registra el pago y sale el
-- comprobante) o lo rechaza con un motivo (y se le avisa por mail). `archivo` es el comprobante de la transferencia que subió.

CREATE TABLE pagos_informados (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  suscripcion_cliente_id INT UNSIGNED NOT NULL,
  cliente_id INT UNSIGNED NOT NULL,
  fecha DATE NOT NULL,
  monto DECIMAL(12,2) NOT NULL,
  moneda CHAR(3) NOT NULL,
  archivo VARCHAR(64) NULL,
  nota VARCHAR(300) NULL,
  estado ENUM('pendiente','confirmado','rechazado') NOT NULL DEFAULT 'pendiente',
  motivo_rechazo VARCHAR(255) NULL,
  cobro_id INT UNSIGNED NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resuelto_en DATETIME NULL,
  KEY idx_pagos_informados_estado (estado, creado_en),
  KEY idx_pagos_informados_cliente (cliente_id, creado_en),
  CONSTRAINT fk_pagos_informados_suscripcion FOREIGN KEY (suscripcion_cliente_id) REFERENCES suscripciones_cliente (id) ON DELETE CASCADE,
  CONSTRAINT fk_pagos_informados_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE CASCADE,
  CONSTRAINT fk_pagos_informados_cobro FOREIGN KEY (cobro_id) REFERENCES cobros (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
