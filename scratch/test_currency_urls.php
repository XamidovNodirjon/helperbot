<?php
function fetch(string $url): void {
    echo "URL: $url\n";
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
    echo "Length: " . strlen($html) . "\n";
    preg_match('#<title>(.*?)</title>#is', $html, $m);
    echo "Title: " . ($m[1] ?? 'none') . "\n";
    echo "Found ads links: ";
    preg_match_all('#href="(/d/oz/obyavlenie/[^"]+)"#', $html, $links);
    echo count($links[1]) . "\n\n";
}

$base = 'https://www.olx.uz/oz/nedvizhimost/kommercheskie-pomeshcheniya/arenda/tashkent/';
$common = 'search%5Bfilter_float_price:from%5D=200&search%5Bfilter_float_price:to%5D=500&search%5Bdistrict_id%5D=7&search%5Bfilter_float_total_area:from%5D=40&search%5Bfilter_float_total_area:to%5D=100&search%5Bfilter_enum_premise_type%5D%5B0%5D=4&view=list';

fetch($base . '?currency=UZS&' . $common);
fetch($base . '?currency=USD&' . $common);
fetch($base . '?currency=UYE&' . $common);
