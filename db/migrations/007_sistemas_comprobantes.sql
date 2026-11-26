-- VEZZA · Copia de los comprobantes de pago en los sistemas de los clientes (spec 2026-11-26-vencimiento-fijo-y-pagos-portal)
-- Aditiva: no renombra ni borra nada existente. Se aplica desde /admin/migraciones.
-- Cada sistema pide los comprobantes de su cliente a /api/sistema-comprobantes con el cursor de lo que ya copió. Ese cursor
-- (la fecha del último cambio que tiene) se guarda acá: la ficha muestra si a un sistema le falta copiar alguno.

ALTER TABLE sistemas
  ADD COLUMN comprobantes_copiados_hasta DATETIME NULL AFTER ultimo_contacto;
