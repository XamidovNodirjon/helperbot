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
    'district_id' => 2,
    'district_name' => 'Mirzo Ulug‘bek tumani',
    'sqm_min' => 40,
    'sqm_max' => 100,
    'currency' => 'usd',
    'price_min' => 200,
    'price_max' => 500,
];

$result = $service->search($filters);
var_export(['count' => count($result['listings']), 'searchUrl' => $result['searchUrl'], 'note' => $result['searchNote']]);
echo "\n";
foreach (array_slice($result['listings'], 0, 5) as $r) {
    echo "- {$r['title']} | {$r['price']} | {$r['location']} | {$r['url']}\n";
}
