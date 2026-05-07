<?php

$url = 'https://www.olx.uz/oz/nedvizhimost/kvartiry/arenda-dolgosrochnaya/tashkent/';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept-Language: uz-UZ,uz;q=0.9,ru;q=0.8',
    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);

$html = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Status: $status\n";

// Find the first data-cy="l-card"
$pos = strpos($html, 'data-cy="l-card"');
if ($pos !== false) {
    // Find the end of the card
    $cardHtml = substr($html, $pos, 2000);
    $endPos = strpos($cardHtml, '</div></div></div>');
    if ($endPos === false) {
        $endPos = strpos($cardHtml, '</div></div>');
    }
    if ($endPos !== false) {
        $cardHtml = substr($cardHtml, 0, $endPos + 20);
    }
    echo "Card HTML:\n$cardHtml\n";
} else {
    echo "No data-cy=\"l-card\" found\n";
}