# LT Marketplace Suite — Security Policy

**Version:** 1.5.0
**Maintained by:** LTMS Security Team

---

## 1. Supported Versions

| Version | Supported |
|---------|-----------|
| 1.5.x   | ✅ Active  |
| 1.4.x   | ⚠️ Security fixes only |
| < 1.4   | ❌ End of life |

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

**Nonce verification:** All AJAX endpoints verify `wp_verify_nonce()`.

**Capability checks:** Every admin action checks `current_user_can()` before execution.

**Vendor isolation:** Vendors cannot access `wp-admin`. Enforced via `redirect_vendor_from_admin()` hook.

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
| 1.5.0 | 2025-01-01 | Initial enterprise security implementation |
| — | — | AES-256 encryption for all PII fields |
| — | — | WAF with IP banning |
| — | — | Immutable forensic logging with DB triggers |
| — | — | SAGRILAFT compliance logging |

---

*For urgent security matters, email security@ltmarketplace.co*
