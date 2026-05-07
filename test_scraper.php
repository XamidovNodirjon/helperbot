<?php

// Simple test without Laravel
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

// Check for ad links
preg_match_all('#href="(/d/oz/obyavlenie/[^"]+)"#', $html, $adLinks);
echo "Ad links found: " . count($adLinks[1]) . "\n";
if (!empty($adLinks[1])) {
    for ($i = 0; $i < min(5, count($adLinks[1])); $i++) {
        echo "  https://www.olx.uz" . $adLinks[1][$i] . "\n";
    }
}

// Check for titles near links
preg_match_all('#<a[^>]*href="/d/oz/obyavlenie/[^"]*"[^>]*>(.*?)</a>#is', $html, $titles);
echo "Titles found: " . count($titles[1]) . "\n";
if (!empty($titles[1])) {
    for ($i = 0; $i < min(3, count($titles[1])); $i++) {
        echo "  " . strip_tags($titles[1][$i]) . "\n";
    }
}