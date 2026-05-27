<?php
if (!defined('ABSPATH')) { define('ABSPATH','/home/customer/www/lo-tengo.com.co/public_html/'); require ABSPATH.'wp-load.php'; }
$qa=[];
function qp(&$q,$n,$d=''){$q[]=['P',$n,$d];echo "  ✅ PASS  $n".($d?" — $d":'')."\n";}
function qf(&$q,$n,$d=''){$q[]=['F',$n,$d];echo "  ❌ FAIL  $n".($d?" — $d":'')."\n";}
function qw(&$q,$n,$d=''){$q[]=['W',$n,$d];echo "  ⚠️  WARN  $n".($d?" — $d":'')."\n";}
echo "\n══════════════════════════════════════════════════\n";
echo "  T-01 · Clases PHP\n";
echo "══════════════════════════════════════════════════\n";
class_exists('LTMS_Api_Uber')?qp($qa,'LTMS_Api_Uber existe'):qf($qa,'LTMS_Api_Uber NO encontrada');
echo "\n══════════════════════════════════════════════════\n";
echo "  T-02 · Credenciales en BD\n";
echo "══════════════════════════════════════════════════\n";
$cid=get_option('ltms_uber_direct_client_id','');
$cs=get_option('ltms_uber_direct_client_secret','');
$cust=get_option('ltms_uber_direct_customer_id','');
$cid?qp($qa,'Client ID','✓ '.strlen($cid).' chars'):qf($qa,'Client ID VACÍO (C-01)');
$cs?qp($qa,'Client Secret','✓ cifrado'):qf($qa,'Client Secret VACÍO (C-01)');
$cust?qp($qa,'Customer ID','✓ '.$cust):qf($qa,'Customer ID VACÍO (C-01)');
echo "\n══════════════════════════════════════════════════\n";
echo "  T-03 · Scope OAuth2\n";
echo "══════════════════════════════════════════════════\n";
$src=file_get_contents(WP_PLUGIN_DIR.'/lt-marketplace-suite/includes/api/class-ltms-api-uber.php');
strpos($src,'direct.organizations')!==false?qp($qa,'Scope correcto','direct.organizations ✅'):qf($qa,'Scope INCORRECTO','aún tiene eats.deliveries');
echo "\n══════════════════════════════════════════════════\n";
echo "  T-04 · health_check()\n";
echo "══════════════════════════════════════════════════\n";
if(!$cid||!$cs||!$cust){qw($qa,'health_check() omitido','C-01: credenciales vacías');}
elseif(class_exists('LTMS_Api_Uber')){
  try{$u=LTMS_Api_Factory::get('uber');$h=$u->health_check();
  (!empty($h['connected'])&&$h['connected'])?qp($qa,'health_check()','connected=true ✅'):qf($qa,'health_check()',($h['message']??$h['error']??json_encode($h)));
  }catch(\Throwable $e){qf($qa,'health_check() excepción',$e->getMessage());}
}
echo "\n══════════════════════════════════════════════════\n";
echo "  RESUMEN QA — Uber Direct\n";
echo "══════════════════════════════════════════════════\n";
$p=count(array_filter($qa,fn($r)=>$r[0]==='P'));
$f=count(array_filter($qa,fn($r)=>$r[0]==='F'));
$w=count(array_filter($qa,fn($r)=>$r[0]==='W'));
echo "  ✅ PASS : $p\n  ❌ FAIL : $f\n  ⚠️  WARN : $w\n  TOTAL  : ".($p+$f+$w)."\n\n";
if($f===0)echo "  🎉 Sin fallos críticos.\n";
else{echo "  🔴 Fallos:\n";foreach($qa as $r)if($r[0]==='F')echo "     · {$r[1]}".($r[2]?" — {$r[2]}":'')."\n";}
echo "\n";
