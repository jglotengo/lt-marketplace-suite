/**
 * LTMS E2E Tests - Vendor Checkout Flow
 * Cypress end-to-end tests for the vendor onboarding and checkout flows.
 *
 * Prerequisites:
 *   - WordPress running at WP_BASE_URL (default: http://localhost:8080)
 *   - WooCommerce active with test products
 *   - LTMS plugin active with sandbox payment gateways
 *
 * Run: npx cypress run --spec "tests/e2e/vendor-checkout.spec.js"
 *
 * @version 1.5.0
 */

/* global describe, beforeEach, it, cy, expect, Cypress */

const WP_BASE_URL = Cypress.env('WP_BASE_URL') || 'http://localhost:8080';

const SELECTORS = {
    // Auth
    loginUsername:  '#ltms-login-username',
    loginPassword:  '#ltms-login-password',
    loginBtn:       '#ltms-login-btn',
    loginNotice:    '#ltms-login-notice',
    registerForm:   '#ltms-register-form',

    // Register
    firstName:      '#ltms-reg-first-name',
    lastName:       '#ltms-reg-last-name',
    email:          '#ltms-reg-email',
    phone:          '#ltms-reg-phone',
    docType:        '#ltms-reg-document-type',
    docNumber:      '#ltms-reg-document-number',
    storeName:      '#ltms-reg-store-name',
    password:       '#ltms-reg-password',
    passwordConf:   '#ltms-reg-password-confirm',
    acceptTerms:    '[name="accept_terms"]',
    registerBtn:    '#ltms-register-btn',

    // Dashboard
    dashboard:      '.ltms-dashboard',
    navHome:        '[data-view="home"]',
    navWallet:      '[data-view="wallet"]',
    navOrders:      '[data-view="orders"]',
    balance:        '.ltms-wallet-balance',

    // Wallet
    payoutBtn:      '#ltms-payout-request-btn',
    payoutModal:    '#ltms-payout-modal',
    payoutAmount:   '#ltms-payout-amount',
    payoutMethod:   '#ltms-payout-method',
    payoutConfirm:  '#ltms-payout-confirm-btn',

    // Notifications
    notifBell:      '#ltms-notif-bell',
    notifPanel:     '.ltms-notifications-panel',
};

// ── Test Fixtures ──────────────────────────────────────────────────

const TEST_VENDOR = {
    first_name:    'Carlos',
    last_name:     'Rodríguez',
    email:         `vendor.test.${Date.now()}@ltms.test`,
    phone:         '3001234567',
    doc_type:      'CC',
    doc_number:    '1234567890',
    store_name:    `Tienda Test ${Date.now()}`,
    password:      'TestPass123!',
};

// ── Helpers ────────────────────────────────────────────────────────

function visitDashboard() {
    cy.visit(`${WP_BASE_URL}/ltms-dashboard/`);
    cy.get(SELECTORS.dashboard, { timeout: 10000 }).should('exist');
}

function loginVendor(email, password) {
    cy.visit(`${WP_BASE_URL}/ltms-login/`);
    cy.get(SELECTORS.loginUsername).type(email);
    cy.get(SELECTORS.loginPassword).type(password);
    cy.get(SELECTORS.loginBtn).click();
    cy.url({ timeout: 10000 }).should('include', 'ltms-dashboard');
}

// ── Test Suite: Registration ────────────────────────────────────────

