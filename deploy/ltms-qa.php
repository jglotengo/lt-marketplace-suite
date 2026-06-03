<?php
if (($_GET['t'] ?? '') !== 'ltms_qa_2026') { http_response_code(403); exit; }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(60);
error_reporting(0);
define('SHORTINIT', true);
require_once __DIR__ . '/wp-load.php';
global $wpdb;

echo "=== LTMS KYC FIX + B2 TEST ===\n\n";

// Fix 1: Set missing kyc bucket
$current = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name='ltms_backblaze_kyc_bucket'");
echo "ltms_backblaze_kyc_bucket actual: " . ($current ?: '(VACIO)') . "\n";

if (empty($current)) {
    $wpdb->replace($wpdb->options, ['option_name'=>'ltms_backblaze_kyc_bucket','option_value'=>'lotengo-kyc-docs','autoload'=>'yes']);
    echo "  -> FIXED: seteado a 'lotengo-kyc-docs'\n";
} else {
    echo "  -> OK (ya tenia valor)\n";
}

// Fix 2: Set private bucket if missing
$priv = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name='ltms_backblaze_private_bucket'");
if (empty($priv)) {
    $wpdb->replace($wpdb->options, ['option_name'=>'ltms_backblaze_private_bucket','option_value'=>'lotengo-kyc-docs','autoload'=>'yes']);
    echo "ltms_backblaze_private_bucket -> FIXED: 'lotengo-kyc-docs'\n";
}

// Fix 3: Set default bucket if missing  
$def = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name='ltms_backblaze_default_bucket'");
if (empty($def)) {
    $wpdb->replace($wpdb->options, ['option_name'=>'ltms_backblaze_default_bucket','option_value'=>'lotengo-contratos','autoload'=>'yes']);
    echo "ltms_backblaze_default_bucket -> FIXED: 'lotengo-contratos'\n";
}

echo "\n=== B2 DIRECT UPLOAD TEST (S3 API) ===\n";

$endpoint = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name='ltms_backblaze_endpoint'");
$key_id   = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name='ltms_backblaze_key_id'");
$app_key  = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name='ltms_backblaze_app_key'");
$bucket   = 'lotengo-kyc-docs';
$region   = 'us-east-005';

echo "Endpoint: {$endpoint}\n";
echo "Key ID: " . substr($key_id,0,10) . "...\n";
echo "Bucket: {$bucket}\n\n";

// Test B2 via S3-compatible API with AWS Signature V4
$object_key = 'kyc/qa-test/' . time() . '_test.png';
$png_data = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
$content_type = 'image/png';
$date_time = gmdate('Ymd\THis\Z');
$date_stamp = gmdate('Ymd');
$host = parse_url($endpoint, PHP_URL_HOST);

// AWS Sig V4
$canonical_uri = '/' . $bucket . '/' . $object_key;
$canonical_headers = "content-type:{$content_type}\nhost:{$host}\nx-amz-date:{$date_time}\n";
$signed_headers = 'content-type;host;x-amz-date';
$payload_hash = hash('sha256', $png_data);
$canonical_request = "PUT\n{$canonical_uri}\n\n{$canonical_headers}\n{$signed_headers}\n{$payload_hash}";
$credential_scope = "{$date_stamp}/{$region}/s3/aws4_request";
$string_to_sign = "AWS4-HMAC-SHA256\n{$date_time}\n{$credential_scope}\n" . hash('sha256', $canonical_request);
$signing_key = hash_hmac('sha256', 'aws4_request',
    hash_hmac('sha256', 's3',
        hash_hmac('sha256', $region,
            hash_hmac('sha256', $date_stamp, 'AWS4' . $app_key, true), true), true), true);
$signature = hash_hmac('sha256', $string_to_sign, $signing_key);
$auth = "AWS4-HMAC-SHA256 Credential={$key_id}/{$credential_scope},SignedHeaders={$signed_headers},Signature={$signature}";

$url = $endpoint . '/' . $bucket . '/' . $object_key;
$ctx = stream_context_create(['http'=>[
    'method'=>'PUT',
    'header'=>"Authorization: {$auth}\r\nContent-Type: {$content_type}\r\nx-amz-date: {$date_time}\r\nContent-Length: " . strlen($png_data),
    'content'=>$png_data,
    'timeout'=>20,
    'ignore_errors'=>true,
]]);
$resp = @file_get_contents($url, false, $ctx);
$code = 0;
if (isset($http_response_header[0])) {
    preg_match('/HTTP\/\S+ (\d+)/', $http_response_header[0], $m);
    $code = (int)($m[1]??0);
}
echo "PUT {$object_key}\n";
echo "HTTP: {$code}\n";
if ($code >= 200 && $code < 300) {
    echo "UPLOAD OK ✓\n";
    // Cleanup
    $date_time2 = gmdate('Ymd\THis\Z');
    $date_stamp2 = gmdate('Ymd');
    $del_cr = "DELETE\n{$canonical_uri}\n\nhost:{$host}\nx-amz-date:{$date_time2}\n\nhost;x-amz-date\n" . hash('sha256','');
    $del_sts = "AWS4-HMAC-SHA256\n{$date_time2}\n{$date_stamp2}/{$region}/s3/aws4_request\n" . hash('sha256',$del_cr);
    $del_sk = hash_hmac('sha256','aws4_request',hash_hmac('sha256','s3',hash_hmac('sha256',$region,hash_hmac('sha256',$date_stamp2,'AWS4'.$app_key,true),true),true),true);
    $del_sig = hash_hmac('sha256',$del_sts,$del_sk);
    $del_auth = "AWS4-HMAC-SHA256 Credential={$key_id}/{$date_stamp2}/{$region}/s3/aws4_request,SignedHeaders=host;x-amz-date,Signature={$del_sig}";
    $del_ctx = stream_context_create(['http'=>['method'=>'DELETE','header'=>"Authorization: {$del_auth}\r\nx-amz-date: {$date_time2}"]]);
    @file_get_contents($url, false, $del_ctx);
    echo "Cleanup OK ✓\n";
} else {
    echo "UPLOAD FAILED\n";
    echo "Response: " . substr($resp,0,300) . "\n";
}

echo "\n=== DONE ===\n";
