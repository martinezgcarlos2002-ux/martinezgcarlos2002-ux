-- Parche ADARA · semana vacía 9–15 oct 2026
-- Probado contra el respaldo adara_respaldo_2026-10-21_180807
-- (base del servidor: u980302731_adarabd).
--
-- Pasa el pago de Tayron (3085, $826.95), Wilmer (3086, $1222.65)
-- y Yousef (3087, $656.40) de la semana vacía 9–15 oct
-- a la semana con leche 10–16 oct, y anula la semana vacía.
-- No toca a Jhonny Aguilera ni semanas de mayo a septiembre.
--
-- Hostinger: phpMyAdmin, base u980302731_adarabd, pestaña Importar.
-- No pegar este archivo en la caja SQL.
-- Si los pagos ya no están en la semana vacía, se detiene y no cambia nada.
--
-- Resultado esperado:
--   Tayron  1615  parcial   pendiente  20.15
--   Wilmer  1617  pagada    pendiente -14.95
--   Yousef  1621  parcial   pendiente  90.60
--   1638, 1639 y 1640 quedan anuladas y dejan de verse en el historial.

SET NAMES utf8mb4;
SET time_zone = '-04:00';

DROP PROCEDURE IF EXISTS parche_validar_semana_vacia;
DROP PROCEDURE IF EXISTS parche_aplicar_semana_vacia;

DELIMITER $$
CREATE PROCEDURE parche_validar_semana_vacia()
BEGIN
    DECLARE pagos INT DEFAULT 0;
    DECLARE vacias INT DEFAULT 0;
    DECLARE leche INT DEFAULT 0;

    SELECT COUNT(*) INTO pagos
    FROM productores_abonos
    WHERE anulado_en IS NULL
      AND (
            (id = 3085 AND productor_id = 65 AND cierre_id = 1638 AND monto_usd = 826.95 AND semana_inicio = '2026-10-09')
         OR (id = 3086 AND productor_id = 77 AND cierre_id = 1639 AND monto_usd = 1222.65 AND semana_inicio = '2026-10-09')
         OR (id = 3087 AND productor_id = 70 AND cierre_id = 1640 AND monto_usd = 656.40 AND semana_inicio = '2026-10-09')
      );

    SELECT COUNT(*) INTO vacias
    FROM productores_cierres
    WHERE id IN (1638, 1639, 1640)
      AND semana_inicio = '2026-10-09'
      AND litros = 0 AND bruto_usd = 0
      AND estado <> 'anulada'
      AND productor_id IN (65, 77, 70);

    SELECT COUNT(*) INTO leche
    FROM productores_cierres
    WHERE (id = 1615 AND productor_id = 65 AND semana_inicio = '2026-10-10' AND bruto_usd = 867.10)
       OR (id = 1617 AND productor_id = 77 AND semana_inicio = '2026-10-10' AND bruto_usd = 1207.70)
       OR (id = 1621 AND productor_id = 70 AND semana_inicio = '2026-10-10' AND bruto_usd = 646.80);

    IF pagos <> 3 OR vacias <> 3 OR leche <> 3 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'No se aplico el parche: los pagos o las semanas ya no coinciden con el respaldo del 21 oct 2026 18:08. No se cambio nada.';
    END IF;
END$$

CREATE PROCEDURE parche_aplicar_semana_vacia()
BEGIN
    UPDATE productores_abonos
    SET semana_inicio = '2026-10-10', cierre_id = 1615
    WHERE id = 3085 AND productor_id = 65 AND cierre_id = 1638 AND anulado_en IS NULL;

    UPDATE productores_abonos
    SET semana_inicio = '2026-10-10', cierre_id = 1617
    WHERE id = 3086 AND productor_id = 77 AND cierre_id = 1639 AND anulado_en IS NULL;

    UPDATE productores_abonos
    SET semana_inicio = '2026-10-10', cierre_id = 1621
    WHERE id = 3087 AND productor_id = 70 AND cierre_id = 1640 AND anulado_en IS NULL;

    UPDATE productores_cierres
    SET estado = 'anulada', pagado_en = NULL,
        pagado_usd = 0.00, abonos_usd = 0.00, arrastre_anterior_usd = 0.00, saldo_usd = 0.00
    WHERE id IN (1638, 1639, 1640) AND semana_inicio = '2026-10-09';

    UPDATE productores_cierres
    SET arrastre_anterior_usd = 0.00, abonos_usd = 20.00, pagado_usd = 826.95,
        saldo_usd = 847.10, estado = 'parcial', pagado_en = NULL
    WHERE id = 1615 AND productor_id = 65;

    UPDATE productores_cierres
    SET arrastre_anterior_usd = 0.00, abonos_usd = 0.00, pagado_usd = 1222.65,
        saldo_usd = 1207.70, estado = 'pagada'
    WHERE id = 1617 AND productor_id = 77;

    UPDATE productores_cierres
    SET arrastre_anterior_usd = 100.20, abonos_usd = 0.00, pagado_usd = 656.40,
        saldo_usd = 747.00, estado = 'parcial', pagado_en = NULL
    WHERE id = 1621 AND productor_id = 70;
END$$
DELIMITER ;

CALL parche_validar_semana_vacia();

DROP TRIGGER IF EXISTS trg_abonos_inmutable;

CALL parche_aplicar_semana_vacia();

DELIMITER $$
CREATE TRIGGER trg_abonos_inmutable
BEFORE UPDATE ON productores_abonos
FOR EACH ROW
BEGIN
    IF OLD.anulado_en IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El pago ya esta anulado';
    END IF;
    IF NOT (NEW.productor_id = OLD.productor_id AND NEW.fecha = OLD.fecha AND NEW.semana_inicio = OLD.semana_inicio
            AND NEW.tipo = OLD.tipo AND NEW.moneda = OLD.moneda AND NEW.monto = OLD.monto
            AND NEW.monto_usd = OLD.monto_usd AND NEW.tasa_bs_por_usd <=> OLD.tasa_bs_por_usd
            AND NEW.registrado_por = OLD.registrado_por) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Los datos economicos del pago son inmutables';
    END IF;
END$$
DELIMITER ;

DROP PROCEDURE IF EXISTS parche_validar_semana_vacia;
DROP PROCEDURE IF EXISTS parche_aplicar_semana_vacia;

SELECT c.id, p.nombre, c.semana_inicio, c.estado,
       c.pagado_usd, c.saldo_usd, c.saldo_usd - c.pagado_usd AS pendiente
FROM productores_cierres c
JOIN productores p ON p.id = c.productor_id
WHERE c.id IN (1615, 1617, 1621, 1638, 1639, 1640)
ORDER BY p.nombre, c.semana_inicio;
