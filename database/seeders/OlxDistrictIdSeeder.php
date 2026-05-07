<?php

namespace Database\Seeders;

use App\Models\District;
use App\Models\Region;
use Illuminate\Database\Seeder;

class OlxDistrictIdSeeder extends Seeder
{
    public function run(): void
    {
        $tashkent = Region::where('name', 'Toshkent shahri')->first();

        if (!$tashkent) {
            return;
        }

        $mapping = [
            'Bektemir tumani'      => 18,
            'Sergeli tumani'       => 19,
            'Olmazor tumani'       => 20,
            'Uchtepa tumani'       => 21,
            'Yashnobod tumani'     => 22,
            'Chilonzor tumani'     => 23,
            'Shayxontohur tumani'  => 24,
            'Yunusobod tumani'     => 25,
            'Yakkasaroy tumani'    => 26,
            'Mirzo Ulug‘bek tumani' => 12,
            'Mirobod tumani'       => 13,
            // Qo'shimcha variantlar (agar nomlar biroz farq qilsa)
            'Bektemir'             => 18,
            'Sirg‘ali tumani'      => 19, // Ba'zida Sirg'ali deb yoziladi
            'Yashnobod'            => 22,
            'Chilonzor'            => 23,
            'Shayxontohur'         => 24,
            'Yunusobod'            => 25,
            'Yakkasaroy'           => 26,
            'Mirzo Ulugbek'        => 12,
            'Mirobod'              => 13,
        ];

        foreach ($mapping as $name => $olxId) {
            District::where('region_id', $tashkent->id)
                ->where('name', 'like', "%{$name}%")
                ->update(['olx_id' => $olxId]);
        }
    }
}