describe('Vendor Registration', () => {

    beforeEach(() => {
        cy.visit(`${WP_BASE_URL}/ltms-registro/`);
        cy.get(SELECTORS.registerForm, { timeout: 5000 }).should('be.visible');
    });

    it('shows validation errors on empty submit', () => {
        // Try to proceed past step 1 without filling in
        cy.get('.ltms-wizard-next[data-next="2"]').click();
        cy.get(SELECTORS.firstName + ':invalid').should('exist');
    });

    it('completes multi-step registration successfully', () => {
        // Step 1: Personal data
        cy.get(SELECTORS.firstName).type(TEST_VENDOR.first_name);
        cy.get(SELECTORS.lastName).type(TEST_VENDOR.last_name);
        cy.get(SELECTORS.email).type(TEST_VENDOR.email);
        cy.get(SELECTORS.phone).type(TEST_VENDOR.phone);
        cy.get(SELECTORS.docType).select(TEST_VENDOR.doc_type);
        cy.get(SELECTORS.docNumber).type(TEST_VENDOR.doc_number);
        cy.get('.ltms-wizard-next[data-next="2"]').click();

        // Step 2: Store
        cy.get(SELECTORS.storeName).should('be.visible');
        cy.get(SELECTORS.storeName).type(TEST_VENDOR.store_name);
        cy.get('.ltms-wizard-next[data-next="3"]').click();

        // Step 3: Security
        cy.get(SELECTORS.password).should('be.visible');
        cy.get(SELECTORS.password).type(TEST_VENDOR.password);
        cy.get(SELECTORS.passwordConf).type(TEST_VENDOR.password);
        cy.get(SELECTORS.acceptTerms).check();

        cy.intercept('POST', '/wp-admin/admin-ajax.php').as('registerRequest');
        cy.get(SELECTORS.registerBtn).click();

        cy.wait('@registerRequest').then((interception) => {
            expect(interception.response.statusCode).to.equal(200);
            expect(interception.response.body.success).to.be.true;
        });

        // Should redirect to dashboard
        cy.url({ timeout: 15000 }).should('include', 'ltms-dashboard');
    });

    it('shows error for duplicate email', () => {
        const existingEmail = Cypress.env('EXISTING_VENDOR_EMAIL') || 'existing@test.com';

        cy.get(SELECTORS.firstName).type('Test');
        cy.get(SELECTORS.lastName).type('User');
        cy.get(SELECTORS.email).type(existingEmail);
        cy.get(SELECTORS.phone).type('3009999999');
        cy.get(SELECTORS.docType).select('CC');
        cy.get(SELECTORS.docNumber).type('9999999999');
        cy.get('.ltms-wizard-next[data-next="2"]').click();
        cy.get(SELECTORS.storeName).type('Duplicate Store');
        cy.get('.ltms-wizard-next[data-next="3"]').click();
        cy.get(SELECTORS.password).type('TestPass123!');
        cy.get(SELECTORS.passwordConf).type('TestPass123!');
        cy.get(SELECTORS.acceptTerms).check();
        cy.get(SELECTORS.registerBtn).click();

        cy.get('#ltms-register-notice').should('be.visible').and('have.class', 'ltms-notice-error');
    });

});

// ── Test Suite: Login ───────────────────────────────────────────────

describe('Vendor Login', () => {

    it('shows error for wrong credentials', () => {
        cy.visit(`${WP_BASE_URL}/ltms-login/`);
        cy.get(SELECTORS.loginUsername).type('wrong@email.com');
        cy.get(SELECTORS.loginPassword).type('wrongpassword');
        cy.get(SELECTORS.loginBtn).click();

        cy.get(SELECTORS.loginNotice, { timeout: 5000 })
            .should('be.visible')
            .and('have.class', 'ltms-notice-error');
    });

    it('blocks after 5 failed login attempts', () => {
        cy.visit(`${WP_BASE_URL}/ltms-login/`);

        for (let i = 0; i < 6; i++) {
            cy.get(SELECTORS.loginUsername).clear().type('brute@force.com');
            cy.get(SELECTORS.loginPassword).clear().type(`wrongpass${i}`);
            cy.get(SELECTORS.loginBtn).click();
        }

        cy.get(SELECTORS.loginNotice)
            .should('contain.text', 'Demasiados intentos');
    });

    it('logs in successfully and redirects to dashboard', () => {
        const email    = Cypress.env('TEST_VENDOR_EMAIL')    || 'test.vendor@ltms.test';
        const password = Cypress.env('TEST_VENDOR_PASSWORD') || 'TestPass123!';

        loginVendor(email, password);
        cy.get(SELECTORS.dashboard).should('be.visible');
    });

});

// ── Test Suite: Dashboard ───────────────────────────────────────────

