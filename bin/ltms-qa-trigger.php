<?php
// LTMS QA Trigger - ejecutar desde WP-CLI: wp eval-file bin/ltms-qa-trigger.php --allow-root
$plugin_dir = dirname(__DIR__);
chdir($plugin_dir);
// git pull
exec('git pull origin main 2>&1', $out, $ret);
echo implode("
", $out) . "
";
echo "Git pull exit: $ret
";
