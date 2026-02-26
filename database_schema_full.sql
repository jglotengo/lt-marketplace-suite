-- ============================================================
-- LT Marketplace Suite (LTMS) - Esquema SQL Maestro v1.5.0
-- Compatible: MySQL 8.0+ / MariaDB 10.6+
-- Prefijo de tablas: {WP_PREFIX}lt_
-- ============================================================
-- INSTRUCCIONES: Reemplazar {WP_PREFIX} con el prefijo real
-- (default: wp_) antes de ejecutar. El Kernel lo hace automáticamente.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- ============================================================
-- TABLA: lt_vendor_wallets
-- Billetera principal de cada vendedor (Ledger)
-- ============================================================
CREATE TABLE IF NOT EXISTS `{WP_PREFIX}lt_vendor_wallets` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `vendor_id`         BIGINT UNSIGNED NOT NULL COMMENT 'WP user_id del vendedor',
    `balance`           DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Saldo disponible para retiro',
    `balance_pending`   DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Saldo en periodo de retención',
    `balance_reserved`  DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Saldo reservado para dispute/retención legal',
    `currency`          CHAR(3) NOT NULL DEFAULT 'COP' COMMENT 'ISO 4217',
    `is_frozen`         TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = cuenta bloqueada por compliance',
    `freeze_reason`     VARCHAR(500) DEFAULT NULL,
    `total_earned`      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `total_withdrawn`   DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `last_transaction`  DATETIME DEFAULT NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `checksum`          CHAR(64) DEFAULT NULL COMMENT 'SHA-256 para integridad anti-tampering',
    PRIMARY KEY (`id`),
    UNIQUE KEY `udx_vendor_id` (`vendor_id`),
    KEY `idx_balance` (`balance`),
    KEY `idx_is_frozen` (`is_frozen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Billetera principal de cada vendedor registrado en LTMS';

-- ============================================================
-- TABLA: lt_wallet_transactions
-- Ledger ACID: registro inmutable de cada movimiento
-- ============================================================
CREATE TABLE IF NOT EXISTS `{WP_PREFIX}lt_wallet_transactions` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `wallet_id`       BIGINT UNSIGNED NOT NULL,
    `vendor_id`       BIGINT UNSIGNED NOT NULL,
    `order_id`        BIGINT UNSIGNED DEFAULT NULL COMMENT 'WC order_id relacionado',
    `type`            ENUM('credit','debit','hold','release','reversal','adjustment','payout','fee','tax_withholding') NOT NULL,
    `amount`          DECIMAL(15,2) NOT NULL,
    `balance_before`  DECIMAL(15,2) NOT NULL COMMENT 'Snapshot antes de la transacción',
    `balance_after`   DECIMAL(15,2) NOT NULL COMMENT 'Snapshot después de la transacción',
    `currency`        CHAR(3) NOT NULL DEFAULT 'COP',
    `description`     VARCHAR(500) NOT NULL,
    `reference`       VARCHAR(255) DEFAULT NULL COMMENT 'ID externo (Openpay, Siigo, etc.)',
    `status`          ENUM('pending','completed','failed','reversed') NOT NULL DEFAULT 'completed',
    `metadata`        JSON DEFAULT NULL COMMENT 'Datos adicionales estructurados',
    `ip_address`      VARCHAR(45) DEFAULT NULL COMMENT 'IP de quien inició la acción',
    `created_by`      BIGINT UNSIGNED DEFAULT NULL COMMENT 'WP user_id del creador (admin/system)',
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `checksum`        CHAR(64) DEFAULT NULL COMMENT 'SHA-256 de (id+wallet_id+amount+balance_after)',
    PRIMARY KEY (`id`),
    KEY `idx_wallet_id` (`wallet_id`),
    KEY `idx_vendor_id` (`vendor_id`),
    KEY `idx_order_id` (`order_id`),
    KEY `idx_type` (`type`),
    KEY `idx_status` (`status`),
    KEY `idx_created_at` (`created_at`),
    CONSTRAINT `fk_wt_wallet` FOREIGN KEY (`wallet_id`) REFERENCES `{WP_PREFIX}lt_vendor_wallets` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Ledger inmutable de todas las transacciones de billetera';

-- ============================================================
-- TABLA: lt_commissions
-- Registro de comisiones calculadas por venta
-- ============================================================
CREATE TABLE IF NOT EXISTS `{WP_PREFIX}lt_commissions` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id`          BIGINT UNSIGNED NOT NULL,
    `order_item_id`     BIGINT UNSIGNED DEFAULT NULL,
    `vendor_id`         BIGINT UNSIGNED NOT NULL,
    `product_id`        BIGINT UNSIGNED NOT NULL,
    `gross_amount`      DECIMAL(15,2) NOT NULL COMMENT 'Precio bruto del ítem',
    `commission_rate`   DECIMAL(5,4) NOT NULL COMMENT 'Tasa aplicada (ej: 0.0800 = 8%)',
    `commission_amount` DECIMAL(15,2) NOT NULL COMMENT 'Monto de comisión de la plataforma',
    `vendor_amount`     DECIMAL(15,2) NOT NULL COMMENT 'Monto neto para el vendedor',
    `tax_withholding`   DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Retención fiscal (Ret.Fuente CO / ISR MX)',
    `iva_amount`        DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `currency`          CHAR(3) NOT NULL DEFAULT 'COP',
    `country_code`      CHAR(2) NOT NULL DEFAULT 'CO',
    `status`            ENUM('pending','approved','paid','reversed','disputed') NOT NULL DEFAULT 'pending',
    `paid_at`           DATETIME DEFAULT NULL,
    `strategy_applied`  VARCHAR(100) DEFAULT NULL COMMENT 'Clase de estrategia fiscal usada',
    `metadata`          JSON DEFAULT NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_order_id` (`order_id`),
    KEY `idx_vendor_id` (`vendor_id`),
    KEY `idx_status` (`status`),
    KEY `idx_country` (`country_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Comisiones calculadas por cada venta procesada';

-- ============================================================
-- TABLA: lt_payout_requests
-- Solicitudes de retiro de billetera
-- ============================================================
CREATE TABLE IF NOT EXISTS `{WP_PREFIX}lt_payout_requests` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `vendor_id`       BIGINT UNSIGNED NOT NULL,
    `wallet_id`       BIGINT UNSIGNED NOT NULL,
    `amount`          DECIMAL(15,2) NOT NULL,
    `currency`        CHAR(3) NOT NULL DEFAULT 'COP',
    `method`          ENUM('bank_transfer','paypal','nequi','daviplata','spei','clabe') NOT NULL DEFAULT 'bank_transfer',
    `bank_account`    JSON NOT NULL COMMENT 'Datos cifrados de cuenta bancaria',
    `status`          ENUM('pending','approved','processing','completed','rejected','cancelled') NOT NULL DEFAULT 'pending',
    `admin_notes`     TEXT DEFAULT NULL,
    `rejection_reason` VARCHAR(500) DEFAULT NULL,
    `transaction_id`  BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK a lt_wallet_transactions',
    `external_ref`    VARCHAR(255) DEFAULT NULL COMMENT 'Referencia en pasarela de pago',
    `processed_by`    BIGINT UNSIGNED DEFAULT NULL COMMENT 'WP admin user_id',
    `requested_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `processed_at`    DATETIME DEFAULT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_vendor_id` (`vendor_id`),
    KEY `idx_status` (`status`),
    KEY `idx_requested_at` (`requested_at`),
    CONSTRAINT `fk_pr_wallet` FOREIGN KEY (`wallet_id`) REFERENCES `{WP_PREFIX}lt_vendor_wallets` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Solicitudes de retiro de fondos de los vendedores';

-- ============================================================
-- TABLA: lt_audit_logs
-- Log forense inmutable (actividad del sistema)
-- ============================================================
CREATE TABLE IF NOT EXISTS `{WP_PREFIX}lt_audit_logs` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event_code`  VARCHAR(100) NOT NULL COMMENT 'Ej: ORDER_PAID, WAF_BLOCK, AUDIT_ACCESS',
    `message`     TEXT NOT NULL,
    `context`     JSON DEFAULT NULL,
    `level`       ENUM('DEBUG','INFO','WARNING','ERROR','CRITICAL','SECURITY') NOT NULL DEFAULT 'INFO',
    `user_id`     BIGINT UNSIGNED DEFAULT NULL,
    `ip_address`  VARCHAR(45) DEFAULT NULL,
    `user_agent`  VARCHAR(500) DEFAULT NULL,
    `url`         VARCHAR(2048) DEFAULT NULL,
    `source`      VARCHAR(100) DEFAULT NULL COMMENT 'Clase PHP que generó el log',
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_event_code` (`event_code`),
    KEY `idx_level` (`level`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_ip_address` (`ip_address`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Log de auditoría forense del sistema (solo INSERT, nunca UPDATE/DELETE)';

-- ============================================================
-- TABLA: lt_security_events
-- Eventos de seguridad del WAF
-- ============================================================
CREATE TABLE IF NOT EXISTS `{WP_PREFIX}lt_security_events` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event_type`  VARCHAR(100) NOT NULL COMMENT 'Ej: SQL_INJECTION, XSS, BRUTE_FORCE',
    `severity`    ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    `ip_address`  VARCHAR(45) NOT NULL,
    `user_id`     BIGINT UNSIGNED DEFAULT NULL,
    `request_uri` VARCHAR(2048) DEFAULT NULL,
    `request_method` ENUM('GET','POST','PUT','DELETE','PATCH','OPTIONS','HEAD') DEFAULT NULL,
    `payload`     TEXT DEFAULT NULL COMMENT 'Payload sospechoso (ofuscado)',
    `rule_matched` VARCHAR(255) DEFAULT NULL COMMENT 'Regla WAF disparada',
    `blocked`     TINYINT(1) NOT NULL DEFAULT 1,
    `country_code` CHAR(2) DEFAULT NULL COMMENT 'País detectado via GeoIP',
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_event_type` (`event_type`),
    KEY `idx_ip_address` (`ip_address`),
    KEY `idx_severity` (`severity`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Eventos de seguridad capturados por el WAF integrado';

-- ============================================================
-- TABLA: lt_waf_blocked_ips
-- IPs bloqueadas por el WAF (blacklist dinámica)
-- ============================================================
CREATE TABLE IF NOT EXISTS `{WP_PREFIX}lt_waf_blocked_ips` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ip_address`  VARCHAR(45) NOT NULL,
    `reason`      VARCHAR(255) NOT NULL,
    `block_count` INT UNSIGNED NOT NULL DEFAULT 1,
    `expires_at`  DATETIME DEFAULT NULL COMMENT 'NULL = bloqueo permanente',
    `is_permanent` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `udx_ip` (`ip_address`),
    KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: lt_vendor_kyc
-- Documentos de identidad KYC de vendedores
-- ============================================================
CREATE TABLE IF NOT EXISTS `{WP_PREFIX}lt_vendor_kyc` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `vendor_id`       BIGINT UNSIGNED NOT NULL,
    `document_type`   ENUM('cedula','nit','pasaporte','rfc','curp','ine','rut','otro') NOT NULL,
    `document_number` VARCHAR(50) NOT NULL COMMENT 'Cifrado AES-256',
    `full_name`       VARCHAR(255) NOT NULL COMMENT 'Nombre completo',
    `file_path`       VARCHAR(500) DEFAULT NULL COMMENT 'Ruta relativa en vault seguro',
    `file_hash`       CHAR(64) DEFAULT NULL COMMENT 'SHA-256 del archivo para integridad',
    `status`          ENUM('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending',
    `verified_by`     BIGINT UNSIGNED DEFAULT NULL,
    `verified_at`     DATETIME DEFAULT NULL,
    `rejection_reason` VARCHAR(500) DEFAULT NULL,
    `expires_at`      DATE DEFAULT NULL,
    `country_code`    CHAR(2) NOT NULL DEFAULT 'CO',
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_vendor_id` (`vendor_id`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Documentos KYC de vendedores (paths cifrados al vault seguro)';

-- ============================================================
-- TABLA: lt_referral_network
-- Árbol genealógico de referidos (MLM)
-- ============================================================
CREATE TABLE IF NOT EXISTS `{WP_PREFIX}lt_referral_network` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `vendor_id`     BIGINT UNSIGNED NOT NULL COMMENT 'El referido',
    `referrer_id`   BIGINT UNSIGNED NOT NULL COMMENT 'Quien lo refirió',
    `level`         TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Nivel en la red (1=directo, 2=indirecto)',
    `referral_code` VARCHAR(50) NOT NULL COMMENT 'Código único de referido',
    `source`        VARCHAR(100) DEFAULT NULL COMMENT 'UTM source / canal de adquisición',
    `total_sales`   DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `total_commission` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `status`        ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `udx_vendor_referrer` (`vendor_id`, `referrer_id`),
    KEY `idx_vendor_id` (`vendor_id`),
    KEY `idx_referrer_id` (`referrer_id`),
    KEY `idx_referral_code` (`referral_code`),
    KEY `idx_level` (`level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Red de referidos multi-nivel del sistema MLM';

-- ============================================================
-- TABLA: lt_notifications
-- Notificaciones del sistema (in-app + WhatsApp + email)
-- ============================================================
CREATE TABLE IF NOT EXISTS `{WP_PREFIX}lt_notifications` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     BIGINT UNSIGNED NOT NULL,
    `type`        VARCHAR(100) NOT NULL COMMENT 'order_new, payout_approved, kyc_rejected, etc.',
    `channel`     ENUM('inapp','email','whatsapp','sms','push') NOT NULL DEFAULT 'inapp',
    `title`       VARCHAR(255) NOT NULL,
    `message`     TEXT NOT NULL,
    `data`        JSON DEFAULT NULL,
    `is_read`     TINYINT(1) NOT NULL DEFAULT 0,
    `read_at`     DATETIME DEFAULT NULL,
    `sent_at`     DATETIME DEFAULT NULL,
    `expires_at`  DATETIME DEFAULT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_is_read` (`is_read`),
    KEY `idx_type` (`type`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Notificaciones del sistema para vendedores y admins';

-- ============================================================
-- TABLA: lt_api_logs
-- Log de todas las llamadas a APIs externas
-- ============================================================
CREATE TABLE IF NOT EXISTS `{WP_PREFIX}lt_api_logs` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `provider`        VARCHAR(50) NOT NULL COMMENT 'siigo, openpay, addi, aveonline, etc.',
    `endpoint`        VARCHAR(500) NOT NULL,
    `method`          VARCHAR(10) NOT NULL,
    `request_body`    MEDIUMTEXT DEFAULT NULL COMMENT 'Body enviado (datos sensibles ofuscados)',
    `response_code`   SMALLINT DEFAULT NULL,
    `response_body`   MEDIUMTEXT DEFAULT NULL,
    `duration_ms`     INT UNSIGNED DEFAULT NULL COMMENT 'Tiempo de respuesta en ms',
    `status`          ENUM('success','error','timeout','retry') NOT NULL DEFAULT 'success',
    `order_id`        BIGINT UNSIGNED DEFAULT NULL,
    `error_message`   TEXT DEFAULT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_provider` (`provider`),
    KEY `idx_status` (`status`),
    KEY `idx_order_id` (`order_id`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Log de todas las integraciones con APIs externas';

-- ============================================================
-- TABLA: lt_webhook_logs
-- Webhooks recibidos de APIs externas
-- ============================================================
CREATE TABLE IF NOT EXISTS `{WP_PREFIX}lt_webhook_logs` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `provider`      VARCHAR(50) NOT NULL,
    `event_type`    VARCHAR(100) NOT NULL,
    `payload`       MEDIUMTEXT NOT NULL,
    `signature`     VARCHAR(500) DEFAULT NULL COMMENT 'Firma HMAC recibida',
    `is_valid`      TINYINT(1) DEFAULT NULL COMMENT 'NULL=sin verificar, 1=válido, 0=inválido',
    `status`        ENUM('received','processing','processed','failed','ignored') NOT NULL DEFAULT 'received',
    `order_id`      BIGINT UNSIGNED DEFAULT NULL,
    `error_message` TEXT DEFAULT NULL,
    `ip_address`    VARCHAR(45) DEFAULT NULL,
    `attempts`      TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `processed_at`  DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_provider` (`provider`),
    KEY `idx_event_type` (`event_type`),
    KEY `idx_status` (`status`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Registro de webhooks entrantes de Openpay, Addi, Siigo, etc.';

-- ============================================================
-- TABLA: lt_job_queue
-- Cola de trabajos en background (AS-compatible)
-- ============================================================
CREATE TABLE IF NOT EXISTS `{WP_PREFIX}lt_job_queue` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hook`          VARCHAR(200) NOT NULL COMMENT 'Nombre del WP action hook a ejecutar',
    `args`          JSON DEFAULT NULL,
    `priority`      TINYINT UNSIGNED NOT NULL DEFAULT 10,
    `status`        ENUM('pending','processing','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
    `attempts`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `max_attempts`  TINYINT UNSIGNED NOT NULL DEFAULT 3,
    `scheduled_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `started_at`    DATETIME DEFAULT NULL,
    `completed_at`  DATETIME DEFAULT NULL,
    `error_message` TEXT DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_hook` (`hook`),
    KEY `idx_status` (`status`),
    KEY `idx_scheduled_at` (`scheduled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cola de trabajos asíncronos del sistema';

-- ============================================================
-- TABLA: lt_rate_limits
-- Control de rate limiting por IP y acción
-- ============================================================
CREATE TABLE IF NOT EXISTS `{WP_PREFIX}lt_rate_limits` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `identifier`  VARCHAR(255) NOT NULL COMMENT 'IP o user_id o combinación',
    `action`      VARCHAR(100) NOT NULL COMMENT 'login, api_call, download, etc.',
    `attempts`    INT UNSIGNED NOT NULL DEFAULT 1,
    `window_start` DATETIME NOT NULL,
    `blocked_until` DATETIME DEFAULT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `udx_identifier_action` (`identifier`(191), `action`),
    KEY `idx_blocked_until` (`blocked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Control de tasa de peticiones para prevenir abusos';

-- ============================================================
-- TABLA: lt_marketing_banners
-- Banners del centro de marketing para vendedores
-- ============================================================
CREATE TABLE IF NOT EXISTS `{WP_PREFIX}lt_marketing_banners` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title`       VARCHAR(255) NOT NULL,
    `type`        ENUM('banner','flyer','social_post','email_template','video') NOT NULL DEFAULT 'banner',
    `file_url`    VARCHAR(500) NOT NULL,
    `thumbnail_url` VARCHAR(500) DEFAULT NULL,
    `dimensions`  VARCHAR(50) DEFAULT NULL COMMENT 'Ej: 1200x628',
    `category`    VARCHAR(100) DEFAULT NULL,
    `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
    `download_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_type` (`type`),
    KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Activos de marketing para el dashboard del vendedor';

-- ============================================================
-- TABLA: lt_tax_reports
-- Reportes fiscales generados (DIAN/SAT)
-- ============================================================
CREATE TABLE IF NOT EXISTS `{WP_PREFIX}lt_tax_reports` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `period_start`    DATE NOT NULL,
    `period_end`      DATE NOT NULL,
    `country_code`    CHAR(2) NOT NULL,
    `report_type`     ENUM('iva','renta','retefuente','ica','isr','ieps','annual') NOT NULL,
    `total_sales`     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `total_tax`       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `total_withheld`  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `vendor_count`    INT UNSIGNED NOT NULL DEFAULT 0,
    `order_count`     INT UNSIGNED NOT NULL DEFAULT 0,
    `report_data`     JSON DEFAULT NULL,
    `pdf_path`        VARCHAR(500) DEFAULT NULL,
    `xml_path`        VARCHAR(500) DEFAULT NULL COMMENT 'Para CFDI México',
    `status`          ENUM('draft','generated','submitted','accepted','rejected') NOT NULL DEFAULT 'draft',
    `generated_by`    BIGINT UNSIGNED NOT NULL,
    `generated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_period` (`period_start`, `period_end`),
    KEY `idx_country` (`country_code`),
    KEY `idx_report_type` (`report_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Reportes fiscales para DIAN (Colombia) y SAT (México)';

-- ============================================================
-- TABLA: lt_deposits
-- Depósitos de seguridad para vendedores (garantías)
-- ============================================================
CREATE TABLE IF NOT EXISTS `{WP_PREFIX}lt_deposits` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `vendor_id`     BIGINT UNSIGNED NOT NULL,
    `amount`        DECIMAL(15,2) NOT NULL,
    `currency`      CHAR(3) NOT NULL DEFAULT 'COP',
    `type`          ENUM('security','tournament','promotional') NOT NULL DEFAULT 'security',
    `status`        ENUM('held','released','forfeited','partial_release') NOT NULL DEFAULT 'held',
    `reason`        VARCHAR(500) DEFAULT NULL,
    `released_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `release_date`  DATE DEFAULT NULL,
    `order_id`      BIGINT UNSIGNED DEFAULT NULL,
    `admin_notes`   TEXT DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_vendor_id` (`vendor_id`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Depósitos de garantía y seguridad de vendedores';

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- FIN DEL ESQUEMA
-- ============================================================
