<?php

namespace App\Telegram\Handlers;

use App\Models\Region;
use Telegram\Bot\Laravel\Facades\Telegram;

class IjaraHandler
{
    /**
     * Mulk turini tanlash — birinchi qadam (ijara yoki sotuvlar uchun)
     */
    public static function showPropertyTypes(int $chatId, int $messageId, string $mode): void
    {
        $title = $mode === 'sotuvlar' ? "🏷 <b>Sotuvlar</b>" : "🔑 <b>Ijara</b>";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🏠 Uy / Kvartira', 'callback_data' => "property_{$mode}_uy"],
                    ['text' => '🏪 Dokon',          'callback_data' => "property_{$mode}_dokon"],
                ],
                [
                    ['text' => '🏢 Ofis / Bino',   'callback_data' => "property_{$mode}_ofis"],
                ],
                [
                    ['text' => '⬅️ Orqaga',         'callback_data' => 'back_main'],
                ],
            ],
        ];

        Telegram::editMessageText([
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'parse_mode'   => 'HTML',
            'reply_markup' => json_encode($keyboard),
            'text'         => "{$title}\n\n🏷 <b>Mulk turini tanlang:</b>\nQaysi turdagi ko'chmas mulkni qidiryapsiz?",
        ]);
    }

    /**
     * Viloyatlar ro'yxatini DB dan o'qib inline keyboard sifatida yuboradi.
     */
    public static function showRegions(int $chatId, int $messageId): void
    {
        $regions = Region::orderBy('name')->get();

        if ($regions->isEmpty()) {
            Telegram::editMessageText([
                'chat_id'    => $chatId,
                'message_id' => $messageId,
                'text'       => '⚠️ Viloyatlar hali DB ga yuklanmagan. Admin bilan bog\'laning.',
            ]);
            return;
        }

        // Inline keyboard: har qatorda 2 ta viloyat
        $rows    = [];
        $buttons = [];

        foreach ($regions as $region) {
            $buttons[] = [
                'text'          => '📍 ' . $region->name,
                'callback_data' => 'region_' . $region->id,
            ];

            if (count($buttons) === 2) {
                $rows[]  = $buttons;
                $buttons = [];
            }
        }

        if (!empty($buttons)) {
            $rows[] = $buttons;
        }

        // Orqaga tugmasi — property type ekraniga qaytish
        $rows[] = [
            ['text' => '⬅️ Orqaga', 'callback_data' => 'back_property'],
        ];

        $keyboard = ['inline_keyboard' => $rows];

        Telegram::editMessageText([
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'text'         => "🗺 <b>Kerakli viloyatni tanlang:</b>",
            'parse_mode'   => 'HTML',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Tanlangan viloyatga tegishli tumanlarni DB dan o'qib ko'rsatadi.
     */
    public static function showDistricts(int $chatId, int $messageId, int $regionId): void
    {
        $region = Region::with('districts')->find($regionId);

        if (!$region) {
            Telegram::editMessageText([
                'chat_id'    => $chatId,
                'message_id' => $messageId,
                'text'       => '❌ Viloyat topilmadi.',
            ]);
            return;
        }

        $districts = $region->districts;

        // Inline keyboard: har qatorda 2 ta tuman
        $rows    = [];
        $buttons = [];

        foreach ($districts as $district) {
            $buttons[] = [
                'text'          => '🏘 ' . $district->name,
                'callback_data' => 'district_' . $district->id,
            ];

            if (count($buttons) === 2) {
                $rows[]  = $buttons;
                $buttons = [];
            }
        }

        if (!empty($buttons)) {
            $rows[] = $buttons;
        }

        // Orqaga tugmasi — viloyatlar ro'yxatiga qaytish
        $rows[] = [
            ['text' => '⬅️ Orqaga', 'callback_data' => 'back_region'],
        ];

        $keyboard = ['inline_keyboard' => $rows];

        Telegram::editMessageText([
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'text'         => "📍 <b>{$region->name}</b>\n\n🏘 Kerakli tumanni tanlang:",
            'parse_mode'   => 'HTML',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
