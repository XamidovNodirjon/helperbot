<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\District;

$rows = District::select('id','name','olx_id')->orderBy('id')->limit(50)->get();
foreach ($rows as $row) {
    echo "{$row->id} | {$row->name} | ".($row->olx_id ?? 'NULL')."\n";
}
$hasNull = District::whereNull('olx_id')->count();
echo "NULL count: $hasNull\n";
$max = District::max('olx_id');
echo "max olx_id: $max\n";
