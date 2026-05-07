<?php
/**
 * OLX sahifasining HTML strukturasini tekshirish.
 * Ishga tushirish: php scratch/check_olx.php
 */

$url = 'https://www.olx.uz/oz/nedvizhimost/kommercheskie-pomeshcheniya/arenda/tashkent/?currency=UZS&search%5Bfilter_float_price:from%5D=1000000&search%5Bfilter_float_price:to%5D=10000000&search%5Bdistrict_id%5D=7&search%5Bfilter_float_total_area:from%5D=40&search%5Bfilter_float_total_area:to%5D=100&search%5Bfilter_enum_premise_type%5D%5B0%5D=4&view=list';

echo "URL: $url\n\n";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    'Accept-Encoding: gzip, deflate, br',
]);
curl_setopt($ch, CURLOPT_ENCODING, '');
$html = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Status: $status\n";
echo "HTML length: " . strlen($html) . "\n\n";

// 1. LD+JSON check
preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $ldMatches);
echo "=== LD+JSON scripts found: " . count($ldMatches[1]) . " ===\n";
foreach ($ldMatches[1] as $i => $json) {
    $data = json_decode($json, true);
    if ($data) {
        $type = $data['@type'] ?? 'unknown';
        echo "  #{$i} type: {$type}\n";
        if ($type === 'Product' && isset($data['offers']['offers'])) {
            echo "    offers count: " . count($data['offers']['offers']) . "\n";
            $first = $data['offers']['offers'][0] ?? null;
            if ($first) {
                echo "    first offer: " . json_encode(array_keys($first)) . "\n";
                echo "    first name: " . ($first['name'] ?? '?') . "\n";
                echo "    first url: " . ($first['url'] ?? '?') . "\n";
            }
        }
    } else {
        echo "  #{$i} JSON parse error: " . json_last_error_msg() . "\n";
        echo "  first 200 chars: " . substr($json, 0, 200) . "\n";
    }
}

// 2. __NEXT_DATA__ check
echo "\n=== __NEXT_DATA__ ===\n";
if (preg_match('#<script id="__NEXT_DATA__"[^>]*>(.*?)</script>#s', $html, $nextMatch)) {
    echo "Found, length: " . strlen($nextMatch[1]) . "\n";
    $next = json_decode($nextMatch[1], true);
    if ($next) {
        // Check different paths for ads
        $paths = [
            'props.pageProps.ads',
            'props.pageProps.data.ads',
            'props.pageProps.listing.ads',
            'props.pageProps.hydraData.searchAds',
        ];
        foreach ($paths as $path) {
            $parts = explode('.', $path);
            $val = $next;
            foreach ($parts as $p) {
                $val = $val[$p] ?? null;
                if ($val === null) break;
            }
            echo "  {$path}: " . ($val ? count($val) . " items" : "null") . "\n";
        }
        // Show available keys
        $ppKeys = array_keys($next['props']['pageProps'] ?? []);
        echo "  pageProps keys: " . implode(', ', $ppKeys) . "\n";
    }
} else {
    echo "Not found\n";
}

// 3. data-cy / data-testid cards
echo "\n=== HTML Cards ===\n";
preg_match_all('#data-cy=["\']l-card["\']#', $html, $cyCards);
echo "data-cy=l-card: " . count($cyCards[0]) . "\n";

preg_match_all('#data-testid=["\']l-card["\']#', $html, $testCards);
echo "data-testid=l-card: " . count($testCards[0]) . "\n";

// 4. Ad links
echo "\n=== Ad links ===\n";
preg_match_all('#href="(/d/oz/obyavlenie/[^"]+)"#', $html, $adLinks);
echo "Ad links (/d/oz/obyavlenie/): " . count($adLinks[1]) . "\n";
foreach (array_slice($adLinks[1], 0, 5) as $link) {
    echo "  -> $link\n";
}

// 5. Show context around first ad link (if any)
if (!empty($adLinks[1])) {
    $pos = strpos($html, $adLinks[1][0]);
    if ($pos !== false) {
        echo "\n=== Context around first ad link (500 chars before, 500 after): ===\n";
        echo substr($html, max(0, $pos - 500), 1200) . "\n";
    }
}

// 6. Check for listing card class patterns
echo "\n=== Other patterns ===\n";
preg_match_all('#class="[^"]*listing[^"]*"#i', $html, $listingClasses);
echo "class containing 'listing': " . count($listingClasses[0]) . "\n";
if (!empty($listingClasses[0])) {
    foreach (array_unique(array_slice($listingClasses[0], 0, 5)) as $cls) {
        echo "  $cls\n";
    }
}

preg_match_all('#data-testid="([^"]*ad[^"]*)"#i', $html, $adTestIds);
echo "data-testid with 'ad': " . count($adTestIds[0]) . "\n";
foreach (array_unique(array_slice($adTestIds[1], 0, 10)) as $tid) {
    echo "  $tid\n";
}
