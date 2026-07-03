<?php
if ( ! defined( 'ABSPATH' ) ) exit;
// LTMS Auditor Dashboard View
?>
<div class="wrap ltms-auditor-wrap">
    <h1><?php esc_html_e( 'Panel Auditor LTMS', 'lt-marketplace-suite' ); ?></h1>
    <div id="ltms-auditor-dashboard">
        <?php do_action( 'ltms_auditor_dashboard_content' ); ?>
    </div>
</div>
