<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OlxScraperService
{
    // ─── OLX.uz viloyat slug lari ─────────────────────────────────────────────
    // https://www.olx.uz/oz/nedvizhimost/ → URL da ko'rinadigan slug lar

    private const REGION_SLUGS = [
        'Toshkent shahri'        => 'tashkent',
        'Toshkent viloyati'      => 'tashkentskaya-oblast',
        'Andijon viloyati'       => 'andizhan',
        'Buxoro viloyati'        => 'bukhara',
        "Farg'ona viloyati"      => 'fergana',
        "Farg\u02bbona viloyati" => 'fergana',
        'Jizzax viloyati'        => 'dzhizak',
        "Qoraqalpog'iston"       => 'karakalpakstan',
        "Qoraqalpog\u02bbiston"  => 'karakalpakstan',
        'Namangan viloyati'      => 'namangan',
        'Navoiy viloyati'        => 'navoi',
        'Qashqadaryo viloyati'   => 'kashkadarya',
        'Samarqand viloyati'     => 'samarkand',
        'Sirdaryo viloyati'      => 'syrdarya',
        'Surxondaryo viloyati'   => 'surkhandarya',
        'Xorazm viloyati'        => 'khorezm',
    ];

    // ─── mulk turi → OLX kategory yo'li ──────────────────────────────────────
    // mode: ijara | sotuvlar
    // property_type: uy | dokon | ofis

    private const CATEGORY_PATHS = [
        'ijara' => [
            'uy'    => 'nedvizhimost/kvartiry/',
            'dokon' => 'nedvizhimost/kommercheskie-pomeshcheniya/arenda/q-dokon-ijaraga/',
            'ofis'  => 'nedvizhimost/kommercheskie-pomeshcheniya/',
        ],
        'sotuvlar' => [
            'uy'    => 'nedvizhimost/kvartiry/prodazha/',
            'dokon' => 'nedvizhimost/kommercheskie-pomeshcheniya/prodazha/',
            'ofis'  => 'nedvizhimost/kommercheskie-pomeshcheniya/',
        ],
    ];

    // Tijorat mulk ichki turi (premise_type): dokon=1, ofis=4
    private const PREMISE_TYPE = [
        'dokon' => '1',
        'ofis'  => '4',
    ];

    // ─── Asosiy metod ─────────────────────────────────────────────────────────

    /**
     * Qidiruv - agar aniq filtrlarga mos natija topilmasa, kengaytirilgan qidiruv qiladi.
     *
     * @return array{listings: list<array{title:string,price:string,location:string,url:string}>, searchUrl: string, searchNote: string}
     */
    public function search(array $filters): array
{
    $districtName = $filters['district_name'] ?? '';
    $startTime    = microtime(true);

    // ─── 1-bosqich: district_id + barcha filtrlar ─────────────────────────
    // district_id URL da bo'lgani uchun filterByDistrict KERAK EMAS
    // OLX o'zi to'g'ri tumanlarni qaytaradi
    $url    = $this->buildUrl($filters);
    $result = $this->fetchAllPages($url, 5); // 3 emas, 5 sahifa

    Log::info('OLX search step 1', ['url' => $url, 'count' => count($result)]);

    if (!empty($result)) {
        return ['listings' => $result, 'searchUrl' => $url, 'searchNote' => ''];
    }

    if (microtime(true) - $startTime > 45) {
        return ['listings' => [], 'searchUrl' => $url, 'searchNote' => ''];
    }

    // ─── 2-bosqich: Narx ±30% kengaytirish ───────────────────────────────
    $relaxedFilters = $this->relaxPriceFilters($filters, 0.30);
    $relaxedUrl     = $this->buildUrl($relaxedFilters);
    $result         = $this->fetchAllPages($relaxedUrl, 5);

    Log::info('OLX search step 2 (±30%)', ['url' => $relaxedUrl, 'count' => count($result)]);

    if (!empty($result)) {
        return [
            'listings'   => $result,
            'searchUrl'  => $relaxedUrl,
            'searchNote' => "💡 <i>Narx oralig'i ±30% kengaytirildi.</i>",
        ];
    }

    if (microtime(true) - $startTime > 45) {
        return ['listings' => [], 'searchUrl' => $relaxedUrl, 'searchNote' => ''];
    }

    // ─── 3-bosqich: Narxsiz, faqat maydon + tuman ────────────────────────
    $noPriceFilters = $filters;
    unset(
        $noPriceFilters['price_min'],
        $noPriceFilters['price_max'],
        $noPriceFilters['price_min_uzs'],
        $noPriceFilters['price_max_uzs']
    );
    $noPriceUrl = $this->buildUrl($noPriceFilters);
    $result     = $this->fetchAllPages($noPriceUrl, 5);

    Log::info('OLX search step 3 (no price)', ['url' => $noPriceUrl, 'count' => count($result)]);

    if (!empty($result)) {
        return [
            'listings'   => $result,
            'searchUrl'  => $noPriceUrl,
            'searchNote' => "💡 <i>Aniq narxga mos e'lon topilmadi. Shu tumandagi barcha e'lonlar ko'rsatilmoqda.</i>",
        ];
    }

    if (microtime(true) - $startTime > 45) {
        return ['listings' => [], 'searchUrl' => $noPriceUrl, 'searchNote' => ''];
    }

    // ─── 4-bosqich: Faqat viloyat bo'yicha ───────────────────────────────
    $locationOnly = [
        'mode'          => $filters['mode']          ?? 'ijara',
        'property_type' => $filters['property_type'] ?? 'uy',
        'region_name'   => $filters['region_name']   ?? '',
        'currency'      => $filters['currency']      ?? 'uzs',
    ];
    $locationUrl       = $this->buildUrl($locationOnly);
    $allRegionListings = $this->fetchAllPages($locationUrl, 8);

    Log::info('OLX search step 4 (region only)', [
        'url'   => $locationUrl,
        'count' => count($allRegionListings),
    ]);

    if (!empty($allRegionListings)) {
        // Tuman bo'yicha eng yaqin 20 ta
        if (!empty($filters['district_name'])) {
            $sorted = $this->sortByDistrictProximity($allRegionListings, $filters['district_name']);
            $result = array_slice($sorted, 0, 20); // 10 emas 20
        } else {
            $result = array_slice($allRegionListings, 0, 20);
        }

        return [
            'listings'   => $result,
            'searchUrl'  => $locationUrl,
            'searchNote' => "💡 <i>Aynan {$districtName} uchun e'lon topilmadi. Viloyat bo'yicha eng yaqin e'lonlar ko'rsatilmoqda.</i>",
        ];
    }

    return ['listings' => [], 'searchUrl' => $url, 'searchNote' => ''];
}

    /**
     * Helper: sort listings by price closeness to the target range.
     */
    private function sortByPriceProximity(array $listings, $priceMin, $priceMax): array
    {
        // Determine target price – use the midpoint if both bounds exist, otherwise the defined bound.
        $target = null;
        if ($priceMin !== null && $priceMax !== null) {
            $target = ((int) $priceMin + (int) $priceMax) / 2;
        } elseif ($priceMin !== null) {
            $target = (int) $priceMin;
        } elseif ($priceMax !== null) {
            $target = (int) $priceMax;
        }
        if ($target === null) {
            return $listings;
        }
        usort($listings, function ($a, $b) use ($target) {
            $pa = $this->extractPrice($a['price'] ?? '');
            $pb = $this->extractPrice($b['price'] ?? '');
            $da = $pa !== null ? abs($pa - $target) : PHP_INT_MAX;
            $db = $pb !== null ? abs($pb - $target) : PHP_INT_MAX;
            return $da <=> $db;
        });
        return $listings;
    }

    /**
     * Extract numeric price from a string like "300 000 $".
     */
    private function extractPrice(string $priceString): ?int
    {
        // Remove any non‑digit characters except dot/comma.
        $numeric = preg_replace('/[^0-9]/', '', $priceString);
        return $numeric !== '' ? (int) $numeric : null;
    }

    /**
     * Helper: sort listings by similarity of their location to the desired district name.
     */
    private function sortByDistrictProximity(array $listings, string $districtName): array
    {
        $cleanTarget = mb_strtolower(preg_replace('/\s*(tuman|tumani)\s*/ui', '', $districtName));
        usort($listings, function ($a, $b) use ($cleanTarget) {
            $locA = mb_strtolower($a['location'] ?? '');
            $locB = mb_strtolower($b['location'] ?? '');
            similar_text($locA, $cleanTarget, $percA);
            similar_text($locB, $cleanTarget, $percB);
            return $percB <=> $percA; // higher similarity first
        });
        return $listings;
    }

    // Existing helper methods (addCurrencyParam, addPriceParams, addDistrictParam) remain unchanged.


    /**
     * Tuman bo'yicha filtrlash.
     * OLX ba'zan `reason=extended_search_no_results_distance` bilan boshqa
     * tumanlardan ham natija qaytaradi, shuning uchun DOIMO client-side filtrlash kerak.
     */
    private function filterByDistrict(array $listings, array $filters): array
{
    $districtName = $filters['district_name'] ?? '';
    $regionName   = $filters['region_name'] ?? '';

    if (empty($districtName)) {
        return $listings;
    }

    $cleanDistrict = mb_strtolower(trim(
        preg_replace('/\s*(tuman|tumani|district)\s*/ui', '', $districtName)
    ));

    $districtWords = array_filter(
        preg_split('/\s+/u', $cleanDistrict),
        fn($w) => mb_strlen($w) >= 3
    );

    $cyrillicMap = [
        'bektemir'     => 'бектемир',
        'mirzo'        => 'мирзо',
        'ulugbek'      => 'улугбек',
        'mirobod'      => 'мирабад',
        'olmazor'      => 'алмазар',
        'sirgali'      => 'сергели',
        'sergeli'      => 'сергели',
        'uchtepa'      => 'учтепа',
        'chilonzor'    => 'чиланзар',
        'shayxontohur' => 'шайхантахур',
        'yunusobod'    => 'юнусабад',
        'yakkasaroy'   => 'яккасарай',
        'yashnobod'    => 'яшнабад',
        'yangihayot'   => 'янгихаёт',
    ];

    $cyrillicWords = [];
    foreach ($districtWords as $word) {
        $normalized = preg_replace("/['\u{2018}\u{2019}`]/u", '', $word);
        if (isset($cyrillicMap[$normalized])) {
            $cyrillicWords[] = $cyrillicMap[$normalized];
        }
    }

    $filtered = array_values(array_filter(
        $listings,
        function ($item) use ($cleanDistrict, $districtWords, $cyrillicWords) {
            $loc = mb_strtolower($item['location'] ?? '');

            // 1) To'liq tuman nomi (lotin) — aniq moslik
            if (mb_stripos($loc, $cleanDistrict) !== false) {
                return true;
            }

            // 2) Tuman so'zlari — faqat mustaqil so'z sifatida (vergul/bo'sh joy chegarasi)
            // Masalan "chilonzor" → "chilonzor tumani" ✅, "Mirobod" → ✗
            foreach ($districtWords as $word) {
                // So'z boshida yoki ajratuvchi belgi oldida kelishi kerak
                if (preg_match('/(?:^|[\s,;\/])' . preg_quote($word, '/') . '/ui', $loc)) {
                    return true;
                }
            }

            // 3) Kirill variantlar — mustaqil so'z sifatida
            foreach ($cyrillicWords as $word) {
                if (preg_match('/(?:^|[\s,;\/])' . preg_quote($word, '/') . '/ui', $loc)) {
                    return true;
                }
            }

            // Viloyat nomi bo'yicha fallback OLIB TASHLANDI:
            // "Toshkent" so'zi barcha Toshkent tumanlarida uchraydi,
            // shuning uchun noto'g'ri tumanlar ham o'tib ketmoqda edi.

            return false;
        }
    ));

    Log::info('filterByDistrict result', [
        'district'     => $cleanDistrict,
        'before_count' => count($listings),
        'after_count'  => count($filtered),
    ]);

    return $filtered;
}

/**
 * Kvadrat metr va narx bo'yicha client-side filtrlash.
 * OLX URL parametrlari har doim to'g'ri ishlamaydi.
 */
private function filterBySqmAndPrice(array $listings, array $filters): array
{
    $sqmMin   = isset($filters['sqm_min'])   ? (int) $filters['sqm_min']   : null;
    $sqmMax   = isset($filters['sqm_max'])   ? (int) $filters['sqm_max']   : null;
    $currency = strtolower($filters['currency'] ?? 'uzs');

    if ($currency === 'usd') {
        $priceMin = isset($filters['price_min']) ? (float) $filters['price_min'] : null;
        $priceMax = isset($filters['price_max']) ? (float) $filters['price_max'] : null;
    } else {
        $priceMin = isset($filters['price_min_uzs']) ? (float) $filters['price_min_uzs'] : null;
        $priceMax = isset($filters['price_max_uzs']) ? (float) $filters['price_max_uzs'] : null;
    }

    // Agar hech qanday filtr yo'q bo'lsa — filtrlashning hojati yo'q
    if ($sqmMin === null && $sqmMax === null && $priceMin === null && $priceMax === null) {
        return $listings;
    }

    return array_values(array_filter(
        $listings,
        function ($item) use ($sqmMin, $sqmMax, $priceMin, $priceMax) {
            // ─── Narx tekshiruvi ──────────────────────────────────────────
            $itemPrice = $this->extractPrice($item['price'] ?? '');
            if ($itemPrice !== null) {
                if ($priceMin !== null && $itemPrice < $priceMin * 0.5) {
                    return false; // Narx juda past (±50% tolerance)
                }
                if ($priceMax !== null && $itemPrice > $priceMax * 1.5) {
                    return false; // Narx juda yuqori (±50% tolerance)
                }
            }

            return true; // sqm listing ichida ko'rinmaydi, faqat detail sahifada
        }
    ));
}

    // ─── Narx filtrlarini kengaytirish ──────────────────────────────────────────

    private function relaxPriceFilters(array $filters, float $percent): array
    {
        $relaxed  = $filters;
        $currency = strtolower($filters['currency'] ?? 'uzs');

        if ($currency === 'usd') {
            // USD da to'g'ridan-to'g'ri kengaytirish
            $priceMin = (int) ($filters['price_min'] ?? 0);
            $priceMax = (int) ($filters['price_max'] ?? 0);

            if ($priceMin > 0) {
                $relaxed['price_min'] = (int) max(0, $priceMin * (1 - $percent));
            }
            if ($priceMax > 0) {
                $relaxed['price_max'] = (int) ($priceMax * (1 + $percent));
            }
        } else {
            // UZS da kengaytirish — price_min_uzs / price_max_uzs dan o'qish
            $priceMin = (int) ($filters['price_min_uzs'] ?? ($filters['price_min'] ?? 0));
            $priceMax = (int) ($filters['price_max_uzs'] ?? ($filters['price_max'] ?? 0));

            if ($priceMin > 0) {
                $newMin = (int) max(0, $priceMin * (1 - $percent));
                $relaxed['price_min']     = $newMin;
                $relaxed['price_min_uzs'] = $newMin;
            }
            if ($priceMax > 0) {
                $newMax = (int) ($priceMax * (1 + $percent));
                $relaxed['price_max']     = $newMax;
                $relaxed['price_max_uzs'] = $newMax;
            }
        }

        Log::info('OLX relaxPriceFilters — narx kengaytirildi', [
            'percent'   => ($percent * 100) . '%',
            'currency'  => strtoupper($currency),
            'price_min' => $relaxed['price_min_uzs'] ?? $relaxed['price_min'] ?? '—',
            'price_max' => $relaxed['price_max_uzs'] ?? $relaxed['price_max'] ?? '—',
        ]);

        return $relaxed;
    }

    // ─── HTTP so'rov va parse qilish ──────────────────────────────────────────

    private $searchStartTime;

    /**
     * Barcha sahifalarni olib, natijalarni birlashtiradi.
     */
    private function fetchAllPages(string $url, int $maxPages = 10): array
    {
        if (!$this->searchStartTime) {
            $this->searchStartTime = microtime(true);
        }

        $allListings = [];
        $seenUrls    = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            // Overall timeout check (90 seconds safety)
            if (microtime(true) - $this->searchStartTime > 90) {
                Log::warning('OLX fetchAllPages: overall timeout reached, stopping.', ['url' => $url, 'page' => $page]);
                break;
            }

            $pageUrl = $url . (str_contains($url, '?') ? '&' : '?') . 'page=' . $page;

            $listings = $this->fetchAndParse($pageUrl);

            if (empty($listings)) {
                Log::info('OLX pagination: sahifa bo\'sh, to\'xtaymiz', ['page' => $page]);
                break;
            }

            // Dublikat tekshirish — agar barcha URL lar allaqachon ko'rilgan bo'lsa, oxirgi sahifa
            $newCount = 0;
            foreach ($listings as $listing) {
                $listUrl = $listing['url'] ?? '';
                if (!isset($seenUrls[$listUrl])) {
                    $seenUrls[$listUrl] = true;
                    $allListings[]      = $listing;
                    $newCount++;
                }
            }

            Log::info('OLX pagination', [
                'page'     => $page,
                'fetched'  => count($listings),
                'new'      => $newCount,
                'total'    => count($allListings),
            ]);

            // Agar yangi e'lon bo'lmasa — oxirgi sahifa
            if ($newCount === 0) break;

            // Rate limit uchun pauza
            if ($page < $maxPages) {
                usleep(500_000); // 0.5s
            }
        }

        return $allListings;
    }

    private function fetchAndParse(string $url): array
    {
        try {
            Log::info('OLX request', ['url' => $url]);
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                'Accept-Encoding' => 'gzip, deflate, br',
                'Sec-Fetch-Dest' => 'document',
            ])->withOptions(['verify' => false])
            ->timeout(15)
            ->get($url);

            Log::info('OLX response status: ' . $response->status());

            if (!$response->successful()) {
                Log::warning('OLX fetch failed', ['status' => $response->status(), 'url' => $url]);
                return [];
            }
            
            $html = $response->body();
            Log::info('OLX HTML length: ' . strlen($html));

            return $this->parse($html);

        } catch (\Throwable $e) {
            Log::error('OLX scraper error: ' . $e->getMessage(), ['url' => $url]);
            return [];
        }
    }

    // ─── URL yasash ───────────────────────────────────────────────────────────

    private function buildUrl(array $filters): string
    {
        $mode         = $filters['mode']          ?? 'ijara';
        $propertyType = $filters['property_type'] ?? 'uy';
        $regionName   = $filters['region_name']   ?? '';

        $categoryPath = self::CATEGORY_PATHS[$mode][$propertyType]
                    ?? self::CATEGORY_PATHS['ijara']['uy'];

        $regionSlug = $this->resolveRegionSlug($regionName);

        // /oz/ prefiksi bilan to'g'ri base URL
        $base = 'https://www.olx.uz/oz/' . $categoryPath;
        if ($regionSlug) {
            $base .= $regionSlug . '/';
        }

        $params   = [];
        $currency = strtolower($filters['currency'] ?? 'uzs');

        // ─── Valyuta: dollar = UYE, so'm = UZS ───────────────────────────────
        $params['currency'] = ($currency === 'usd' || $currency === 'uye') ? 'UYE' : 'UZS';

        // ─── Narx filtri ──────────────────────────────────────────────────────
        if ($currency === 'uzs') {
            $priceMin = $filters['price_min_uzs'] ?? ($filters['price_min'] ?? null);
            $priceMax = $filters['price_max_uzs'] ?? ($filters['price_max'] ?? null);
        } else {
            $priceMin = $filters['price_min'] ?? null;
            $priceMax = $filters['price_max'] ?? null;
        }

        if (!empty($priceMin)) {
            $params['search[filter_float_price:from]'] = (int) $priceMin;
        }
        if (!empty($priceMax)) {
            $params['search[filter_float_price:to]'] = (int) $priceMax;
        }

        // ─── Tijorat mulk turi (dokon=1, ofis=4) ─────────────────────────────
        if (in_array($propertyType, ['dokon', 'ofis']) && isset(self::PREMISE_TYPE[$propertyType])) {
            $params['search[filter_enum_premise_type][0]'] = self::PREMISE_TYPE[$propertyType];
        }

        // ─── Maydon filtri ────────────────────────────────────────────────────
        if (!empty($filters['sqm_min'])) {
            $params['search[filter_float_total_area:from]'] = (int) $filters['sqm_min'];
        }
        if (!empty($filters['sqm_max'])) {
            $params['search[filter_float_total_area:to]'] = (int) $filters['sqm_max'];
        }

        // ─── Tuman filtri ─────────────────────────────────────────────────────
        if (!empty($filters['district_id'])) {
            $params['search[district_id]'] = $filters['district_id'];
        }

        // http_build_query: [ ] ni %5B %5D qiladi, : ni %3A qiladi
        // OLX : ni ochiq holda kutadi, shuning uchun qayta almashtirish
        $queryString = http_build_query($params);
        $queryString = str_replace('%3A', ':', $queryString);

        Log::info('OLX buildUrl', [
            'url'           => $base . '?' . $queryString,
            'mode'          => $mode,
            'property_type' => $propertyType,
            'currency'      => $params['currency'],
            'price_from'    => $priceMin ?? '—',
            'price_to'      => $priceMax ?? '—',
            'sqm_from'      => $filters['sqm_min'] ?? '—',
            'sqm_to'        => $filters['sqm_max'] ?? '—',
        ]);

        return $base . (empty($params) ? '' : '?' . $queryString);
    }

    // Helper methods for query parameters
    private function addCurrencyParam(array &$params, array $filters): void
    {
        $currency = strtolower($filters['currency'] ?? 'uzs');
        if ($currency === 'usd' || $currency === 'uye') {
            $params['search[filter_enum_currency][0]'] = 'USD';
        } else {
            $params['search[filter_enum_currency][0]'] = 'UZS';
        }
    }

    private function addPriceParams(array &$params, $priceMin, $priceMax): void
    {
        if (!empty($priceMin)) {
            $params['search[filter_float_price:from]'] = (int) $priceMin;
        }
        if (!empty($priceMax)) {
            $params['search[filter_float_price:to]'] = (int) $priceMax;
        }
    }

    private function addDistrictParam(array &$params, array $filters): void
    {
        if (!empty($filters['district_id'])) {
            $params['search[district_id]'] = $filters['district_id'];
        }
    }

    // ─── HTML parse ───────────────────────────────────────────────────────────

    private function parse(string $html): array
    {
        // 1-usul: LD+JSON dan Product orqali e'lonlarni olish
        $listings = $this->parseJsonLd($html);
        if (!empty($listings)) {
            Log::info('Found via parseJsonLd', ['count' => count($listings)]);
            return $listings;
        }

        // 2-usul: OLX sahifasidagi __NEXT_DATA__ JSON blokidan o'qish
        $listings = $this->parseNextData($html);
        if (!empty($listings)) {
            Log::info('Found via parseNextData', ['count' => count($listings)]);
            return $listings;
        }

        // 3-usul: HTML data-cy attributlari bilan
        $listings = $this->parseHtmlSelectors($html);
        if (!empty($listings)) {
            Log::info('Found via parseHtmlSelectors', ['count' => count($listings)]);
            return $listings;
        }

        // 4-usul: /d/oz/obyavlenie/ linklar orqali e'lonlarni topish
        $listings = $this->parseAdCards($html);
        if (!empty($listings)) {
            Log::info('Found via parseAdCards', ['count' => count($listings)]);
            return $listings;
        }

        // 5-usul: oxirgi chora — oddiy regex
        $listings = $this->parseFallback($html);
        Log::info('Found via parseFallback', ['count' => count($listings)]);
        return $listings;
    }

    /**
     * OLX sahifasidagi LD+JSON ni parse qilish.
     * OLX hozir Product + AggregateOffer yoki Product + offers formatida foydalanadi.
     */
    private function parseJsonLd(string $html): array
    {
        $listings = [];

        // Barcha LD+JSON bloklarni topish
        preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $allScripts);

        if (empty($allScripts[1])) {
            return [];
        }

        foreach ($allScripts[1] as $jsonStr) {
            $data = json_decode(trim($jsonStr), true);
            if (!$data || !isset($data['@type'])) continue;

            // Product tipida — offers ichidan e'lonlar olish
            if ($data['@type'] === 'Product' && isset($data['offers'])) {
                $offers = [];

                // 1-format: offers.offers (array of Offer)
                if (!empty($data['offers']['offers']) && is_array($data['offers']['offers'])) {
                    $offers = $data['offers']['offers'];
                }
                // 2-format: offers array to'g'ridan-to'g'ri
                elseif (isset($data['offers']['@type']) && $data['offers']['@type'] === 'AggregateOffer' && !empty($data['offers']['offers'])) {
                    $offers = $data['offers']['offers'];
                }

                foreach ($offers as $item) {
                    $offerType = $item['@type'] ?? '';
                    if ($offerType !== 'Offer') continue;

                    $title = $item['name'] ?? '';
                    $url   = $item['url'] ?? '';
                    if (empty($title) || empty($url)) continue;

                    $price = '—';
                    if (isset($item['price']) && $item['price'] > 0) {
                        $amount   = number_format((float) $item['price'], 0, '.', ' ');
                        $currency = $item['priceCurrency'] ?? 'UZS';
                        $price    = "{$amount} {$currency}";
                    }

                    $location = $item['areaServed']['name'] ?? '—';

                    if (!str_starts_with($url, 'http')) {
                        $url = 'https://www.olx.uz' . $url;
                    }

                    $listings[] = compact('title', 'price', 'location', 'url');
                }
            }

            // ItemList tipida (ba'zan OLX shuni ishlatadi)
            if ($data['@type'] === 'ItemList' && !empty($data['itemListElement'])) {
                foreach ($data['itemListElement'] as $item) {
                    $title = $item['name'] ?? '';
                    $url   = $item['url'] ?? '';
                    if (empty($title) || empty($url)) continue;

                    if (!str_starts_with($url, 'http')) {
                        $url = 'https://www.olx.uz' . $url;
                    }

                    $listings[] = [
                        'title'    => $title,
                        'price'    => '—',
                        'location' => '—',
                        'url'      => $url,
                    ];
                }
            }
        }

        Log::info('parseJsonLd result', ['count' => count($listings)]);
        return $listings;
    }

    /**
     * OLX React sahifasidagi __NEXT_DATA__ JSON ni parse qilish.
     * Bu eng ishonchli usul — JavaScript build qilingan ma'lumotlar.
     */
    private function parseNextData(string $html): array
    {
        if (!preg_match('#<script id="__NEXT_DATA__"[^>]*>(.*?)</script>#s', $html, $m)) {
            return [];
        }

        $json = json_decode($m[1], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [];
        }


        // 4 ta turli yo'l bilan ads ni qidirish:
        $ads = $json['props']['pageProps']['ads'] 
            ?? $json['props']['pageProps']['data']['ads'] 
            ?? $json['props']['pageProps']['listing']['ads'] 
            ?? $json['props']['pageProps']['hydraData']['searchAds'] 
            ?? null;

        if (empty($ads) || !is_array($ads)) {
            return [];
        }

        $listings = [];
        foreach ($ads as $ad) {
            $title    = $ad['title'] ?? ($ad['name'] ?? null);
            $url      = $ad['url']   ?? null;

            if (empty($title) || empty($url)) continue;

            // Narx
            $price = '—';
            if (!empty($ad['price']['regularPrice']['value'])) {
                $amount   = number_format($ad['price']['regularPrice']['value'], 0, '.', ' ');
                $currency = $ad['price']['regularPrice']['currencyCode'] ?? 'UZS';
                $price    = "{$amount} {$currency}";
            } elseif (!empty($ad['price']['displayValue'])) {
                $price = $ad['price']['displayValue'];
            }

            // Joylashuv
            $location = $ad['location']['cityName'] ?? ($ad['location']['regionName'] ?? '—');
            if (!empty($ad['location']['districtName'])) {
                $location = $ad['location']['districtName'] . ', ' . $location;
            }

            // URL to'liq bo'lmasa olx.uz qo'shamiz
            if (!str_starts_with($url, 'http')) {
                $url = 'https://www.olx.uz' . $url;
            }

            $listings[] = compact('title', 'price', 'location', 'url');
        }

        return $listings;
    }

    /**
     * HTML dan DOMDocument bilan parse qilish.
     * data-cy="l-card" card'lardan sarlavha, narx, manzil, rasm oladi.
     */
    private function parseHtmlSelectors(string $html): array
    {
        $listings = [];

        // Suppress HTML warnings
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        // data-cy="l-card" card'larni topish
        $cards = $xpath->query('//*[@data-cy="l-card"]');

        if ($cards->length === 0) {
            Log::info('parseHtmlSelectors: no l-card elements found (DOMDocument)');
            return [];
        }

        Log::info('parseHtmlSelectors: found l-card elements', ['count' => $cards->length]);

        foreach ($cards as $card) {
            // E'lon linki: /d/oz/obyavlenie/ YOKI /d/obyavlenie/ formatlarini qo'llab-quvvatlash
            $adLink = $xpath->query(
                './/a[contains(@href, "/d/oz/obyavlenie/") or contains(@href, "/d/obyavlenie/")]',
                $card
            );
            if ($adLink->length === 0) continue;

            $href = $adLink->item(0)->getAttribute('href');
            $url = str_starts_with($href, 'http') ? $href : 'https://www.olx.uz' . $href;

            // Sarlavha: 1) data-cy="ad-title", 2) h4/h6/h3/h2, 3) strong/p, 4) eng uzun matn
            $title = '';

            // 1-usul: data-cy="ad-title" — OLX yangi versiyasi
            $titleEl = $xpath->query('.//*[@data-cy="ad-title"]', $card);
            if ($titleEl->length > 0) {
                $title = trim($titleEl->item(0)->textContent);
            }

            // 2-usul: heading taglar
            if (empty($title)) {
                foreach (['h4', 'h6', 'h3', 'h2', 'h5'] as $tag) {
                    $heading = $xpath->query('.//' . $tag, $card);
                    if ($heading->length > 0) {
                        $title = trim($heading->item(0)->textContent);
                        if (!empty($title)) break;
                    }
                }
            }

            // 3-usul: strong yoki p teg
            if (empty($title)) {
                foreach (['strong', 'p'] as $tag) {
                    $els = $xpath->query('.//' . $tag, $card);
                    foreach ($els as $el) {
                        $t = trim($el->textContent);
                        if (mb_strlen($t) >= 10 && mb_strlen($t) <= 200) {
                            $title = $t;
                            break 2;
                        }
                    }
                }
            }

            if (mb_strlen($title) < 3 || mb_strlen($title) > 200) continue;

            // Narx: data-testid="ad-price" — <style> taglarni olib tashlash
            $price = '—';
            $priceEl = $xpath->query('.//*[@data-testid="ad-price"]', $card);
            if ($priceEl->length > 0) {
                $price = $this->getCleanText($priceEl->item(0));
            }

            // Manzil: data-testid="location-date" — <style> taglarni olib tashlash
            $location = '—';
            $locEl = $xpath->query('.//*[@data-testid="location-date"]', $card);
            if ($locEl->length > 0) {
                $location = $this->getCleanText($locEl->item(0));
                // "Toshkent, Chilonzor - Bugun 14:45" => "Toshkent, Chilonzor tumani"
                $location = preg_replace('/\s*-\s*(?:Bugun|Kecha|Dushanba|Seshanba|Chorshanba|Payshanba|Juma|Shanba|Yakshanba|Bugunda|\d).*$/ui', '', $location);
            }

            // Rasm: birinchi img
            $thumbnail = '';
            $imgEl = $xpath->query('.//img', $card);
            if ($imgEl->length > 0) {
                $thumbnail = $imgEl->item(0)->getAttribute('src');
            }

            $listings[] = [
                'title'     => $title,
                'price'     => $price,
                'location'  => $location,
                'url'       => $url,
                'thumbnail' => $thumbnail,
            ];

            if (count($listings) >= 100) break;
        }

        Log::info('parseHtmlSelectors found', ['count' => count($listings)]);
        return $listings;
    }

    /**
     * DOM elementdan toza matn olish — <style> va <script> taglarni olib tashlab.
     * Bu OLX ning CSS-in-JS elementlaridan CSS kodini filtrlaydi.
     */
    private function getCleanText(\DOMNode $node): string
    {
        $clone = $node->cloneNode(true);

        // <style> va <script> taglarni o'chirish
        foreach (['style', 'script'] as $tag) {
            $elements = $clone->getElementsByTagName($tag);
            // Oxiridan o'chiramiz (DOMNodeList live collection)
            $toRemove = [];
            foreach ($elements as $el) {
                $toRemove[] = $el;
            }
            foreach ($toRemove as $el) {
                $el->parentNode->removeChild($el);
            }
        }

        $text = trim($clone->textContent);
        // Qo'shimcha CSS fragmentlarni tozalash (ehtiyot uchun)
        $text = preg_replace('/\.css-[a-z0-9]+\{[^}]*\}/s', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    /**
     * E'lon linklarini (/d/oz/obyavlenie/) va ularning kontekstini topish.
     * Bu usul data-cy card'lar ishlamagan holatda e'lonlarni aniqroq topadi.
     */
    private function parseAdCards(string $html): array
    {
        $listings = [];
        $seenUrls = [];

        // /d/oz/obyavlenie/ YOKI /d/obyavlenie/ formatdagi barcha linklar
        preg_match_all(
            '#<a[^>]*href=["\']((?:https://www\.olx\.uz)?/d/(?:oz/)?obyavlenie/[^"\'>]+)["\'][^>]*>(.*?)</a>#is',
            $html,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        if (empty($matches)) {
            Log::info('parseAdCards: no ad links found (/d/oz/obyavlenie/ or /d/obyavlenie/)');
            return [];
        }

        foreach ($matches as $match) {
            $rawHref = $match[1][0];
            // To'liq URL hosil qilish
            $url = str_starts_with($rawHref, 'http') ? $rawHref : 'https://www.olx.uz' . $rawHref;
            $path = parse_url($url, PHP_URL_PATH) ?? $rawHref;

            // Dublikat tekshirish
            if (isset($seenUrls[$url])) continue;
            $seenUrls[$url] = true;

            // URL haqiqiy e'lon URL mi? (.html bilan tugashi yoki IDxxxxxx bo'lishi kerak)
            if (!preg_match('#\.(html|htm)$|ID[A-Za-z0-9]+\.html#', $path)) {
                continue;
            }

            // Sarlavha: <a> tag ichidagi matn
            $innerHtml = $match[2][0];
            $title = strip_tags(html_entity_decode($innerHtml, ENT_QUOTES, 'UTF-8'));
            $title = trim(preg_replace('/\s+/', ' ', $title));

            if (mb_strlen($title) < 5 || mb_strlen($title) > 200) {
                continue;
            }

            // Narx va joylashuvni link atrofidagi HTML dan qidirish
            $offset = $match[0][1];
            $contextStart = max(0, $offset - 500);
            $context = substr($html, $contextStart, 2000);

            $price = '—';
            // OLX narx formatlari: "300 $", "1 500 000 so'm", "5 000 000 UZS"
            if (preg_match('#([\d\s]+(?:so[\x{2018}\x{2019}\'`]m|\$|USD|UZS|UYE|у\.е\.))#iu', $context, $pm)) {
                $price = trim($pm[1]);
            } elseif (preg_match('#data-testid=["\']ad-price["\'][^>]*>\s*([^<]+)#i', $context, $pm)) {
                $price = trim($pm[1]);
            }

            $location = '—';
            if (preg_match('#data-testid=["\']location-date["\'][^>]*>\s*([^<]+)#i', $context, $lm)) {
                $location = trim($lm[1]);
                $location = preg_replace('/\s*-\s*.*$/', '', $location);
            }

            $listings[] = compact('title', 'price', 'location', 'url');

            if (count($listings) >= 100) {
                break;
            }
        }

        Log::info('parseAdCards found', ['count' => count($listings)]);
        return $listings;
    }

    /**
     * Oxirgi chora: oddiy regex bilan faqat haqiqiy e'lon URL larini topish.
     * /d/oz/obyavlenie/ formatdagi linklar — haqiqiy e'lon.
     * Boshqa (kategoriya, navigatsiya) linklar filtrlanadi.
     */
    private function parseFallback(string $html): array
    {
        $listings = [];
        $seenUrls = [];

        // Faqat haqiqiy e'lon linklarini topish: /d/oz/obyavlenie/ YOKI /d/obyavlenie/
        preg_match_all(
            '#href=["\']((?:https://www\.olx\.uz)?/d/(?:oz/)?obyavlenie/[^"\'>]+)["\'][^>]*>([^<]{5,}?)<#is',
            $html,
            $matches
        );

        if (!empty($matches[1])) {
            foreach ($matches[1] as $i => $rawHref) {
                // To'liq URL yoki nisbiy yo'l bo'lishi mumkin
                $url = str_starts_with($rawHref, 'http')
                    ? $rawHref
                    : 'https://www.olx.uz' . $rawHref;

                if (isset($seenUrls[$url])) continue;
                $seenUrls[$url] = true;

                // URL haqiqiy e'lon ekanini tekshirish (.html bilan tugashi kerak)
                if (!preg_match('#\.html?$#', $url)) continue;

                $titleHtml = $matches[2][$i] ?? '';
                $title = strip_tags(html_entity_decode($titleHtml, ENT_QUOTES, 'UTF-8'));
                $title = trim(preg_replace('/\s+/', ' ', $title));

                if (mb_strlen($title) < 5 || mb_strlen($title) > 200) continue;

                $listings[] = [
                    'title'    => $title,
                    'price'    => '—',
                    'location' => '—',
                    'url'      => $url,
                ];

                if (count($listings) >= 100) break;
            }
        }

        // Duplicates o'tkazib yuborish
        $unique = [];
        $seen = [];
        foreach ($listings as $listing) {
            if (!isset($seen[$listing['url']])) {
                $seen[$listing['url']] = true;
                $unique[] = $listing;
            }
        }

        Log::info('OLX parseFallback result', ['count' => count($unique), 'html_length' => strlen($html)]);

        return array_slice($unique, 0, 100);
    }

    // ─── Region slug resolver ─────────────────────────────────────────────────

    private function resolveRegionSlug(string $regionName): string
    {
        // To'g'ridan-to'g'ri map
        if (isset(self::REGION_SLUGS[$regionName])) {
            return self::REGION_SLUGS[$regionName];
        }

        // Qisman moslik (birinchi so'z bo'yicha)
        $firstWord = mb_strtolower(explode(' ', $regionName)[0]);
        foreach (self::REGION_SLUGS as $key => $slug) {
            if (mb_stripos($key, $firstWord) !== false) {
                return $slug;
            }
        }

        // Butun O'zbekiston bo'yicha
        return '';
    }

    /**
     * E'lonning to'liq ma'lumotlarini olish (rasmlar, maydon, tel)
     */
    public function getAdDetails(string $url): array
    {
        // URL haqiqiy e'lon sahifasi ekanini tekshirish
        if (!preg_match('#/d/oz/obyavlenie/|/d/obyavlenie/#', $url)) {
            Log::warning('getAdDetails: URL is not an ad page', ['url' => $url]);
            return ['photos' => [], 'm2' => '—', 'phone' => '—'];
        }

        try {
            $response = Http::withHeaders([
                'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                'Accept'          => 'text/html,application/xhtml+xml',
                'Accept-Encoding' => 'gzip, deflate, br',
                'Sec-Fetch-Dest'  => 'document',
            ])->withOptions(['verify' => false])
              ->timeout(12)
              ->get($url);

            Log::info('getAdDetails response', ['url' => $url, 'status' => $response->status()]);

            if (!$response->successful()) return ['photos' => [], 'm2' => '—', 'phone' => '—'];

            $html = $response->body();
            $details = [
                'photos' => [],
                'm2'     => '—',
                'phone'  => '—',
            ];

            // ─── 1. Barcha LD+JSON bloklardan rasm va ma'lumot olish ──────────
            preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $ldMatches);
            foreach ($ldMatches[1] ?? [] as $ldJson) {
                $ld = json_decode(trim($ldJson), true);
                if (!$ld) continue;
                if (isset($ld[0])) $ld = $ld[0]; // array bo'lsa

                // Rasmlar
                if (empty($details['photos']) && isset($ld['image'])) {
                    $images = is_array($ld['image']) ? $ld['image'] : [$ld['image']];
                    $details['photos'] = array_filter($images, fn($img) => is_string($img) && str_starts_with($img, 'http'));
                }

                // Tavsifdan m2 va tel
                $desc = $ld['description'] ?? '';
                if ($details['m2'] === '—' && preg_match('#(\d+(?:[.,]\d+)?)\s*(?:m2|м2|m²|кв\.?\s*м)#ui', $desc, $am)) {
                    $details['m2'] = $am[1] . ' m²';
                }
                if ($details['phone'] === '—' && preg_match('#(\+?998\s?\d{2}\s?\d{3}\s?\d{2}\s?\d{2})#', $desc, $pm)) {
                    $details['phone'] = $pm[1];
                }
            }

            // ─── 2. __NEXT_DATA__ dan rasmlar va parametrlar ─────────────────
            if (empty($details['photos']) && preg_match('#<script id="__NEXT_DATA__"[^>]*>(.*?)</script>#s', $html, $nextMatch)) {
                $nextData = json_decode($nextMatch[1], true);
                if ($nextData) {
                    // Rasmlar
                    $ad = $nextData['props']['pageProps']['ad'] ?? $nextData['props']['pageProps']['adData'] ?? null;
                    if ($ad) {
                        // photos array
                        if (!empty($ad['photos'])) {
                            foreach ($ad['photos'] as $photo) {
                                $photoUrl = $photo['link'] ?? $photo['url'] ?? null;
                                if ($photoUrl) $details['photos'][] = $photoUrl;
                            }
                        }
                        // params (m2, phone)
                        if (!empty($ad['params'])) {
                            foreach ($ad['params'] as $param) {
                                $key = $param['key'] ?? '';
                                $val = $param['value']['label'] ?? ($param['normalizedValue'] ?? '');
                                if ($key === 'total_area' && $details['m2'] === '—' && $val) {
                                    $details['m2'] = $val . ' m²';
                                }
                                if ($key === 'phone' && $details['phone'] === '—' && $val) {
                                    $details['phone'] = $val;
                                }
                            }
                        }
                    }
                }
            }

            // ─── 3. og:image meta tag dan rasm olish ──────────────────────────
            if (empty($details['photos'])) {
                preg_match_all('#<meta\s+(?:property|name)=["\']og:image["\']\s+content=["\']([^"\']+)["\']#i', $html, $ogImages);
                if (!empty($ogImages[1])) {
                    $details['photos'] = array_filter($ogImages[1], fn($img) => str_starts_with($img, 'http'));
                }
            }

            // ─── 4. Apollo CDN rasmlarini HTML dan qidirish (oxirgi chora) ────
            if (empty($details['photos'])) {
                preg_match_all('#(https://[a-z]+\.apollo\.olxcdn\.com[^"\'>\s]+)#i', $html, $cdnImages);
                if (!empty($cdnImages[1])) {
                    $details['photos'] = array_unique(array_slice($cdnImages[1], 0, 10));
                }
            }

            // ─── 5. DOM dan m2 va telefon qidirish ────────────────────────────
            if ($details['m2'] === '—') {
                if (preg_match('#Umumiy maydon[^<]*?:\s*<[^>]*>([^<]+)#iu', $html, $am)) {
                    $details['m2'] = trim($am[1]);
                } elseif (preg_match('#[Mm]aydon[^<]*?:\s*<[^>]*>([^<]+)#iu', $html, $am)) {
                    $details['m2'] = trim($am[1]);
                } elseif (preg_match('#(\d+)\s*m²#u', $html, $am)) {
                    $details['m2'] = $am[1] . ' m²';
                }
            }

            // Telefon — HTML body dan qidirish
            if ($details['phone'] === '—') {
                if (preg_match('#(\+?998[\s-]?\d{2}[\s-]?\d{3}[\s-]?\d{2}[\s-]?\d{2})#', $html, $pm)) {
                    $details['phone'] = preg_replace('/[\s-]+/', ' ', $pm[1]);
                }
            }

            Log::info('getAdDetails extracted', [
                'photos' => count($details['photos']),
                'm2'     => $details['m2'],
                'phone'  => $details['phone'],
            ]);

            return $details;

        } catch (\Throwable $e) {
            Log::error('OLX details error', ['url' => $url, 'error' => $e->getMessage()]);
            return ['photos' => [], 'm2' => '—', 'phone' => '—'];
        }
    }
}
