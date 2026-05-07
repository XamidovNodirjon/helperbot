<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\OlxScraperService;

$service = new OlxScraperService();
$url = 'https://www.olx.uz/oz/nedvizhimost/kommercheskie-pomeshcheniya/arenda/tashkent/?currency=USD&search%5Bfilter_float_price:from%5D=200&search%5Bfilter_float_price:to%5D=500&search%5Bdistrict_id%5D=7&search%5Bfilter_float_total_area:from%5D=40&search%5Bfilter_float_total_area:to%5D=100&search%5Bfilter_enum_premise_type%5D%5B0%5D=4&view=list';
$html = file_get_contents($url);
if ($html === false) {
    echo "Fetch failed\n";
    exit(1);
}

$ref = new ReflectionMethod(OlxScraperService::class, 'parse');
$ref->setAccessible(true);
$listings = $ref->invoke($service, $html);

echo "Parsed count: " . count($listings) . "\n";
foreach (array_slice($listings, 0, 5) as $i => $listing) {
    echo ($i+1) . ". " . ($listing['title'] ?? 'NO TITLE') . " | " . ($listing['price'] ?? 'NO PRICE') . " | " . ($listing['location'] ?? 'NO LOC') . "\n";
}
