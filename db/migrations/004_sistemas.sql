-- VEZZA · Sistemas de los clientes conectados al admin (módulo "Acceder a VEZZA" de cada sistema)
-- Aditiva: no renombra ni borra nada existente. Se aplica desde /admin/migraciones.
-- Cada sistema que se le hizo a un cliente (su repo de GitHub y su URL) se conecta con un token propio. Con ese token, el
-- servidor del sistema consulta /api/sistema-cuenta y le muestra a su usuario el estado de su cuenta en VEZZA.
-- Del token se guarda solo el hash (sha256): el token se muestra una vez, al generarlo, y no se puede volver a leer.

CREATE TABLE sistemas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT UNSIGNED NOT NULL,
  nombre VARCHAR(255) NOT NULL,
  repo VARCHAR(255) NULL,
  url VARCHAR(500) NULL,
  token_hash CHAR(64) NULL,
  token_generado_en DATETIME NULL,
  ultimo_contacto DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_sistemas_token (token_hash),
  KEY idx_sistemas_cliente (cliente_id),
  CONSTRAINT fk_sistemas_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
