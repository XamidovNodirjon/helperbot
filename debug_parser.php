<?php

require 'vendor/autoload.php';

$html = file_get_contents('debug_olx.html');

// Test the exact regex pattern
echo "Testing exact regex pattern used in parseHtmlSelectors:\n\n";

preg_match_all(
    '#<div[^>]*data-cy="l-card"[^>]*data-testid="l-card"[^>]*id="(\d+)"[^>]*>(.*?)</div>\s*</div>#is',
    $html,
    $cards
);

echo "Pattern 1 (data-cy + data-testid + id): " . count($cards[2]) . " found\n";

if (count($cards[2]) > 0) {
    echo "\nAnalyzing first card:\n";
    $card = $cards[2][0];
    echo "Card length: " . strlen($card) . " chars\n";
    
    // Try to find link
    if (preg_match(
        '#<a[^>]*class="[^"]*css-1tqlkj0[^"]*"[^>]*href="(/d/oz/[^"]+)"[^>]*>(.*?)</a>#is',
        $card, $linkMatch
    )) {
        echo "✓ Link found!\n";
        echo "  URL: " . $linkMatch[1] . "\n";
        echo "  Title HTML length: " . strlen($linkMatch[2]) . "\n";
        
        $title = strip_tags(html_entity_decode($linkMatch[2], ENT_QUOTES, 'UTF-8'));
        $title = trim(preg_replace('/\s+/', ' ', $title));
        echo "  Title: " . substr($title, 0, 100) . "\n";
    } else {
        echo "✗ Link NOT found in card\n";
        echo "\nFirst 500 chars of card:\n";
        echo substr($card, 0, 500) . "\n";
    }
}

// Try alternative pattern
echo "\n\nTrying alternative pattern without data-testid:\n";
preg_match_all(
    '#<div[^>]*data-cy="l-card"[^>]*>(.*?)</div>#is',
    $html,
    $cards2
);

echo "Pattern 2 (just data-cy): " . count($cards2[1]) . " found\n";

if (count($cards2[1]) > 0) {
    echo "\nLooking for links in first card...\n";
    
    preg_match_all(
        '#href="(/d/oz/[^"]+)"[^>]*>([^<]{5,200})<#is',
        $cards2[1][0],
        $links
    );
    
    echo "Links found: " . count($links[1]) . "\n";
    if (count($links[1]) > 0) {
        echo "First link: " . $links[1][0] . "\n";
        echo "First link text: " . substr($links[2][0], 0, 100) . "\n";
    }
}
