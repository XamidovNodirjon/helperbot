<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$filters = [
    'mode' => 'ijara',
    'property_type' => 'ofis',
    'region_name' => 'Toshkent shahri',
    'district_id' => 7,
    'district_name' => 'Mirzo Ulugbek',
    'sqm_min' => 40,
    'sqm_max' => 100,
    'currency' => 'usd',
    'price_min' => 200,
    'price_max' => 500,
];

$s = new App\Services\OlxScraperService();
$method = new ReflectionMethod(App\Services\OlxScraperService::class, 'buildUrl');
$method->setAccessible(true);
echo $method->invoke($s, $filters) . PHP_EOL;
