<?php

namespace App\Console\Commands;

use App\Models\District;
use App\Models\Region;
use Illuminate\Console\Command;

class ImportCitiesFromJson extends Command
{
    /**
     * Artisan command nomi.
     *  php artisan cities:import
     */
    protected $signature = 'cities:import {--fresh : Mavjud ma\'lumotlarni o\'chirib qayta import qilish}';

    protected $description = 'public/cities.json faylidan viloyat va tumanlarni DB ga import qiladi';

    public function handle(): int
    {
        $path = public_path('cities.json');

        if (!file_exists($path)) {
            $this->error("❌ Fayl topilmadi: $path");
            return self::FAILURE;
        }

        $data = json_decode(file_get_contents($path), true);

        if (empty($data)) {
            $this->error('❌ cities.json bo\'sh yoki noto\'g\'ri formatda.');
            return self::FAILURE;
        }

        // --fresh flag bilan mavjud ma'lumotlarni tozalash
        if ($this->option('fresh')) {
            $this->warn('🗑  Mavjud ma\'lumotlar o\'chirilmoqda...');
            District::truncate();
            Region::truncate();
        }

        $this->info('📥 Import boshlanmoqda...');
        $bar = $this->output->createProgressBar(count($data));
        $bar->start();

        $regionCount   = 0;
        $districtCount = 0;

        foreach ($data as $item) {
            // Bir xil viloyat ikki marta kirib qolmasligi uchun firstOrCreate
            $region = Region::firstOrCreate(
                ['name' => $item['region']],
                [
                    'lat'  => $item['lat']  ?? null,
                    'long' => $item['long'] ?? null,
                ]
            );

            if ($region->wasRecentlyCreated) {
                $regionCount++;
            }

            foreach ($item['cities'] ?? [] as $city) {
                District::firstOrCreate(
                    [
                        'region_id' => $region->id,
                        'name'      => $city['name'],
                    ],
                    [
                        'lat'  => $city['lat']  ?? null,
                        'long' => $city['long'] ?? null,
                    ]
                );
                $districtCount++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("✅ Import tugadi!");
        $this->table(
            ['Turi', 'Soni'],
            [
                ['Viloyatlar', $regionCount],
                ['Tumanlar',  $districtCount],
            ]
        );

        return self::SUCCESS;
    }
}
