# LT Marketplace Suite — Security Policy

**Version:** 2.9.98
**Maintained by:** LTMS Security Team
**Last audit:** 2026-07-08 (DEEP-AUDIT-002 — 56 findings, 100% P0+P1+P2 resolved)

---

## 1. Supported Versions

| Version | Supported |
|---------|------------|
| 2.9.x   | ✅ Active |
| 2.8.x   | ⚠️ Security fixes only |
| < 2.8   | ❌ End of life |

---

## 2. Reporting a Vulnerability

**DO NOT** report security vulnerabilities through public GitHub issues.

**Email:** pqrscolombia@lo-tengo.com.co
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

All sensitive data is encrypted at rest using **AES-256-GCM (v2)** with backward-compatible **AES-256-CBC (v1)** for legacy data:

```php
// v2: AES-256-GCM (authenticated encryption — preferred)
$key = hash_pbkdf2('sha256', WP_LTMS_MASTER_KEY, $salt, 10000, 32, true);
$cipher = openssl_encrypt($data, 'AES-256-GCM', $key, OPENSSL_RAW_DATA, $iv, $tag);

// v1: AES-256-CBC (legacy, still decryptable for backward-compat)
$cipher = openssl_encrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
```

The encryption version is stored as a prefix (`v1:` / `v2:`) in the ciphertext. `LTMS_Core_Security::decrypt()` auto-detects the version and uses the appropriate algorithm.

**Encrypted fields (v2.9.98 complete list):**
- Bank account numbers (`lt_payout_requests.bank_account_number`)
- Government document numbers (`lt_vendor_kyc.*`, `lt_vendor_drivers.document_number`)
- Vehicle plates (`lt_vendor_drivers.vehicle_plate`)
- API credentials stored in `wp_options` (PosGold token, Aveonline credentials, Alegra token, OpenPay keys, Stripe keys, XCover credentials)
- OAuth tokens (Google OAuth refresh tokens)
- TOTP secrets (`user_meta.ltms_totp_secret`)
- 2FA recovery codes (`user_meta.ltms_totp_recovery_hashes`, bcrypt-hashed)

**Key storage:** `WP_LTMS_MASTER_KEY` constant must be defined in `wp-config.php`.
Never store encryption keys in the database.

**IDOR Protection (v2.9.61 P0-4):** All endpoints that read/write vendor data verify ownership before proceeding:
```php
$existing_vendor = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT vendor_id FROM `{$table}` WHERE id = %d", $driver_id
));
if ( $existing_vendor !== $vendor_id ) {
    wp_send_json_error( __( 'No autorizado.', 'ltms' ), 403 );
    return;
}
```
This pattern is applied to: KYC, bank accounts, payout requests, drivers, insurance policies, incidents, orders (multi-vendor split).

**Bank Account Masking (v2.9.61 P0-5):** Bank account numbers are decrypted server-side and masked (`****1234`) before being sent to the browser. The full plaintext NEVER appears in the DOM.

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

### 3.4.1 TOTP 2FA (v2.9.35, hardening en v2.9.61)

Vendors and compliance officers can enroll in **Time-based One-Time Password (TOTP)** two-factor authentication via `includes/core/class-ltms-totp-2fa.php`.

**Algorithm:** RFC 6238 (SHA-1, 30-second window, 6 digits) — compatible with Google Authenticator, Microsoft Authenticator, Authy, 1Password.

**Secret storage:**
- 32-byte base32 secret generated via `random_bytes()`
- Encrypted with `LTMS_Core_Security::encrypt()` (AES-256-GCM v2) before saving to `user_meta` key `ltms_totp_secret`
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

**Rate limiting (v2.9.61 P1):** 5 failed TOTP attempts per IP per 15 minutes → temporal lockout. Uses atomic `INSERT ON DUPLICATE KEY UPDATE` to prevent race conditions.

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

### 3.7 CSP Compliance (v2.9.84 P1)

The vendor dashboard frontend is **Content-Security-Policy compliant** — zero inline event handlers:
- All `onclick`, `onchange`, `onfocus`, `onsubmit`, `onload` attributes have been replaced with `addEventListener()` calls and `data-action` delegation.
- All `alert()` calls have been replaced with a slide-in toast notification system.
- `location.reload()` is only used when strictly necessary (e.g., create/edit flows that need fresh server-rendered HTML).

This allows the plugin to work under strict CSP headers:
```nginx
add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline';" always;
```

### 3.8 Cloudflare Turnstile CAPTCHA (v2.9.60 MISSING-03)

The vendor registration form optionally integrates **Cloudflare Turnstile** for bot protection (alternative to reCAPTCHA). Enabled via admin setting `ltms_turnstile_enabled`.

- Site key + secret key stored in `wp_options` (encrypted at rest).
- Verification happens server-side via Cloudflare API.
- Falls back gracefully (form still works) if Turnstile is not configured.
- Used on: registration form, 2FA challenge (optional).

### 3.9 Nonce Standardization (v2.9.61 P3-5)

