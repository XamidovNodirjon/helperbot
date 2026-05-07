<?php

require 'vendor/autoload.php';

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Bootstrap Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$scraper = new \App\Services\OlxScraperService();

// Test URL
$url = 'https://www.olx.uz/oz/nedvizhimost/kvartiry/arenda-dolgosrochnaya/tashkent/?search%5Bfilter_float_total_area%3Afrom%5D=50&search%5Bfilter_float_total_area%3Ato%5D=100&view=list';

try {
    $response = Http::withHeaders([
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    ])->timeout(20)->get($url);

    if ($response->successful()) {
        $html = $response->body();
        echo "✓ Fetched HTML (" . strlen($html) . " bytes)\n";
        
        // Save to file for inspection
        file_put_contents('debug_olx.html', $html);
        echo "✓ Saved to debug_olx.html\n";

        // Test extraction patterns
        
        // Pattern 1: data-cy="l-card"
        preg_match_all('#<div[^>]*data-cy="l-card"[^>]*>#i', $html, $m);
        echo "\n1. data-cy='l-card' found: " . count($m[0]) . "\n";
        
        // Pattern 2: class="l-card"
        preg_match_all('#<div[^>]*class="[^"]*l-card[^"]*"[^>]*>#i', $html, $m);
        echo "2. class='l-card' found: " . count($m[0]) . "\n";
        
        // Pattern 3: OLX links in nedvizhimost
        preg_match_all('#href="(https://www\.olx\.uz/oz/nedvizhimost/[^"]+)"#i', $html, $m);
        echo "3. OLX nedvizhimost links found: " . count($m[1]) . "\n";
        if (!empty($m[1])) {
            echo "   First few: " . implode(", ", array_slice($m[1], 0, 3)) . "\n";
        }
        
        // Pattern 4: Links with ID patterns
        preg_match_all('#href="(https://www\.olx\.uz/oz/nedvizhimost/[^"]*?/\d+\.html)"#i', $html, $m);
        echo "4. Links with /ID.html pattern: " . count($m[1]) . "\n";
        if (!empty($m[1])) {
            echo "   First few: " . implode(", ", array_slice($m[1], 0, 3)) . "\n";
        }
        
        // Pattern 5: JSON-LD
        preg_match_all('#<script type="application/ld\+json"[^>]*>#i', $html, $m);
        echo "5. JSON-LD scripts found: " . count($m[0]) . "\n";
        
        // Pattern 6: __NEXT_DATA__
        preg_match('#<script id="__NEXT_DATA__"[^>]*>#i', $html, $m);
        echo "6. __NEXT_DATA__ found: " . (count($m) > 0 ? "Yes" : "No") . "\n";
        
        // Sample some HTML content
        echo "\n--- HTML Sample (first 2000 chars) ---\n";
        echo substr($html, 0, 2000) . "\n";
        
        // Check for actual listings structure
        if (preg_match('#<div[^>]*class="[^"]*listingCard[^"]*"[^>]*>(.*?)</div>#is', $html, $m)) {
            echo "\n--- Found listingCard structure\n";
        }
        
        if (preg_match('#<article[^>]*>(.*?)</article>#is', $html, $m)) {
            echo "--- Found article tags\n";
        }
        
    } else {
        echo "✗ Failed to fetch: " . $response->status() . "\n";
    }
} catch (\Throwable $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
