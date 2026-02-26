-- ============================================================
-- LT Marketplace Suite (LTMS) - Database Triggers Anti-Tampering
-- Versión: 1.5.0
-- ============================================================
-- Estos triggers implementan:
-- 1. Inmutabilidad del ledger (no UPDATE/DELETE en wallet_transactions)
-- 2. Validación de checksum en billeteras
-- 3. Registro de auditoría automático al modificar datos críticos
-- ============================================================

DELIMITER $$

-- ============================================================
-- TRIGGER: Prevenir UPDATE en lt_wallet_transactions
-- El ledger financiero es INMUTABLE. Solo INSERT.
-- ============================================================
DROP TRIGGER IF EXISTS `ltms_prevent_update_wallet_tx`$$
CREATE TRIGGER `ltms_prevent_update_wallet_tx`
BEFORE UPDATE ON `{WP_PREFIX}lt_wallet_transactions`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'LTMS_SECURITY: Las transacciones de billetera son inmutables. No se permite UPDATE.';
END$$

-- ============================================================
-- TRIGGER: Prevenir DELETE en lt_wallet_transactions
-- ============================================================
DROP TRIGGER IF EXISTS `ltms_prevent_delete_wallet_tx`$$
CREATE TRIGGER `ltms_prevent_delete_wallet_tx`
BEFORE DELETE ON `{WP_PREFIX}lt_wallet_transactions`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'LTMS_SECURITY: Las transacciones de billetera son inmutables. No se permite DELETE.';
END$$

-- ============================================================
-- TRIGGER: Prevenir DELETE en lt_audit_logs
-- Los logs forenses son inmutables.
-- ============================================================
DROP TRIGGER IF EXISTS `ltms_prevent_delete_audit_log`$$
CREATE TRIGGER `ltms_prevent_delete_audit_log`
BEFORE DELETE ON `{WP_PREFIX}lt_audit_logs`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'LTMS_SECURITY: Los logs de auditoría son inmutables. No se permite DELETE.';
END$$

-- ============================================================
-- TRIGGER: Prevenir UPDATE en lt_audit_logs
-- ============================================================
DROP TRIGGER IF EXISTS `ltms_prevent_update_audit_log`$$
CREATE TRIGGER `ltms_prevent_update_audit_log`
BEFORE UPDATE ON `{WP_PREFIX}lt_audit_logs`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'LTMS_SECURITY: Los logs de auditoría son inmutables. No se permite UPDATE.';
END$$

-- ============================================================
-- TRIGGER: Validar balance no negativo en lt_vendor_wallets
-- Previene que un balance llegue a negativo (control financiero)
-- ============================================================
DROP TRIGGER IF EXISTS `ltms_validate_wallet_balance`$$
CREATE TRIGGER `ltms_validate_wallet_balance`
BEFORE UPDATE ON `{WP_PREFIX}lt_vendor_wallets`
FOR EACH ROW
BEGIN
    IF NEW.balance < 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'LTMS_FINANCIAL: El saldo de la billetera no puede ser negativo.';
    END IF;
    IF NEW.balance_pending < 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'LTMS_FINANCIAL: El saldo pendiente no puede ser negativo.';
    END IF;
END$$

-- ============================================================
-- TRIGGER: Registrar intentos de modificación en billeteras
-- Si alguien intenta modificar campos críticos, queda en log
-- ============================================================
DROP TRIGGER IF EXISTS `ltms_audit_wallet_change`$$
CREATE TRIGGER `ltms_audit_wallet_change`
AFTER UPDATE ON `{WP_PREFIX}lt_vendor_wallets`
FOR EACH ROW
BEGIN
    -- Solo auditar si el balance cambió significativamente
    IF OLD.balance <> NEW.balance OR OLD.is_frozen <> NEW.is_frozen THEN
        INSERT INTO `{WP_PREFIX}lt_audit_logs`
            (`event_code`, `message`, `context`, `level`, `created_at`)
        VALUES
            (
                'WALLET_MODIFIED',
                CONCAT('Billetera #', NEW.id, ' del vendor #', NEW.vendor_id, ' modificada directamente en DB'),
                JSON_OBJECT(
                    'wallet_id', NEW.id,
                    'vendor_id', NEW.vendor_id,
                    'old_balance', OLD.balance,
                    'new_balance', NEW.balance,
                    'old_frozen', OLD.is_frozen,
                    'new_frozen', NEW.is_frozen
                ),
                'SECURITY',
                NOW()
            );
    END IF;
END$$

-- ============================================================
-- TRIGGER: Auto-actualizar checksum al insertar transacción
-- ============================================================
DROP TRIGGER IF EXISTS `ltms_set_tx_checksum`$$
CREATE TRIGGER `ltms_set_tx_checksum`
BEFORE INSERT ON `{WP_PREFIX}lt_wallet_transactions`
FOR EACH ROW
BEGIN
    -- Checksum simple usando SHA2 sobre campos críticos
    SET NEW.checksum = SHA2(
        CONCAT(NEW.wallet_id, '|', NEW.amount, '|', NEW.balance_after, '|', NEW.created_at, '|', 'LTMS_SALT_2025'),
        256
    );
END$$

-- ============================================================
-- TRIGGER: Prevenir retiro si la billetera está congelada
-- ============================================================
DROP TRIGGER IF EXISTS `ltms_block_frozen_wallet_payout`$$
CREATE TRIGGER `ltms_block_frozen_wallet_payout`
BEFORE INSERT ON `{WP_PREFIX}lt_payout_requests`
FOR EACH ROW
BEGIN
    DECLARE wallet_frozen TINYINT(1) DEFAULT 0;

    SELECT is_frozen INTO wallet_frozen
    FROM `{WP_PREFIX}lt_vendor_wallets`
    WHERE id = NEW.wallet_id
    LIMIT 1;

    IF wallet_frozen = 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'LTMS_COMPLIANCE: No se puede procesar el retiro. La billetera está congelada por cumplimiento.';
    END IF;
END$$

DELIMITER ;

-- ============================================================
-- FIN DE TRIGGERS
-- ============================================================
