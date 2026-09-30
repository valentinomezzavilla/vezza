-- VEZZA · Portal de clientes · esquema (spec 2026-09-30-portal-clientes-design)
-- Aditiva: no renombra ni borra nada existente. Se aplica desde /admin/migraciones.

CREATE TABLE usuarios_cliente (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT UNSIGNED NOT NULL,
  email VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  token_hash CHAR(64) NULL,
  token_tipo ENUM('invitacion','recuperacion') NULL,
  token_expira DATETIME NULL,
  ultimo_login DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_usuarios_cliente_email (email),
  UNIQUE KEY uk_usuarios_cliente_cliente (cliente_id),
  KEY idx_usuarios_cliente_token (token_hash),
  CONSTRAINT fk_usuarios_cliente_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE planes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(255) NOT NULL,
  descripcion TEXT NULL,
  monto DECIMAL(12,2) NOT NULL,
  moneda CHAR(3) NOT NULL,
  frecuencia ENUM('mensual','anual') NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  orden INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_planes_activo (activo, orden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE suscripciones_cliente (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT UNSIGNED NOT NULL,
  plan_id INT UNSIGNED NOT NULL,
  estado ENUM('activa','cancelada') NOT NULL DEFAULT 'activa',
  fecha_inicio DATE NOT NULL,
  fecha_proximo_cobro DATE NULL,
  fecha_baja DATE NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_susc_cliente (cliente_id, estado),
  CONSTRAINT fk_susc_cliente_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE CASCADE,
  CONSTRAINT fk_susc_cliente_plan FOREIGN KEY (plan_id) REFERENCES planes (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE solicitudes_suscripcion (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT UNSIGNED NOT NULL,
  suscripcion_id INT UNSIGNED NULL,
  tipo ENUM('alta','cambio','baja') NOT NULL,
  plan_id INT UNSIGNED NULL,
  estado ENUM('pendiente','aprobada','rechazada','retirada') NOT NULL DEFAULT 'pendiente',
  nota_cliente TEXT NULL,
  nota_admin TEXT NULL,
  resuelta_en DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_solicitudes_estado (estado, created_at),
  KEY idx_solicitudes_cliente (cliente_id),
  CONSTRAINT fk_solicitudes_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE CASCADE,
  CONSTRAINT fk_solicitudes_suscripcion FOREIGN KEY (suscripcion_id) REFERENCES suscripciones_cliente (id) ON DELETE SET NULL,
  CONSTRAINT fk_solicitudes_plan FOREIGN KEY (plan_id) REFERENCES planes (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE servicios (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT UNSIGNED NOT NULL,
  suscripcion_id INT UNSIGNED NULL,
  nombre VARCHAR(255) NOT NULL,
  url VARCHAR(500) NULL,
  chequear TINYINT(1) NOT NULL DEFAULT 0,
  estado_auto ENUM('activo','caido','desconocido') NOT NULL DEFAULT 'desconocido',
  fallos_consecutivos INT UNSIGNED NOT NULL DEFAULT 0,
  estado_manual ENUM('activo','mantenimiento','caido') NULL,
  estado_manual_hasta DATETIME NULL,
  mensaje VARCHAR(500) NULL,
  ultimo_chequeo DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_servicios_cliente (cliente_id),
  CONSTRAINT fk_servicios_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE CASCADE,
  CONSTRAINT fk_servicios_suscripcion FOREIGN KEY (suscripcion_id) REFERENCES suscripciones_cliente (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE servicio_eventos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  servicio_id INT UNSIGNED NOT NULL,
  estado ENUM('activo','mantenimiento','caido') NOT NULL,
  origen ENUM('auto','manual') NOT NULL,
  mensaje VARCHAR(500) NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_servicio_eventos (servicio_id, creado_en),
  CONSTRAINT fk_servicio_eventos_servicio FOREIGN KEY (servicio_id) REFERENCES servicios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categorias_ticket (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(255) NOT NULL,
  tipo ENUM('fix','tarea') NOT NULL,
  prioridad_default ENUM('baja','media','alta') NOT NULL DEFAULT 'media',
  orden INT NOT NULL DEFAULT 0,
  activa TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO categorias_ticket (nombre, tipo, prioridad_default, orden) VALUES
  ('Algo no funciona o está caído', 'fix', 'alta', 1),
  ('Error visual o de datos', 'fix', 'media', 2),
  ('Cambio de contenido', 'tarea', 'baja', 3),
  ('Nueva funcionalidad', 'tarea', 'media', 4),
  ('Mejora de algo existente', 'tarea', 'media', 5),
  ('Consulta', 'tarea', 'baja', 6);

CREATE TABLE tickets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT UNSIGNED NOT NULL,
  usuario_id INT UNSIGNED NULL,
  servicio_id INT UNSIGNED NULL,
  categoria_id INT UNSIGNED NOT NULL,
  tipo ENUM('fix','tarea') NOT NULL,
  asunto VARCHAR(255) NOT NULL,
  descripcion TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_tickets_cliente (cliente_id, created_at),
  KEY idx_tickets_tipo (tipo, created_at),
  CONSTRAINT fk_tickets_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE CASCADE,
  CONSTRAINT fk_tickets_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios_cliente (id) ON DELETE SET NULL,
  CONSTRAINT fk_tickets_servicio FOREIGN KEY (servicio_id) REFERENCES servicios (id) ON DELETE SET NULL,
  CONSTRAINT fk_tickets_categoria FOREIGN KEY (categoria_id) REFERENCES categorias_ticket (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tareas_cliente (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT UNSIGNED NOT NULL,
  ticket_id INT UNSIGNED NULL,
  proceso_id INT UNSIGNED NULL,
  titulo VARCHAR(255) NOT NULL,
  descripcion TEXT NULL,
  estado ENUM('pendiente','en_curso','hecha') NOT NULL DEFAULT 'pendiente',
  prioridad ENUM('baja','media','alta') NOT NULL DEFAULT 'media',
  fecha_vencimiento DATE NULL,
  orden INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_tareas_cliente_ticket (ticket_id),
  KEY idx_tareas_cliente_estado (estado, orden),
  CONSTRAINT fk_tareas_cliente_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE CASCADE,
  CONSTRAINT fk_tareas_cliente_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE SET NULL,
  CONSTRAINT fk_tareas_cliente_proceso FOREIGN KEY (proceso_id) REFERENCES procesos (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE fixs
  ADD COLUMN ticket_id INT UNSIGNED NULL AFTER proceso_id,
  ADD UNIQUE KEY uk_fixs_ticket (ticket_id),
  ADD CONSTRAINT fk_fixs_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE SET NULL;

ALTER TABLE login_intentos
  ADD COLUMN ambito VARCHAR(10) NOT NULL DEFAULT 'admin',
  ADD COLUMN clave VARCHAR(255) NULL,
  ADD KEY idx_login_ambito_clave (ambito, clave, creado_en);

INSERT IGNORE INTO migraciones (nombre) VALUES ('002_portal');
