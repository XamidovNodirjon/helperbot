<?php
$urls = [
    'https://www.olx.uz/oz/nedvizhimost/kommercheskie-pomeshcheniya/arenda/tashkent/?currency=UZS&search%5Bfilter_float_price:from%5D=200&search%5Bfilter_float_price:to%5D=500&search%5Bdistrict_id%5D=7&search%5Bfilter_float_total_area:from%5D=40&search%5Bfilter_float_total_area:to%5D=100&search%5Bfilter_enum_premise_type%5D%5B0%5D=4&view=list',
    'https://www.olx.uz/oz/nedvizhimost/kommercheskie-pomeshcheniya/arenda/tashkent/?currency=USD&search%5Bfilter_float_price:from%5D=200&search%5Bfilter_float_price:to%5D=500&search%5Bdistrict_id%5D=7&search%5Bfilter_float_total_area:from%5D=40&search%5Bfilter_float_total_area:to%5D=100&search%5Bfilter_enum_premise_type%5D%5B0%5D=4&view=list',
    'https://www.olx.uz/oz/nedvizhimost/kommercheskie-pomeshcheniya/arenda/tashkent/?currency=UYE&search%5Bfilter_float_price:from%5D=200&search%5Bfilter_float_price:to%5D=500&search%5Bdistrict_id%5D=7&search%5Bfilter_float_total_area:from%5D=40&search%5Bfilter_float_total_area:to%5D=100&search%5Bfilter_enum_premise_type%5D%5B0%5D=4&view=list',
];
foreach ($urls as $u) {
    $html = file_get_contents($u);
    echo "URL: $u\n";
    echo "Length: " . strlen($html) . "\n";
    echo "Dollar signs: " . substr_count($html, '$') . "\n";
    echo "USD words: " . substr_count(strtolower($html), 'usd') . "\n";
    echo "UZS words: " . substr_count(strtolower($html), 'uzs') . "\n";
    echo "\n";
}
