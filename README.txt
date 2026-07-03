=== LT Marketplace Suite ===
Contributors: ltms-team
Tags: marketplace, multi-vendor, woocommerce, colombia, mexico, wallet, mlm, kyc
Requires at least: 6.3
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enterprise multi-vendor marketplace for WooCommerce. Colombia & Mexico.

== Description ==

LT Marketplace Suite (LTMS) is an enterprise-grade multi-vendor WooCommerce plugin
designed for Latin American markets, with specific support for Colombia (DIAN, SAGRILAFT)
and Mexico (SAT, CFDI 4.0, RESICO).

= Key Features =

* Multi-vendor store management with independent vendor dashboards
* ACID-compliant digital wallet with ledger history
* Country-aware tax engine (Colombia: ReteFuente/ReteIVA/ReteICA; Mexico: ISR 113-A/IEPS)
* MLM 3-level referral network with ancestor-path algorithm
* KYC identity verification workflow
* PWA vendor dashboard with push notifications
* AES-256 encryption for all PII data
* Built-in Web Application Firewall (WAF)
* Immutable forensic logging for compliance

= Payment Gateways =

* Openpay (Colombia & Mexico) — Cards, PSE, OXXO, SPEI
* Addi BNPL — Buy Now Pay Later (Colombia & Mexico)
* Nequi / Daviplata — Mobile wallets (Colombia)
* MSI (Meses Sin Intereses) — Installments (Mexico)

= Integrations =

* Siigo — Electronic invoicing (Colombia DIAN)
* Aveonline — Logistics and shipping
* ZapSign — Electronic signatures for vendor contracts
* TPTC — MLM network synchronization
* XCover — Product insurance
* Backblaze B2 — Secure document storage for KYC

== Installation ==

1. Upload the plugin ZIP via wp-admin > Plugins > Add New > Upload Plugin
2. Activate the plugin
3. Add the required constants to wp-config.php (see wp-config-sample-snippet.php)
4. Navigate to LT Marketplace > Configuración to complete setup
5. Add shortcodes to your pages:
   - [ltms_vendor_dashboard] — Vendor SPA dashboard
   - [ltms_vendor_login] — Vendor login form
   - [ltms_vendor_register] — Vendor registration form

== Requirements ==

* WordPress 6.3 or higher
* WooCommerce 8.0 or higher
* PHP 8.1 or higher
* MySQL 8.0 or higher
* PHP extensions: openssl, bcmath, intl, mbstring

== Frequently Asked Questions ==

= Does it work without Openpay? =

Yes. The wallet and order management features work without payment gateway integration.
You can manually process payouts.

= Is it compatible with other vendor plugins? =

LTMS is designed as a standalone solution. Running alongside other multi-vendor plugins
(Dokan, WCFM, etc.) is not recommended and may cause conflicts.

= What currencies are supported? =

Colombian Pesos (COP) and Mexican Pesos (MXN). The currency is set in Settings > General.

= How does the KYC process work? =

1. Vendor submits identity documents through their dashboard
2. Admin reviews documents in LT Marketplace > KYC / Documentos
3. Admin approves or rejects with reason
4. Approved vendors unlock wallet withdrawal functionality

== Screenshots ==

1. Vendor Dashboard — KPIs and sales chart
2. Wallet view with transaction history
3. Admin payout management
4. KYC document review
5. Tax reports with fiscal compliance data
6. MLM referral network tree

== Changelog ==

= 1.5.0 =
* Initial enterprise release
* Multi-vendor marketplace with ACID wallet ledger
* Colombia & Mexico tax compliance
* MLM 3-level referral network
* KYC verification workflow
* PWA vendor dashboard
* Openpay, Siigo, Addi, Aveonline, ZapSign, TPTC, XCover integrations
* AES-256 encryption and WAF security
* SAGRILAFT compliance logging

== Upgrade Notice ==

= 1.5.0 =
Initial enterprise release. Review wp-config-sample-snippet.php for required constants.
Run database migrations from LT Marketplace > Configuración after activation.
