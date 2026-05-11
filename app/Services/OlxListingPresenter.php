<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram;

class OlxListingPresenter
{
    private const PER_PAGE = 2;

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

        if ($page >= $totalPages) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text'    => "✅ Barcha {$total} ta e'lon ko'rsatildi.",
            ]);
            return;
        }

        $pageListings = array_slice($allListings, $page * self::PER_PAGE, self::PER_PAGE);
        $scraper      = new OlxScraperService();

        Log::info("OLX sendPage", [
            'chatId'  => $chatId,
            'page'    => $page,
            'total'   => $total,
            'showing' => count($pageListings),
        ]);

        foreach ($pageListings as $i => $listing) {
            $details = $scraper->getAdDetails($listing['url']);

            Log::info("OLX e'lon tafsilotlari", [
                'title'  => $listing['title'],
                'photos' => count($details['photos'] ?? []),
                'm2'     => $details['m2'] ?? '—',
                'phone'  => $details['phone'] ?? '—',
            ]);

            // sqm filter YO'Q — OLX URL parametrlari allaqachon sqm ni filtrlamoqda
            // Client-side sqm filter juda ko'p e'lonni o'chirib tashlaydi

            self::sendSingleListing($chatId, $listing, $details);

            // Har 2 e'lon orasida pauza (Telegram rate limit)
            if ($i < count($pageListings) - 1) {
                usleep(600_000); // 0.6s
            }
        }

        // ─── Navigatsiya ─────────────────────────────────────────────────
        $shown    = min(($page + 1) * self::PER_PAGE, $total);
        $nextPage = $page + 1;
        $hasMore  = $nextPage < $totalPages;

        if ($hasMore) {
            $keyboard = [
                'inline_keyboard' => [[
                    [
                        'text'          => "➡️ Keyingisi ({$shown}/{$total})",
                        'callback_data' => "olx_next_{$cacheKey}_{$nextPage}",
                    ],
                ]],
            ];

            Telegram::sendMessage([
                'chat_id'      => $chatId,
                'parse_mode'   => 'HTML',
                'reply_markup' => json_encode($keyboard),
                'text'         => "📋 <b>{$shown}</b> / {$total} ta e'lon ko'rsatildi.",
            ]);
        } else {
            $keyboard = [
                'inline_keyboard' => [[
                    [
                        'text' => "🔍 OLX da to'liq ro'yxat",
                        'url'  => $searchUrl,
                    ],
                ]],
            ];

            Telegram::sendMessage([
                'chat_id'      => $chatId,
                'parse_mode'   => 'HTML',
                'reply_markup' => json_encode($keyboard),
                'text'         => "✅ <b>Barcha {$total} ta e'lon ko'rsatildi.</b>",
            ]);
        }
    }

    /**
     * Bitta e'lonni yuboradi:
     * - Rasmlar (media group yoki bitta rasm) + caption
     * - Pastida "OLX da ko'rish" bosiladigan tugma
     *
     * Media group reply_markup qabul qilmagani uchun tugma
     * alohida kichik xabar sifatida yuboriladi.
     */
    private static function sendSingleListing(
        int   $chatId,
        array $listing,
        array $details
    ): void {
        $adUrl   = $listing['url'] ?? '';
        $caption = self::formatCaption($listing, $details);

        // Har doim bosiladigan tugma
        $keyboard = json_encode([
            'inline_keyboard' => [[
                [
                    'text' => "🔗 OLX da ko'rish",
                    'url'  => $adUrl,
                ],
            ]],
        ]);

        // Rasmlarni tozalash
        $photos = array_values(array_filter(
            $details['photos'] ?? [],
            fn($p) => is_string($p) && str_starts_with($p, 'http')
        ));
        $photos = array_slice($photos, 0, 10);

        $photosSent = false;

        if (count($photos) >= 2) {
            // ─── Media group ─────────────────────────────────────────────
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
                $photosSent = true;
            } catch (\Throwable $e) {
                Log::warning("sendMediaGroup xatosi", [
                    'error' => $e->getMessage(),
                    'url'   => $adUrl,
                ]);
            }

            if (!$photosSent) {
                // Fallback: bitta rasm
                $photosSent = self::trySendPhoto($chatId, $photos[0], $caption);
            }

        } elseif (count($photos) === 1) {
            // ─── Bitta rasm + caption + tugma ────────────────────────────
            try {
                Telegram::sendPhoto([
                    'chat_id'      => $chatId,
                    'photo'        => $photos[0],
                    'caption'      => $caption,
                    'parse_mode'   => 'HTML',
                    'reply_markup' => $keyboard,
                ]);
                return; // tugma sendPhoto ichida, alohida kerak emas
            } catch (\Throwable $e) {
                Log::warning("sendPhoto xatosi", [
                    'error' => $e->getMessage(),
                    'url'   => $adUrl,
                ]);
                // matn fallback quyida
            }
        }

        if (!$photosSent || count($photos) === 0) {
            // ─── Rasm yo'q yoki xato — faqat matn + tugma ────────────────
            Telegram::sendMessage([
                'chat_id'                  => $chatId,
                'parse_mode'               => 'HTML',
                'reply_markup'             => $keyboard,
                'disable_web_page_preview' => true,
                'text'                     => $caption,
            ]);
            return;
        }

        // ─── Media group uchun tugma alohida xabar ────────────────────────
        usleep(300_000);
        Telegram::sendMessage([
            'chat_id'                  => $chatId,
            'parse_mode'               => 'HTML',
            'reply_markup'             => $keyboard,
            'disable_web_page_preview' => true,
            'text'                     => "👆 Yuqoridagi e'lon:",
        ]);
    }

    private static function trySendPhoto(int $chatId, string $photoUrl, string $caption): bool
    {
        try {
            Telegram::sendPhoto([
                'chat_id'    => $chatId,
                'photo'      => $photoUrl,
                'caption'    => $caption,
                'parse_mode' => 'HTML',
            ]);
            return true;
        } catch (\Throwable $e) {
            Log::warning("trySendPhoto xatosi", ['error' => $e->getMessage()]);
            return false;
        }
    }

    private static function formatCaption(array $listing, array $details): string
    {
        $lines = [];

        $title = trim($listing['title'] ?? '');
        if ($title) {
            $lines[] = "🏢 <b>" . htmlspecialchars($title, ENT_QUOTES) . "</b>";
        }

        $m2 = trim($details['m2'] ?? '—');
        if ($m2 && $m2 !== '—') {
            $lines[] = "📐 <b>Maydon:</b> {$m2}";
        }

        $location = trim($listing['location'] ?? '—');
        if ($location && $location !== '—') {
            $lines[] = "📍 <b>Manzil:</b> " . htmlspecialchars($location, ENT_QUOTES);
        }

        $price = trim($listing['price'] ?? '—');
        if ($price && $price !== '—') {
            $lines[] = "💰 <b>Narx:</b> {$price}";
        }

        $phone = trim($details['phone'] ?? '—');
        if ($phone && $phone !== '—') {
            $lines[] = "📞 <b>Tel:</b> {$phone}";
        }

        $text = implode("\n", $lines);

        // Telegram caption limit: 1024 belgi
        if (mb_strlen($text) > 1020) {
            $text = mb_substr($text, 0, 1020) . '...';
        }

        return $text ?: "📋 E'lon ma'lumotlari mavjud emas";
    }
}