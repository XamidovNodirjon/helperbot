<?php

namespace App\Telegram\Handlers;

use App\Models\District;
use App\Models\Region;
use App\Models\UserState;
use Telegram\Bot\Laravel\Facades\Telegram;

class IjaraFlowHandler
{
    // ─── USD → UZS kurs (taxminiy) ─────────────────────────────────────────
    // Bu kursni kerak bo'lganda yangilash mumkin
    public static function getUsdRate(): int
    {
        return (int) config('app.usd_to_uzs', 12_850);
    }
    // ─── 1. Tuman tanlangandan keyin birinchi qadam ────────────────────────────
    // Property type avval (ijara/sotuvlar bosilganda) saqlanadi.

    public static function startFlow(int $chatId, int $districtId): void
    {
        $district = District::with('region')->find($districtId);

        if (!$district) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text'    => '❌ Tuman topilmadi. Iltimos qaytadan boshlang.',
            ]);
            return;
        }

        // State dan avval saqlangan mode va property_type ni olamiz
        $state        = UserState::forUser($chatId);
        $propertyType = $state->data['property_type'] ?? 'uy';
        $mode         = $state->data['mode'] ?? 'ijara';

        $propLabel = match($propertyType) {
            'dokon' => '🏪 Dokon',
            'ofis'  => '🏢 Ofis / Bino',
            default => '🏠 Uy / Kvartira',
        };

        // district va region ni ham statega qo'shamiz
        $state->nextStep(UserState::STEP_ASK_SQM_MIN, [
            'district_id'   => $district->olx_id ?? $district->id,
            'district_name' => $district->name,
            'region_id'     => $district->region->id,
            'region_name'   => $district->region->name,
        ]);

        Telegram::sendMessage([
            'chat_id'    => $chatId,
            'parse_mode' => 'HTML',
            'text'       => self::progressBar(1, 5) .
                "\n\n{$propLabel}\n" .
                "📍 <b>{$district->region->name}</b> — <i>{$district->name}</i>" .
                "\n\n📐 <b>Minimal maydon</b>\n" .
                "Ijaralanayotgan joyning <b>eng kam</b> kvadrat metrini kiriting.\n\n" .
                "💡 <i>Misol: 40</i>",
        ]);
    }

    // ─── 2. Foydalanuvchi xabar yozganda handle qilish ────────────────────────

    public static function handleTextInput(int $chatId, string $text): bool
    {
        $state = UserState::forUser($chatId);

        return match ($state->step) {
            UserState::STEP_ASK_SQM_MIN   => self::handleSqmMin($state, $chatId, $text),
            UserState::STEP_ASK_SQM_MAX   => self::handleSqmMax($state, $chatId, $text),
            UserState::STEP_ASK_PRICE_MIN => self::handlePriceMin($state, $chatId, $text),
            UserState::STEP_ASK_PRICE_MAX => self::handlePriceMax($state, $chatId, $text),
            default                       => false,
        };
    }

    // ─── Qadam 1: Minimal kvadrat metr ────────────────────────────────────────

    private static function handleSqmMin(UserState $state, int $chatId, string $text): bool
    {
        if (!self::isPositiveNumber($text)) {
            Telegram::sendMessage([
                'chat_id'    => $chatId,
                'parse_mode' => 'HTML',
                'text'       => "⚠️ Iltimos, faqat <b>musbat son</b> kiriting.\n💡 <i>Misol: 40</i>",
            ]);
            return true;
        }

        $state->nextStep(UserState::STEP_ASK_SQM_MAX, ['sqm_min' => (int) $text]);

        Telegram::sendMessage([
            'chat_id'    => $chatId,
            'parse_mode' => 'HTML',
            'text'       => self::progressBar(2, 5) .
                "\n\n📐 <b>Maksimal maydon</b>\n" .
                "Ijaralanayotgan joyning <b>eng ko'p</b> kvadrat metrini kiriting.\n\n" .
                "✅ Minimal: <b>{$text} m²</b>\n" .
                "💡 <i>Misol: 120</i>",
        ]);

        return true;
    }

    // ─── Qadam 2: Maksimal kvadrat metr ───────────────────────────────────────

    private static function handleSqmMax(UserState $state, int $chatId, string $text): bool
    {
        if (!self::isPositiveNumber($text)) {
            Telegram::sendMessage([
                'chat_id'    => $chatId,
                'parse_mode' => 'HTML',
                'text'       => "⚠️ Iltimos, faqat <b>musbat son</b> kiriting.\n💡 <i>Misol: 120</i>",
            ]);
            return true;
        }

        $sqmMin = $state->data['sqm_min'] ?? 0;

        if ((int) $text <= $sqmMin) {
            Telegram::sendMessage([
                'chat_id'    => $chatId,
                'parse_mode' => 'HTML',
                'text'       => "⚠️ Maksimal maydon <b>minimal maydandan katta</b> bo'lishi kerak.\n" .
                    "Minimal: <b>{$sqmMin} m²</b>\n💡 <i>Misol: " . ($sqmMin + 50) . "</i>",
            ]);
            return true;
        }

        $state->nextStep(UserState::STEP_ASK_CURRENCY, ['sqm_max' => (int) $text]);

        // Valyuta tanlash tugmalari
        self::showCurrencySelection($chatId, $sqmMin, (int) $text);

        return true;
    }

    // ─── Qadam 2.5: Valyuta tanlash ──────────────────────────────────────────

    public static function showCurrencySelection(int $chatId, int $sqmMin, int $sqmMax): void
    {
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => "🇺🇿 So'm (UZS)", 'callback_data' => 'currency_uzs'],
                    ['text' => '🇺🇸 Dollar ($)',   'callback_data' => 'currency_usd'],
                ],
            ],
        ];

        Telegram::sendMessage([
            'chat_id'      => $chatId,
            'parse_mode'   => 'HTML',
            'reply_markup' => json_encode($keyboard),
            'text'         => self::progressBar(3, 5) .
                "\n\n💱 <b>Valyutani tanlang</b>\n" .
                "Narxni qaysi valyutada kiritmoqchisiz?\n\n" .
                "✅ Maydon: <b>{$sqmMin}–{$sqmMax} m²</b>",
        ]);
    }

    // ─── Valyuta tanlangandan keyin ──────────────────────────────────────────

    public static function handleCurrencySelected(int $chatId, string $currency): void
    {
        $state = UserState::forUser($chatId);
        $state->nextStep(UserState::STEP_ASK_PRICE_MIN, ['currency' => $currency]);

        $currencyLabel = $currency === 'usd' ? '$' : "so'm";
        $example = $currency === 'usd' ? '200' : '1 500 000';

        $sqmMin = $state->data['sqm_min'] ?? 0;
        $sqmMax = $state->data['sqm_max'] ?? 0;

        Telegram::sendMessage([
            'chat_id'    => $chatId,
            'parse_mode' => 'HTML',
            'text'       => self::progressBar(4, 5) .
                "\n\n💰 <b>Minimal narx</b>\n" .
                "Oylik ijaraning <b>eng past</b> narxini kiriting (<b>{$currencyLabel}</b>).\n\n" .
                "✅ Maydon: <b>{$sqmMin}–{$sqmMax} m²</b>\n" .
                "💡 <i>Misol: {$example}</i>",
        ]);
    }

    // ─── Qadam 3: Minimal narx ────────────────────────────────────────────────

    private static function handlePriceMin(UserState $state, int $chatId, string $text): bool
    {
        $clean = preg_replace('/[\s,]+/', '', $text); // Bo'shliq va vergullarni olib tashlash

        if (!self::isPositiveNumber($clean)) {
            $currency = $state->data['currency'] ?? 'uzs';
            $example = $currency === 'usd' ? '200' : '1500000';
            Telegram::sendMessage([
                'chat_id'    => $chatId,
                'parse_mode' => 'HTML',
                'text'       => "⚠️ Iltimos, faqat <b>musbat son</b> kiriting.\n💡 <i>Misol: {$example}</i>",
            ]);
            return true;
        }

        $currency = $state->data['currency'] ?? 'uzs';
        $state->nextStep(UserState::STEP_ASK_PRICE_MAX, ['price_min' => (int) $clean]);

        $currencyLabel = $currency === 'usd' ? '$' : "so'm";
        $formatted = self::formatMoney((int) $clean);
        $example = $currency === 'usd' ? '500' : '3 000 000';

        Telegram::sendMessage([
            'chat_id'    => $chatId,
            'parse_mode' => 'HTML',
            'text'       => self::progressBar(5, 5) .
                "\n\n💰 <b>Maksimal narx</b>\n" .
                "Oylik ijaraning <b>eng yuqori</b> narxini kiriting (<b>{$currencyLabel}</b>).\n\n" .
                "✅ Minimal narx: <b>{$formatted} {$currencyLabel}</b>\n" .
                "💡 <i>Misol: {$example}</i>",
        ]);

        return true;
    }

    // ─── Qadam 4: Maksimal narx ───────────────────────────────────────────────

    private static function handlePriceMax(UserState $state, int $chatId, string $text): bool
    {
        $clean = preg_replace('/[\s,]+/', '', $text);

        if (!self::isPositiveNumber($clean)) {
            $currency = $state->data['currency'] ?? 'uzs';
            $example = $currency === 'usd' ? '500' : '3000000';
            Telegram::sendMessage([
                'chat_id'    => $chatId,
                'parse_mode' => 'HTML',
                'text'       => "⚠️ Iltimos, faqat <b>musbat son</b> kiriting.\n💡 <i>Misol: {$example}</i>",
            ]);
            return true;
        }

        $priceMin = $state->data['price_min'] ?? 0;
        $currency = $state->data['currency'] ?? 'uzs';

        if ((int) $clean <= $priceMin) {
            $currencyLabel = $currency === 'usd' ? '$' : "so'm";
            Telegram::sendMessage([
                'chat_id'    => $chatId,
                'parse_mode' => 'HTML',
                'text'       => "⚠️ Maksimal narx <b>minimal narxdan katta</b> bo'lishi kerak.\n" .
                    "Minimal: <b>" . self::formatMoney($priceMin) . " {$currencyLabel}</b>",
            ]);
            return true;
        }

        // Narxlarni UZS ga konvertatsiya qilish (OLX UZS da ishlaydi)
        $priceMinUzs = $priceMin;
        $priceMaxUzs = (int) $clean;

        if ($currency === 'usd') {
            $priceMinUzs = $priceMin * self::getUsdRate();
            $priceMaxUzs = (int) $clean * self::getUsdRate();
        }

        $state->nextStep(UserState::STEP_DONE, [
            'price_max'     => (int) $clean,
            'price_min_uzs' => $priceMinUzs,
            'price_max_uzs' => $priceMaxUzs,
        ]);

        // Yakuniy xulosa
        self::showSummary($chatId, $state->fresh()->data);

        return true;
    }

    // ─── Yakuniy xulosa ───────────────────────────────────────────────────────

    private static function showSummary(int $chatId, array $data): void
    {
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ Qidirishni boshlash', 'callback_data' => 'ijara_confirm'],
                    ['text' => '🔄 Qaytadan',            'callback_data' => 'ijara_restart'],
                ],
                [
                    ['text' => '❌ Bekor qilish', 'callback_data' => 'ijara_cancel'],
                ],
            ],
        ];

        $modeLabel = ($data['mode'] ?? 'ijara') === 'sotuvlar' ? '🏷 Sotuvlar' : '🔑 Ijara';

        $currency = $data['currency'] ?? 'uzs';
        $currencyLabel = $currency === 'usd' ? '$' : "so'm";

        $priceText = self::formatMoney($data['price_min']) .
            " – " . self::formatMoney($data['price_max']) . " {$currencyLabel}";

        // Agar USD bo'lsa, UZS ekvivalentini ham ko'rsatamiz
        $convertNote = '';
        if ($currency === 'usd') {
            $convertNote = "\n💱 <i>≈ " . self::formatMoney($data['price_min_uzs']) .
                " – " . self::formatMoney($data['price_max_uzs']) . " so'm</i>" .
                "\n<i>(1$ ≈ " . number_format(self::getUsdRate(), 0, '.', ' ') . " so'm)</i>";
        }

        Telegram::sendMessage([
            'chat_id'      => $chatId,
            'parse_mode'   => 'HTML',
            'reply_markup' => json_encode($keyboard),
            'text'         =>
                "🎉 <b>Filtr tayyor!</b>\n" .
                str_repeat("─", 30) . "\n\n" .
                "{$modeLabel}  |  " . self::propertyLabel($data['property_type'] ?? 'uy') . "\n\n" .
                "🗺 <b>Viloyat:</b>   {$data['region_name']}\n" .
                "📍 <b>Tuman:</b>     {$data['district_name']}\n\n" .
                "📐 <b>Maydon:</b>    {$data['sqm_min']} – {$data['sqm_max']} m²\n" .
                "💰 <b>Narx:</b>      {$priceText}{$convertNote}\n\n" .
                str_repeat("─", 30) . "\n" .
                "OLX.uz dan qidirsinmi?",
        ]);
    }

    // ─── Yordamchi metodlar ───────────────────────────────────────────────────

    private static function isPositiveNumber(string $value): bool
    {
        return is_numeric($value) && (int) $value > 0;
    }

    private static function formatMoney(int $amount): string
    {
        return number_format($amount, 0, '.', ' ');
    }

    private static function propertyLabel(string $type): string
    {
        return match ($type) {
            'dokon' => '🏪 Dokon',
            'ofis'  => '🏢 Ofis',
            default => '🏠 Uy / Kvartira',
        };
    }

    /**
     * Progress bar ko'rinishi: ▓▓▓░░  3/5 qadam
     */
    private static function progressBar(int $current, int $total): string
    {
        $filled = str_repeat('▓', $current);
        $empty  = str_repeat('░', $total - $current);
        return "📊 {$filled}{$empty}  <b>{$current}/{$total} qadam</b>";
    }

}
