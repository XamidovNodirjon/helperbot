<?php

namespace App\Console\Commands;

use App\Services\OlxScraperService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:test-olx-scraper')]
#[Description('Command description')]
class TestOlxScraper extends Command
{
    /**
     * Execute the console command.
     */

       public function handle(): int
    {
        $scraper = new OlxScraperService();
        
        $filters = [
            'mode' => 'ijara',
            'property_type' => 'uy',
            'region_name' => 'Toshkent shahri',
            'district_name' => 'Chilonzor',
            'sqm_min' => 40,
            'sqm_max' => 100,
            'price_min' => 1000000,
            'price_max' => 3000000,
            'currency' => 'uzs',
        ];

        $this->info('Qidiruv boshlanmoqda...');
        $result = $scraper->search($filters);

        $this->info("Topildi: " . count($result['listings']) . " ta");
        $this->info("URL: " . $result['searchUrl']);

        foreach (array_slice($result['listings'], 0, 3) as $listing) {
            $this->line("---");
            $this->line("Sarlavha: " . $listing['title']);
            $this->line("Narx: " . $listing['price']);
            $this->line("Joy: " . $listing['location']);
            $this->line("URL: " . $listing['url']);
        }

        return self::SUCCESS;
    }
}
