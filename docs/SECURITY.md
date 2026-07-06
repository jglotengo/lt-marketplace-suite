# LT Marketplace Suite — Security Policy

**Version:** 2.9.35
**Maintained by:** LTMS Security Team

---

## 1. Supported Versions

| Version | Supported |
|---------|-----------|
| 2.9.x   | ✅ Active  |
| 2.8.x   | ⚠️ Security fixes only |
| < 2.8   | ❌ End of life |

---

## 2. Reporting a Vulnerability

**DO NOT** report security vulnerabilities through public GitHub issues.

**Email:** security@ltmarketplace.co
**PGP Key:** Available on request
**Response SLA:** 48 hours acknowledgment / 7 days initial assessment

Please include:
- Description of the vulnerability
- Steps to reproduce
- Potential impact assessment
- Your contact information (optional for responsible disclosure)

---

## 3. Security Architecture

### 3.1 Encryption

All sensitive data is encrypted at rest using AES-256-CBC:

```php
// Encryption uses PBKDF2 key derivation
$key = hash_pbkdf2('sha256', WP_LTMS_MASTER_KEY, $salt, 10000, 32, true);
$cipher = openssl_encrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
```

**Encrypted fields:**
- Bank account numbers
- Government document numbers
- API credentials stored in wp_options
- Payment gateway private keys

**Key storage:** `WP_LTMS_MASTER_KEY` constant must be defined in `wp-config.php`.
Never store encryption keys in the database.

### 3.2 Web Application Firewall (WAF)

The built-in WAF (`LTMS_Firewall`) inspects all incoming requests at `init` priority 1:

**Blocked patterns:**
- SQL injection: `UNION SELECT`, `DROP TABLE`, `1=1`, `OR 1`, etc.
- XSS: `<script>`, `javascript:`, `onerror=`, etc.
- LFI/RFI: `../../`, `file://`, `php://`, etc.
- Bad bots: Configured user-agent blocklist

**IP banning:**
- Automatic 24-hour ban after 10 WAF triggers from same IP
- Manual ban through admin panel
- Ban records stored in `lt_waf_blocked_ips`

### 3.3 Forensic Logging

All security events are logged to `lt_security_events` with immutability enforced by database triggers:

```sql
-- Trigger prevents UPDATE and DELETE on security log
DELIMITER $$
CREATE TRIGGER prevent_log_update
BEFORE UPDATE ON lt_security_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Security log records are immutable';
END$$
```

**Logged events:**
- Authentication (success/failure/lockout)
- WAF blocks
- Admin actions (approve/reject KYC, freeze wallet)
- Auditor access
- Large payout requests (SAGRILAFT threshold)

### 3.4 Authentication & Session Security

**Login throttling:** 5 attempts per IP per 15 minutes using WordPress transients.

**Nonce verification:** All AJAX endpoints verify `wp_verify_nonce()`. The storefront AJAX endpoints use the `ltms_ux_nonce` action (NOT `ltms_storefront_nonce`, which was the old, broken name and has been removed in v2.9.35).

**Capability checks:** Every admin action checks `current_user_can()` before execution.

**Vendor isolation:** Vendors cannot access `wp-admin`. Enforced via `redirect_vendor_from_admin()` hook.

### 3.4.1 TOTP 2FA (v2.9.35)

Vendors and compliance officers can enroll in **Time-based One-Time Password (TOTP)** two-factor authentication via `includes/core/class-ltms-totp-2fa.php`.

**Algorithm:** RFC 6238 (SHA-1, 30-second window, 6 digits) — compatible with Google Authenticator, Microsoft Authenticator, Authy, 1Password.

**Secret storage:**
- 32-byte base32 secret generated via `random_bytes()`
- Encrypted with `LTMS_Core_Security::encrypt()` (AES-256-CBC + PBKDF2) before saving to `user_meta` key `ltms_totp_secret`
- Never stored in plaintext, never logged

**Recovery codes:**
- 10 single-use codes generated on enrollment
- Stored bcrypt-hashed (cost 12) in `user_meta` key `ltms_totp_recovery_hashes`
- Each code is consumed on use and cannot be reused
- Regenerated on demand; old codes invalidated

**Login flow:**
1. User submits username + password
2. If password verifies AND `ltms_totp_secret` exists → redirect to `ltms_2fa_challenge` page
3. User submits 6-digit TOTP code (or recovery code)
4. `verify_code()` checks the 30-second window (allows ±1 step for clock drift)
5. On success → complete login; on failure → log to `lt_security_events` and retry

**Enforcement:**
- Admin can force 2FA for specific roles via `ltms_force_2fa_roles` setting (default: `ltms_vendor_premium`, `ltms_compliance_officer`)
- 7-day grace period from first login after enrollment before 2FA is mandatory (configurable via `ltms_2fa_grace_period_days`)
- During grace period, dashboard shows persistent reminder banner

**Anti-replay:** each TOTP code can only be used once per session — a hash of the code is cached for 30s via transient `ltms_totp_used_{user_id}_{code_hash}`.

**Vendor dashboard view:** `includes/frontend/views/view-security.php` — enroll, scan QR, verify, view/regenerate recovery codes, disable.

