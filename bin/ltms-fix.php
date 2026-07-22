<?php
echo "=== Fix Alegra commission_item_id ===\n\n";
$plugin = WP_CONTENT_DIR . '/plugins/lt-marketplace-suite/';
$f = $plugin . 'includes/business/class-ltms-alegra-sync.php';
$c = file_get_contents($f); $orig = $c;
$c = str_replace(
    "\$item_id = (int) LTMS_Core_Config::get( 'ltms_alegra_commission_item_id', 0 );",
    "\$item_id = (int) get_option( 'ltms_alegra_commission_item_id', 0 ); // sin cache",
    $c
);
$c = str_replace(
    "LTMS_Core_Config::set( 'ltms_alegra_commission_item_id', \$item_id );",
    "update_option( 'ltms_alegra_commission_item_id', \$item_id );",
    $c
);
if ($c !== $orig) {
    file_put_contents($f, $c);
    if (function_exists('opcache_invalidate')) opcache_invalidate($f, true);
    echo "OK: sync.php parcheado\n";
} else {
    echo "INFO: sin cambios, mostrando lineas:\n";
}
foreach (explode("\n",$c) as $i=>$l)
    if (strpos($l,'commission_item_id')!==false)
        echo "  L".($i+1).": ".trim($l)."\n";

$alegra = LTMS_Api_Factory::get('alegra');
try {
    $item = $alegra->create_item(['name'=>'Comision Marketplace Lo Tengo','price'=>0,'type'=>'service']);
    $id = (int)($item['id'] ?? 0);
    if ($id) {
        update_option('ltms_alegra_commission_item_id', $id);
        echo "\nOK: item_id=$id en BD. get_option=".get_option('ltms_alegra_commission_item_id')."\n";
    } else { echo "\nSIN ID: ".json_encode($item)."\n"; }
} catch (\Throwable $e) {
    $id = (int) get_option('ltms_alegra_commission_item_id', 0);
    echo "\nWARN: ".$e->getMessage()." — BD=$id\n";
}
update_option('ltms_alegra_active','yes');
echo "Alegra activo: ".get_option('ltms_alegra_active')."\n";
$contact = (int) get_user_meta(18,'_ltms_alegra_contact_id',true);
$item_bd = (int) get_option('ltms_alegra_commission_item_id',0);
echo "\nTest: item=$item_bd contact=$contact\n";
if ($item_bd && $contact) {
    try {
        $inv = $alegra->create_invoice(['date'=>date('Y-m-d'),'due_date'=>date('Y-m-d'),'client_id'=>$contact,'items'=>[['id'=>$item_bd,'quantity'=>1,'price'=>15000.0]],'observations'=>'Test fix']);
        echo "OK: factura ID=".($inv['id']??'?')."\n";
    } catch (\Throwable $e) { echo "FAIL: ".$e->getMessage()."\n"; }
}
chdir($plugin);
system('git add includes/business/class-ltms-alegra-sync.php');
system('git -c user.email=dircomercialcol@lo-tengo.com.co -c user.name="LTMS Bot" commit -m "fix(alegra): get_option directo en prepare_commission_items"');
system('git remote set-url origin https://jglotengo:GITHUB_TOKEN_REMOVED@github.com/jglotengo/lt-marketplace-suite.git');
system('git push origin main && echo PUSH_OK');
echo "\n=== DONE ===\n";
