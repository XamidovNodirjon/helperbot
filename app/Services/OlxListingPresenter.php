<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram;

/**
 * OLX e'lonlarini Telegram ga sahifalab yuborish servisi.
 *
 * Har bir sahifada 2 ta e'lon:
 *   1) Rasmlar (media group yoki bitta rasm) — caption birinchi rasmda
 *   2) \"➡️ Keyingisi\" tugmasi (oxirgi sahifada ko'rsatilmaydi)
 */
class OlxListingPresenter
{
    /** Bir sahifadagi e'lonlar soni */
    private const PER_PAGE = 2;

    /**
     * Berilgan sahifadagi e'lonlarni yuboradi.
     *
     * @param int    $chatId
     * @param string $cacheKey  Cache kaliti (olx_results_{chatId})
     * @param int    $page      0-indekslangan sahifa raqami
     */
    public static function sendPage(int $chatId, string $cacheKey, int $page): void
    {
        $cached = Cache::get($cacheKey);

        if (empty($cached) || empty($cached['listings'])) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text'    => "⏳ Natijalar muddati tugagan. Iltimos, yangi qidiruv boshlang.",
            ]);
            return;
        }

        $allListings = $cached['listings'];
        $searchUrl   = $cached['searchUrl'] ?? '';
        $total       = count($allListings);
        $totalPages  = (int) ceil($total / self::PER_PAGE);

        // Sahifa chegarasini tekshirish
        if ($page >= $totalPages) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text'    => "✅ Barcha {$total} ta e'lon ko'rsatildi.",
            ]);
            return;
        }

        // Joriy sahifadagi e'lonlar
        $pageListings = array_slice($allListings, $page * self::PER_PAGE, self::PER_PAGE);
        $scraper      = new OlxScraperService();

        Log::info("OLX sendPage", [
            'chatId'  => $chatId,
            'page'    => $page,
            'total'   => $total,
            'showing' => count($pageListings),
        ]);

        $sentCount = 0;
        foreach ($pageListings as $listing) {
            $details = $scraper->getAdDetails($listing['url']);

            Log::info("OLX e'lon tafsilotlari olindi", [
                'title'  => $listing['title'],
                'photos' => count($details['photos'] ?? []),
                'm2'     => $details['m2'] ?? '—',
                'phone'  => $details['phone'] ?? '—',
            ]);

            self::sendSingleListing($chatId, $listing, $details);
            $sentCount++;

            // Telegram rate limit uchun qisqa pauza
            if ($sentCount < count($pageListings)) {
                usleep(400_000); // 0.4s
            }
        }

        // ─── Navigatsiya tugmasi ──────────────────────────────────────────────
        $shown    = min(($page + 1) * self::PER_PAGE, $total);
        $nextPage = $page + 1;
        $hasMore  = $nextPage < $totalPages;

        if ($hasMore) {
            // "Keyingisi" tugmasi
            $keyboard = [
                'inline_keyboard' => [
                    [
                        [
                            'text'          => "➡️ Keyingisi ({$shown}/{$total})",
                            'callback_data' => "olx_next_{$cacheKey}_{$nextPage}",
                        ],
                    ],
                    [
                        [
                            'text'          => "🔍 OLX da to'liq ko'rish",
                            'callback_data' => "olx_openurl",
                        ],
                    ],
                ],
            ];

            Telegram::sendMessage([
                'chat_id'      => $chatId,
                'parse_mode'   => 'HTML',
                'reply_markup' => json_encode($keyboard),
                'text'         => "📋 <b>{$shown}</b> / {$total} ta e'lon ko'rsatildi.\n\n➡️ Keyingisi tugmasini bosib davom eting.",
            ]);
        } else {
            // Oxirgi sahifa — faqat OLX linki
            $keyboard = [
                'inline_keyboard' => [
                    [
                        [
                            'text' => "🔍 OLX da to'liq ko'rish",
                            'url'  => $searchUrl,
                        ],
                    ],
                ],
            ];

            Telegram::sendMessage([
                'chat_id'                  => $chatId,
                'parse_mode'               => 'HTML',
                'reply_markup'             => json_encode($keyboard),
                'disable_web_page_preview' => true,
                'text'                     =>
                    "✅ <b>Barcha {$total} ta e'lon ko'rsatildi.</b>\n\n" .
                    "🔍 To'liq ro'yxat uchun OLX da ko'ring.",
            ]);
        }
    }

    /**
     * Bitta e'lonni yuboradi:
     *   1) Rasmlar media group sifatida (caption birinchi rasmda: maydon, manzil, narx, tel)
     *   2) Bitta rasm bo'lsa — sendPhoto + caption
     *   3) Rasm yo'q bo'lsa — faqat matn xabari
     */
    private static function sendSingleListing(
        int   $chatId,
        array $listing,
        array $details,
        array $filters = []
    ): void {
        // ─── Kvadrat metr filtri (detail ma'lumoti bor bo'lsa) ───────────────
        if (!empty($filters['sqm_min']) || !empty($filters['sqm_max'])) {
            $m2Raw = $details['m2'] ?? '—';
            if ($m2Raw !== '—') {
                preg_match('/(\d+(?:[.,]\d+)?)/', $m2Raw, $m);
                $actualSqm = isset($m[1]) ? (float) str_replace(',', '.', $m[1]) : null;

                if ($actualSqm !== null) {
                    $sqmMin = (int) ($filters['sqm_min'] ?? 0);
                    $sqmMax = (int) ($filters['sqm_max'] ?? PHP_INT_MAX);

                    if ($actualSqm < $sqmMin || $actualSqm > $sqmMax) {
                        Log::info("sqm filter: e'lon o'tkazib yuborildi", [
                            'actual' => $actualSqm,
                            'min'    => $sqmMin,
                            'max'    => $sqmMax,
                            'url'    => $listing['url'],
                        ]);
                        return;
                    }
                }
            }
        }

        // ─── Caption (sarlavha, maydon, manzil, narx, telefon, link) ─────────
        $caption = self::formatCaption($listing, $details);

        // ─── Rasmlar ro'yxatini tozalash ──────────────────────────────────────
        $photos = array_values(array_filter(
            $details['photos'] ?? [],
            fn($p) => is_string($p) && str_starts_with($p, 'http')
        ));
        // Telegram media group uchun max 10 ta
        $photos = array_slice($photos, 0, 10);

        if (count($photos) >= 2) {
            // ─── Media Group: rasmlar guruhi, caption birinchi rasmda ──────────
            $media = [];
            foreach ($photos as $i => $photoUrl) {
                $item = ['type' => 'photo', 'media' => $photoUrl];
                if ($i === 0) {
                    $item['caption']    = $caption;
                    $item['parse_mode'] = 'HTML';
                }
                $media[] = $item;
            }

            try {
                Telegram::sendMediaGroup([
                    'chat_id' => $chatId,
                    'media'   => json_encode($media),
                ]);
            } catch (\Throwable $e) {
                Log::warning("sendMediaGroup xatosi, bitta rasm bilan davom etamiz", [
                    'error' => $e->getMessage(),
                    'url'   => $listing['url'],
                ]);
                self::sendSinglePhoto($chatId, $photos[0], $caption);
            }

        } elseif (count($photos) === 1) {
            // ─── Bitta rasm ───────────────────────────────────────────────────
            self::sendSinglePhoto($chatId, $photos[0], $caption);

        } else {
            // ─── Rasm yo'q — faqat matn ───────────────────────────────────────
            Telegram::sendMessage([
                'chat_id'                  => $chatId,
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => true,
                'text'                     => $caption,
            ]);
        }
    }

    /**
     * Bitta rasm + caption yuborish yordamchi metodi.
     * Xato bo'lsa faqat matn sifatida yuboradi.
     */
    private static function sendSinglePhoto(int $chatId, string $photoUrl, string $caption): void
    {
        try {
            Telegram::sendPhoto([
                'chat_id'    => $chatId,
                'photo'      => $photoUrl,
                'caption'    => $caption,
                'parse_mode' => 'HTML',
            ]);
        } catch (\Throwable $e) {
            Log::warning("sendPhoto xatosi, faqat matn yuboriladi", [
                'error' => $e->getMessage(),
                'photo' => $photoUrl,
            ]);
            Telegram::sendMessage([
                'chat_id'                  => $chatId,
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => true,
                'text'                     => $caption,
            ]);
        }
    }

    /**
     * E'lon ma'lumotlarini chiroyli matn sifatida shakllantiradi.
     * Telegram caption uchun (max 1024 belgi).
     */
    private static function formatCaption(array $listing, array $details): string
    {
        $lines = [];

        // Sarlavha
        $title = trim($listing['title'] ?? '');
        if ($title) {
            $lines[] = "🏢 <b>" . htmlspecialchars($title, ENT_QUOTES) . "</b>";
        }

        // Maydon
        $m2 = trim($details['m2'] ?? '—');
        if ($m2 && $m2 !== '—') {
            $lines[] = "📐 <b>Maydon:</b> {$m2}";
        }

        // Manzil
        $location = trim($listing['location'] ?? '—');
        if ($location && $location !== '—') {
            $lines[] = "📍 <b>Manzil:</b> {$location}";
        }

        // Narx
        $price = trim($listing['price'] ?? '—');
        if ($price && $price !== '—') {
            $lines[] = "💰 <b>Narx:</b> {$price}";
        }

        // Telefon
        $phone = trim($details['phone'] ?? '—');
        if ($phone && $phone !== '—') {
            $lines[] = "📞 <b>Tel:</b> {$phone}";
        }

        // Link
        $url = $listing['url'] ?? '';
        if ($url) {
            $lines[] = "\n🔗 <a href=\"{$url}\">OLX da ko'rish</a>";
        }

        return implode("\n", $lines);
    }
}
