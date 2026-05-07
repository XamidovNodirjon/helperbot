<?php
function fetch($url) {
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
    return [$status, $html];
}

$url = 'https://www.olx.uz/nedvizhimost/kommercheskie-pomeshcheniya/arenda/tashkent/?currency=UZS&search%5Bfilter_enum_currency%5D%5B0%5D=UZS&search%5Bfilter_enum_premise_type%5D%5B0%5D=4&view=list';
[$status, $html] = fetch($url);
echo "Status: $status\n";
echo "Length: " . strlen($html) . "\n";
if (preg_match('#<title>(.*?)</title>#is', $html, $m)) {
    echo "Title: " . trim($m[1]) . "\n";
}

preg_match_all('#href="(/d/oz/obyavlenie/[^"]+)"#', $html, $links);
echo "Ad links: " . count($links[1]) . "\n";
if ($links[1]) {
    echo "First: " . $links[1][0] . "\n";
}