describe('Vendor Dashboard', () => {

    beforeEach(() => {
        const email    = Cypress.env('TEST_VENDOR_EMAIL')    || 'test.vendor@ltms.test';
        const password = Cypress.env('TEST_VENDOR_PASSWORD') || 'TestPass123!';
        loginVendor(email, password);
    });

    it('displays KPI cards on home view', () => {
        cy.get('.ltms-metric').should('have.length.at.least', 3);
        cy.get('.ltms-metric-value').each(($el) => {
            cy.wrap($el).invoke('text').should('match', /^\$?[\d,\.]+/);
        });
    });

    it('navigates to wallet view', () => {
        cy.get(SELECTORS.navWallet).click();
        cy.get(SELECTORS.balance, { timeout: 5000 }).should('be.visible');
        cy.get('#ltms-wallet-tbody').should('exist');
    });

    it('navigates to orders view', () => {
        cy.get(SELECTORS.navOrders).click();
        cy.get('#ltms-orders-tbody', { timeout: 5000 }).should('exist');
    });

    it('opens and closes notification panel', () => {
        cy.get(SELECTORS.notifBell).click();
        cy.get(SELECTORS.notifPanel).should('be.visible');
        cy.get('body').click(10, 10); // Click outside
        cy.get(SELECTORS.notifPanel).should('not.be.visible');
    });

});

// ── Test Suite: Wallet & Payout ─────────────────────────────────────

describe('Wallet & Payout Flow', () => {

    beforeEach(() => {
        const email    = Cypress.env('TEST_VENDOR_EMAIL')    || 'test.vendor@ltms.test';
        const password = Cypress.env('TEST_VENDOR_PASSWORD') || 'TestPass123!';
        loginVendor(email, password);
        cy.get(SELECTORS.navWallet).click();
    });

    it('shows wallet balance', () => {
        cy.get(SELECTORS.balance, { timeout: 5000 }).should('be.visible');
    });

    it('opens payout modal', () => {
        cy.get(SELECTORS.payoutBtn).should('be.visible').click();
        cy.get(SELECTORS.payoutModal, { timeout: 3000 }).should('be.visible');
    });

    it('validates minimum payout amount', () => {
        cy.get(SELECTORS.payoutBtn).click();
        cy.get(SELECTORS.payoutAmount).type('100'); // Below minimum
        cy.get(SELECTORS.payoutMethod).select('bank_transfer');
        cy.get(SELECTORS.payoutConfirm).click();

        cy.get('.ltms-notice-error', { timeout: 3000 }).should('be.visible');
    });

    it('closes payout modal on Escape key', () => {
        cy.get(SELECTORS.payoutBtn).click();
        cy.get(SELECTORS.payoutModal).should('be.visible');
        cy.get('body').type('{esc}');
        cy.get(SELECTORS.payoutModal).should('not.be.visible');
    });

});

// ── Test Suite: PWA ─────────────────────────────────────────────────

describe('PWA Manifest & Service Worker', () => {

    it('serves manifest.json with correct fields', () => {
        cy.request(`${WP_BASE_URL}/wp-content/plugins/lt-marketplace-suite/assets/json/manifest.json`)
            .then((response) => {
                expect(response.status).to.equal(200);
                expect(response.body).to.have.property('name').that.includes('LT Marketplace');
                expect(response.body).to.have.property('start_url');
                expect(response.body).to.have.property('icons').that.is.an('array').with.length.at.least(3);
            });
    });

    it('dashboard page includes manifest link tag', () => {
        cy.visit(`${WP_BASE_URL}/ltms-dashboard/`);
        cy.get('link[rel="manifest"]').should('exist');
    });

});

// ── Test Suite: Security ────────────────────────────────────────────

describe('Security & WAF', () => {

    it('blocks SQL injection attempt in login form', () => {
        cy.visit(`${WP_BASE_URL}/ltms-login/`);
        cy.get(SELECTORS.loginUsername).type("admin' OR '1'='1");
        cy.get(SELECTORS.loginPassword).type('anything');
        cy.get(SELECTORS.loginBtn).click();

        cy.get(SELECTORS.loginNotice)
            .should('be.visible')
            .and('have.class', 'ltms-notice-error');

        // Should NOT be logged in
        cy.url().should('not.include', 'ltms-dashboard');
    });

    it('returns 403 for admin-ajax.php without nonce', () => {
        cy.request({
            method:           'POST',
            url:              `${WP_BASE_URL}/wp-admin/admin-ajax.php`,
            form:             true,
            body:             { action: 'ltms_get_wallet_data' },
            failOnStatusCode: false,
        }).then((response) => {
            expect(response.body).to.have.property('success', false);
        });
    });

});
