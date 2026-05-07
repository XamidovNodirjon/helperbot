<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\OlxScraperService;

$service = new OlxScraperService();
$filters = [
    'mode' => 'ijara',
    'property_type' => 'ofis',
    'region_name' => 'Toshkent shahri',
];

$ref = new ReflectionMethod(OlxScraperService::class, 'buildUrl');
$ref->setAccessible(true);
$url = $ref->invoke($service, $filters);
echo "Region URL: $url\n";
$html = file_get_contents($url);

$parseRef = new ReflectionMethod(OlxScraperService::class, 'parse');
$parseRef->setAccessible(true);
$listings = $parseRef->invoke($service, $html);
echo "Parsed region count: " . count($listings) . "\n";
foreach (array_slice($listings, 0, 5) as $i => $listing) {
    echo ($i+1) . ". " . ($listing['title'] ?? 'NO TITLE') . " | " . ($listing['price'] ?? 'NO PRICE') . " | " . ($listing['location'] ?? 'NO LOC') . "\n";
}
