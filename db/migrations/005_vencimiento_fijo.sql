-- VEZZA · Vencimiento fijo de las suscripciones (spec 2026-11-26-vencimiento-fijo-y-pagos-portal)
-- Aditiva: no renombra ni borra nada existente. Se aplica desde /admin/migraciones.
-- Cada suscripción vence un día fijo de cada mes (1 a 28) y fecha_proximo_cobro pasa a ser su próximo vencimiento, que
-- siempre cae en ese día. Las que ya existen vencen el 5: su próximo vencimiento pasa al primer día 5 igual o posterior
-- a la fecha que tenían (nunca antes, así nadie queda vencido por la migración). Las que no tienen fecha la siguen sin tener.

ALTER TABLE suscripciones_cliente
  ADD COLUMN dia_vencimiento TINYINT UNSIGNED NOT NULL DEFAULT 5 AFTER fecha_proximo_cobro;

UPDATE suscripciones_cliente
  SET fecha_proximo_cobro = CASE
    WHEN DAY(fecha_proximo_cobro) <= 5 THEN DATE_ADD(fecha_proximo_cobro, INTERVAL 5 - DAY(fecha_proximo_cobro) DAY)
    ELSE DATE_ADD(DATE_ADD(DATE_SUB(fecha_proximo_cobro, INTERVAL DAY(fecha_proximo_cobro) - 1 DAY), INTERVAL 1 MONTH), INTERVAL 4 DAY)
  END
  WHERE fecha_proximo_cobro IS NOT NULL;