### 3.4.2 SAT México Compliance Logging

The TOTP module and login flow write to `lt_security_events` for Mexican SAT (Servicio de Administración Tributaria) compliance auditing:

| Event type | Trigger | Data logged |
|-----------|---------|-------------|
| `2fa_enrolled` | Vendor completes TOTP enrollment | user_id, IP, user-agent, timestamp |
| `2fa_challenge_failed` | Wrong TOTP code submitted | user_id, IP, user-agent, attempt count |
| `2fa_disabled` | Vendor (or admin) disables 2FA | user_id, IP, reason (self / admin_force) |
| `2fa_recovery_used` | Recovery code consumed | user_id, IP, code index (not the code itself) |
| `2fa_locked_out` | 5 failed TOTP attempts in 15 min | user_id, IP, lockout duration |

These events are exportable as CSV from the admin Security panel for SAT audit submission.

### 3.5 Input Validation & Sanitization

All user inputs are sanitized using WordPress functions:
- `sanitize_text_field()` for text
- `absint()` for integers
- `wp_kses_post()` for HTML content
- `esc_url_raw()` for URLs

Database queries use `$wpdb->prepare()` with `%s`, `%d`, `%f` placeholders exclusively.

### 3.6 SAGRILAFT Compliance (Colombia)

The Superintendencia de Vigilancia y Seguridad Privada (SAGRILAFT) requires:
- KYC verification before enabling withdrawals
- Logging of transactions above 10,000 UVT (~$497M COP 2025)
- Automatic flagging of unusual transaction patterns
- Auditor read-only access to transaction data

**Implementation:**
- `LTMS_Data_Masking::log_auditor_access()` tracks all auditor views
- Large payouts appear in the auditor dashboard
- KYC documents require admin approval before wallet unlock

---

## 4. Required wp-config.php Entries

```php
// Master encryption key (generate with: openssl rand -base64 32)
define('WP_LTMS_MASTER_KEY', 'your-unique-64-char-key-here');

// Openpay credentials
define('WP_LTMS_OPENPAY_ID_CO',         'your-openpay-co-merchant-id');
define('WP_LTMS_OPENPAY_KEY_CO',        'your-openpay-co-private-key');
define('WP_LTMS_OPENPAY_PUBLIC_KEY_CO', 'your-openpay-co-public-key');

// Force SSL
define('FORCE_SSL_ADMIN', true);
```

---

## 5. Server Hardening Recommendations

### 5.1 PHP Configuration (`php.ini`)
```ini
expose_php = Off
allow_url_fopen = Off
allow_url_include = Off
session.cookie_httponly = 1
session.cookie_secure = 1
```

### 5.2 Nginx Security Headers
```nginx
add_header X-Frame-Options "SAMEORIGIN" always;
add_header X-Content-Type-Options "nosniff" always;
add_header X-XSS-Protection "1; mode=block" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Content-Security-Policy "default-src 'self'; script-src 'self' https://js.openpay.co https://js.openpay.mx;" always;
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
```

### 5.3 File Permissions
```bash
# WordPress files
find /var/www/html -type f -exec chmod 644 {} \;
find /var/www/html -type d -exec chmod 755 {} \;

# wp-config.php: especially restrictive
chmod 600 /var/www/html/wp-config.php
```

---

## 6. Dependency Security

Run security audits regularly:
```bash
composer audit
npm audit
```

All Composer packages are pinned to specific versions in `composer.lock`.

---

## 7. Bug Bounty

We appreciate responsible disclosure. Depending on severity:

| Severity | Reward |
|----------|--------|
| Critical (RCE, SQLi, Authentication Bypass) | Acknowledged + Hall of Fame |
| High (Stored XSS, IDOR, Privilege Escalation) | Acknowledged |
| Medium/Low | Acknowledged |

---

## 8. Security Changelog

| Version | Date | Change |
|---------|------|--------|
| 2.9.35 | 2026-07-06 | TOTP 2FA (RFC 6238) for vendors and compliance officers |
| — | — | SAT México compliance logging (5 new event types in `lt_security_events`) |
| — | — | `LTMS_Core_Firewall::get_client_ip()` made `public` (was `private`, caused WSOD via `LTMS_Data_Masking` call) |
| — | — | `LTMS_Core_Security::derive_key()` duplicate declaration removed (was fatal on boot) |
| — | — | Storefront nonce action standardized to `ltms_ux_nonce` (removed `ltms_storefront_nonce`) |
| — | — | `continue 2` illegal use fixed in `logistics-compliance.php` |
| — | — | Admin Security page template — missing `<?php` opening tag fixed (PHP source was leaking to browser) |
| — | — | 11 SAT México columns added to `lt_commissions` (CFDI 4.0 compliance) |
| 1.5.0 | 2025-01-01 | Initial enterprise security implementation |
| — | — | AES-256 encryption for all PII fields |
| — | — | WAF with IP banning |
| — | — | Immutable forensic logging with DB triggers |
| — | — | SAGRILAFT compliance logging |

---

*For urgent security matters, email security@ltmarketplace.co*
