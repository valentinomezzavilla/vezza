-- VEZZA · Monitor de servicios (chequeos externos HTTP, TCP y SSL)
-- Aditiva: no renombra ni borra nada existente. Se aplica desde /admin/migraciones.

CREATE TABLE monitor_servicios (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  grupo VARCHAR(100) NULL,
  nombre VARCHAR(255) NOT NULL,
  tipo ENUM('http','tcp','ssl') NOT NULL,
  destino VARCHAR(500) NOT NULL,
  puerto SMALLINT UNSIGNED NULL,
  codigo_esperado SMALLINT UNSIGNED NULL,
  timeout_seg TINYINT UNSIGNED NOT NULL DEFAULT 10,
  intervalo_min SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  fallos_para_caer TINYINT UNSIGNED NOT NULL DEFAULT 2,
  ssl_dias_aviso TINYINT UNSIGNED NOT NULL DEFAULT 14,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  estado ENUM('desconocido','ok','caido') NOT NULL DEFAULT 'desconocido',
  fallos_seguidos SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ultimo_chequeo DATETIME NULL,
  ultimo_cambio DATETIME NULL,
  ultima_latencia_ms INT UNSIGNED NULL,
  ultimo_detalle VARCHAR(255) NULL,
  ssl_vence DATE NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_monitor_servicios_activo (activo, ultimo_chequeo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE monitor_chequeos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  servicio_id INT UNSIGNED NOT NULL,
  hecho_en DATETIME NOT NULL,
  ok TINYINT(1) NOT NULL,
  latencia_ms INT UNSIGNED NULL,
  detalle VARCHAR(255) NULL,
  KEY idx_monitor_chequeos_servicio (servicio_id, hecho_en),
  KEY idx_monitor_chequeos_fecha (hecho_en),
  CONSTRAINT fk_monitor_chequeos_servicio FOREIGN KEY (servicio_id) REFERENCES monitor_servicios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE monitor_eventos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  servicio_id INT UNSIGNED NOT NULL,
  tipo ENUM('caida','recuperacion') NOT NULL,
  creado_en DATETIME NOT NULL,
  duracion_seg INT UNSIGNED NULL,
  detalle VARCHAR(255) NULL,
  notificado TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_monitor_eventos_servicio (servicio_id, creado_en),
  KEY idx_monitor_eventos_fecha (creado_en),
  CONSTRAINT fk_monitor_eventos_servicio FOREIGN KEY (servicio_id) REFERENCES monitor_servicios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
