-- VEZZA · Panel admin · esquema inicial
-- En el primer deploy se importa a mano en phpMyAdmin; se registra solo en `migraciones`.

CREATE TABLE IF NOT EXISTS migraciones (
  nombre VARCHAR(190) NOT NULL PRIMARY KEY,
  aplicada_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE clientes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(255) NOT NULL,
  email VARCHAR(255) NULL,
  telefono VARCHAR(50) NULL,
  rubro VARCHAR(255) NULL,
  estado ENUM('activo','pausado','finalizado') NOT NULL DEFAULT 'activo',
  fecha_inicio DATE NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_clientes_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notas_cliente (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT UNSIGNED NOT NULL,
  contenido TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_notas_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE procesos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT UNSIGNED NOT NULL,
  titulo VARCHAR(255) NOT NULL,
  descripcion TEXT NULL,
  estado ENUM('por_hacer','en_curso','en_revision','entregado') NOT NULL DEFAULT 'por_hacer',
  prioridad ENUM('baja','media','alta') NOT NULL DEFAULT 'media',
  fecha_inicio DATE NULL,
  fecha_entrega_estimada DATE NULL,
  orden INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_procesos_estado (estado, orden),
  CONSTRAINT fk_procesos_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subtareas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  proceso_id INT UNSIGNED NOT NULL,
  titulo VARCHAR(255) NOT NULL,
  completada TINYINT(1) NOT NULL DEFAULT 0,
  orden INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_subtareas_orden (proceso_id, orden),
  CONSTRAINT fk_subtareas_proceso FOREIGN KEY (proceso_id) REFERENCES procesos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cobros (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT UNSIGNED NOT NULL,
  proceso_id INT UNSIGNED NULL,
  concepto VARCHAR(255) NULL,
  monto DECIMAL(12,2) NOT NULL,
  moneda CHAR(3) NOT NULL,
  fecha_vencimiento DATE NOT NULL,
  fecha_pago DATE NULL,
  estado ENUM('pendiente','pagado') NOT NULL DEFAULT 'pendiente',
  metodo_pago VARCHAR(100) NULL,
  comprobante_url VARCHAR(500) NULL,
  comprobante_archivo VARCHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cobros_estado_venc (estado, fecha_vencimiento),
  KEY idx_cobros_pago (fecha_pago),
  CONSTRAINT fk_cobros_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE RESTRICT,
  CONSTRAINT fk_cobros_proceso FOREIGN KEY (proceso_id) REFERENCES procesos (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE suscripciones (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  servicio VARCHAR(255) NOT NULL,
  categoria VARCHAR(100) NULL,
  monto DECIMAL(12,2) NOT NULL,
  moneda CHAR(3) NOT NULL,
  frecuencia ENUM('mensual','anual') NOT NULL,
  fecha_proximo_cobro DATE NOT NULL,
  activa TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_suscripciones_proximas (activa, fecha_proximo_cobro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE gastos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  suscripcion_id INT UNSIGNED NULL,
  concepto VARCHAR(255) NOT NULL,
  categoria VARCHAR(100) NULL,
  monto DECIMAL(12,2) NOT NULL,
  moneda CHAR(3) NOT NULL,
  fecha DATE NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_gastos_fecha (fecha),
  CONSTRAINT fk_gastos_suscripcion FOREIGN KEY (suscripcion_id) REFERENCES suscripciones (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tareas_personales (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  titulo VARCHAR(255) NOT NULL,
  descripcion TEXT NULL,
  estado ENUM('pendiente','en_curso','hecha') NOT NULL DEFAULT 'pendiente',
  prioridad ENUM('baja','media','alta') NOT NULL DEFAULT 'media',
  fecha_vencimiento DATE NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_tareas_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fixs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT UNSIGNED NOT NULL,
  proceso_id INT UNSIGNED NULL,
  titulo VARCHAR(255) NOT NULL,
  descripcion TEXT NULL,
  estado ENUM('reportado','en_progreso','resuelto') NOT NULL DEFAULT 'reportado',
  prioridad ENUM('baja','media','alta') NOT NULL DEFAULT 'media',
  fecha_reportado DATE NOT NULL,
  fecha_resuelto DATE NULL,
  orden INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_fixs_estado (estado, orden),
  CONSTRAINT fk_fixs_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE CASCADE,
  CONSTRAINT fk_fixs_proceso FOREIGN KEY (proceso_id) REFERENCES procesos (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE eventos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT UNSIGNED NULL,
  titulo VARCHAR(255) NOT NULL,
  descripcion TEXT NULL,
  fecha_hora DATETIME NOT NULL,
  duracion_min INT NULL,
  tipo ENUM('reunion','llamada','recordatorio','otro') NOT NULL DEFAULT 'otro',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_eventos_fecha (fecha_hora),
  CONSTRAINT fk_eventos_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_intentos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_login_ip (ip, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO migraciones (nombre) VALUES ('001_inicial');