All AJAX endpoints use one of four standardized nonce actions:

| Context | Nonce action |
|---------|-------------|
| Vendor dashboard | `ltms_dashboard_nonce` |
| Storefront (customers) | `ltms_ux_nonce` |
| Admin panel | `ltms_admin_nonce` |
| Auth (login, register, 2FA, Google OAuth) | `ltms_auth_nonce` |

The legacy `ltms_storefront_nonce` has been removed.

### 3.10 Rate Limiting (v2.9.60 REG-01/02)

Atomic rate limiting via `$wpdb->query("INSERT ... ON DUPLICATE KEY UPDATE count = count + 1")`:
- Registration: 5 attempts per IP per 15 minutes
- Login: 5 attempts per IP per 15 minutes
- 2FA challenge: 5 attempts per IP per 15 minutes
- Password reset: 3 attempts per IP per hour
- KYC submission: 3 attempts per vendor per hour

Rate limit records stored in `lt_rate_limits` table (atomic, no race conditions).

### 3.11 SSRF Protection (v2.9.61 P1)

The PosGold API client validates URLs against an allow-list before making outbound HTTP requests, preventing Server-Side Request Forgery (SSRF) attacks if a vendor manages to inject a malicious URL into their PosGold credentials.

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
| 2.9.98 | 2026-07-08 | Nav integration Seguros + Domiciliarios (conditional on vendor config) |
| — | — | Drivers count cache (`_ltms_drivers_count_cache`) to avoid DB query per dashboard render |
| 2.9.97 | 2026-07-08 | view-insurance + view-drivers full expansion (KPIs, filters, edit, SVG empty states) |
| — | — | Bug fix: view-drivers forms/buttons had no JS handlers (now all functional via fetch + toast) |
| 2.9.96 | 2026-07-08 | SVG illustrations in empty states (orders, kitchen) |
| — | — | CSP fix: inline `onchange` in shipping-statement → `data-action` delegation |
| 2.9.84-95 | 2026-07-08 | **UIUX-AUDIT-001**: 62 findings, 100% resolved (P0×7, P1×15, P2×22, P3×13) |
| — | — | CSP compliance: 0 inline handlers across all 25 views |
| — | — | Toast system (0 alerts), dark mode, mobile bottom nav, keyboard shortcuts |
| — | — | WCAG 2.1 AA: skip-link, focus-visible, modal focus traps, aria-labels |
| 2.9.61-77 | 2026-07-07 | **DEEP-AUDIT-002**: 56 findings, 100% P0+P1+P2 resolved |
| — | — | P0: ReDi pause/resume, PosGold token masking, Aveonline OC access, KYC IDOR, bank account decrypt |
| — | — | P1: 2FA rate limit, nopriv abuse vectors, payout bank validation, PosGold SSRF, KYC doc validation |
| — | — | P2: Custom tables (backorder + review votes), bank account sync, PosGold cron, onboarding store check |
| — | — | P3: Dead code cleanup, nonce standardization, PosGold JSON categories, KYC expiry cron |
| 2.9.60 | 2026-07-06 | **REG-AUDIT-001**: 11 fixes + 3 missing features |
| — | — | REG-10: Google OAuth nonce fix (`ltms_admin_nonce` → `ltms_auth_nonce`) |
| — | — | REG-01/02: Atomic rate limiting via `INSERT ON DUPLICATE KEY UPDATE` |
| — | — | REG-04: E.164 phone validation |
| — | — | REG-05/06/07: Whitelists for business_type, document_type, vendor_country |
| — | — | REG-08: HTML email templates for KYC |
| — | — | REG-09: Verification URL → `wp_login_url()` |
| — | — | REG-11: `set_role()` → `add_role()` |
| — | — | MISSING-03: Cloudflare Turnstile CAPTCHA (optional) |
| — | — | MISSING-04: Admin notification on new registration |
| — | — | MISSING-08: Resend verification email endpoint |
| — | — | UX-06: Google OAuth profile completion |
| 2.9.35 | 2026-07-06 | TOTP 2FA (RFC 6238) for vendors and compliance officers |
| — | — | SAT México compliance logging (5 new event types in `lt_security_events`) |
| — | — | `LTMS_Core_Firewall::get_client_ip()` made `public` (was `private`, caused WSOD) |
| — | — | `LTMS_Core_Security::derive_key()` duplicate declaration removed (fatal on boot) |
| — | — | Storefront nonce action standardized to `ltms_ux_nonce` (removed `ltms_storefront_nonce`) |
| — | — | 11 SAT México columns added to `lt_commissions` (CFDI 4.0 compliance) |
| 1.5.0 | 2025-01-01 | Initial enterprise security implementation |
| — | — | AES-256 encryption for all PII fields |
| — | — | WAF with IP banning |
| — | — | Immutable forensic logging with DB triggers |
| — | — | SAGRILAFT compliance logging |

---

*For urgent security matters, email pqrscolombia@lo-tengo.com.co*
