<?php
/**
 * Fix ltms_referral_rates - forzar valor limpio
 * wp eval-file bin/fix-referral-rates.php --path=/home/customer/www/lo-tengo.com.co/public_html
 */
// Forzar string limpio directamente, sin pasar por json_encode (que genera float artifacts)
$clean = '[0.05,0.02]';
update_option('ltms_referral_rates', $clean);
$verify = get_option('ltms_referral_rates');
echo "ltms_referral_rates = " . $verify . "\n";
$ok = ($verify === $clean);
echo $ok ? "✅ OK\n" : "❌ STILL WRONG\n";
