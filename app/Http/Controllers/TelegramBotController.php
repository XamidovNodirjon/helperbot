<?php

namespace App\Http\Controllers;

use App\Jobs\PerformOlxSearch;
use App\Services\OlxListingPresenter;
use App\Services\OlxScraperService;
use App\Telegram\Handlers\IjaraFlowHandler;
use App\Telegram\Handlers\IjaraHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Telegram\Bot\Laravel\Facades\Telegram;
use Telegram\Bot\Objects\Update;

class TelegramBotController extends Controller
{
    public function handle(Request $request)
    {
        $update = Telegram::getWebhookUpdate();

        // Handle callback queries
        if ($update->has('callback_query')) {
            $this->handleCallback($update->getCallbackQuery());
            return response('OK');
        }

        // Handle text messages
        if ($update->has('message') && $update->getMessage()->has('text')) {
            $message = $update->getMessage();
            $chatId = $message->getChat()->getId();
            $text = $message->getText();

            // Check if user is in a flow
            if (IjaraFlowHandler::handleTextInput($chatId, $text)) {
                return response('OK');
            }
        }

        // Handle commands
        Telegram::commandsHandler(true);

        return response('OK');
    }

    private function handleCallback($callbackQuery)
    {
        $chatId = $callbackQuery->getMessage()->getChat()->getId();
        $messageId = $callbackQuery->getMessage()->getMessageId();
        $data = $callbackQuery->getData();

        // Answer the callback
        Telegram::answerCallbackQuery([
            'callback_query_id' => $callbackQuery->getId(),
        ]);

        // Parse callback data
        if ($data === 'ijara_confirm') {
            // Send processing message immediately
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '🔍 Qidiruv boshlandi... Iltimos, kuting.',
            ]);
            // Dispatch job to perform search in background
            $state = \App\Models\UserState::forUser($chatId);
            $filters = $state->data;
            PerformOlxSearch::dispatch($chatId, $filters);
        } elseif ($data === 'ijara_restart') {
            // Restart the flow - go back to start
            $this->sendStartMessage($chatId);
        } elseif ($data === 'ijara_cancel') {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ Qidiruv bekor qilindi.',
            ]);
        } elseif (str_starts_with($data, 'sotuvlar')) {
            IjaraHandler::showPropertyTypes($chatId, $messageId, 'sotuvlar');
        } elseif (str_starts_with($data, 'ijara')) {
            IjaraHandler::showPropertyTypes($chatId, $messageId, 'ijara');
        } elseif (str_starts_with($data, 'property_')) {
            // property_{mode}_{type}
            [$_, $mode, $type] = explode('_', $data);
            $this->handlePropertySelected($chatId, $messageId, $mode, $type);
        } elseif (str_starts_with($data, 'region_')) {
            $regionId = (int) str_replace('region_', '', $data);
            IjaraHandler::showDistricts($chatId, $messageId, $regionId);
        } elseif (str_starts_with($data, 'district_')) {
            $districtId = (int) str_replace('district_', '', $data);
            IjaraFlowHandler::startFlow($chatId, $districtId);
        } elseif (str_starts_with($data, 'currency_')) {
            $currency = str_replace('currency_', '', $data);
            IjaraFlowHandler::handleCurrencySelected($chatId, $currency);
        } elseif (str_starts_with($data, 'back_')) {
            $this->handleBack($chatId, $messageId, $data);
        } elseif (str_starts_with($data, 'olx_next_')) {
            // olx_next_{cacheKey}_{page}
            // cacheKey = "olx_results_{chatId}" — ichida _ bor, shuning uchun oxirgi _ ga qarab ajratamiz
            $withoutPrefix = substr($data, strlen('olx_next_')); // "olx_results_{chatId}_{page}"
            $lastUnderscore = strrpos($withoutPrefix, '_');
            $cacheKey = substr($withoutPrefix, 0, $lastUnderscore);  // "olx_results_{chatId}"
            $page     = (int) substr($withoutPrefix, $lastUnderscore + 1);

            \Log::info('OLX next page callback', [
                'chatId'   => $chatId,
                'cacheKey' => $cacheKey,
                'page'     => $page,
            ]);

            OlxListingPresenter::sendPage($chatId, $cacheKey, $page);
        } elseif ($data === 'olx_openurl') {
            // OLX URL ni olish va yuborish
            $cacheKey = 'olx_results_' . $chatId;
            $cached   = Cache::get($cacheKey);
            $url      = $cached['searchUrl'] ?? null;
            if ($url) {
                Telegram::sendMessage([
                    'chat_id'                  => $chatId,
                    'parse_mode'               => 'HTML',
                    'disable_web_page_preview' => false,
                    'text'                     => "🔍 <a href=\"{$url}\">OLX da to'liq ko'rish</a>",
                ]);
            }
        }
    }

    private function handlePropertySelected(int $chatId, int $messageId, string $mode, string $type)
    {
        // Save mode and property_type to user state
        $state = \App\Models\UserState::forUser($chatId);
        $state->nextStep(\App\Models\UserState::STEP_ASK_REGION, ['mode' => $mode, 'property_type' => $type]);

        // Show regions
        IjaraHandler::showRegions($chatId, $messageId);
    }

    private function handleBack(int $chatId, int $messageId, string $data)
    {
        if ($data === 'back_main') {
            $this->sendStartMessage($chatId, $messageId);
        } elseif ($data === 'back_property') {
            $state = \App\Models\UserState::forUser($chatId);
            $mode = $state->data['mode'] ?? 'ijara';
            IjaraHandler::showPropertyTypes($chatId, $messageId, $mode);
        } elseif ($data === 'back_region') {
            IjaraHandler::showRegions($chatId, $messageId);
        }
    }

    private function sendStartMessage(int $chatId, int $messageId = null)
    {
        $text = "Assalomu alaykum!\n\nBotga xush kelibsiz!\n\nKerakli bo'limni tanlang:";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🏷️ Sotuvlar', 'callback_data' => 'sotuvlar'],
                    ['text' => '🔑 Ijara',    'callback_data' => 'ijara']
                ]
            ]
        ];

        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
        ];

        if ($messageId) {
            $params['message_id'] = $messageId;
            Telegram::editMessageText($params);
        } else {
            Telegram::sendMessage($params);
        }
    }

    private function performSearch(int $chatId)
    {
        $state = \App\Models\UserState::forUser($chatId);
        $filters = $state->data;

        if (empty($filters)) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ Filtrlar topilmadi. Iltimos qaytadan boshlang.',
            ]);
            return;
        }

        // Perform OLX search
        $scraper = new OlxScraperService();
        $result = $scraper->search($filters);
        
        \Log::info('OLX Search Filters:', $filters);
        \Log::info('OLX Search Result:', $result);

        if (empty($result['listings'])) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ Hech narsa topilmadi. Filtrlarni o\'zgartirib qaytadan urinib ko\'ring.',
            ]);
            return;
        }

        // Send results
        $this->sendSearchResults($chatId, $result);
    }

    private function sendSearchResults(int $chatId, array $result)
    {
        $listings = $result['listings'];
        $searchUrl = $result['searchUrl'];
        $searchNote = $result['searchNote'];

        // Send search note if any
        if (!empty($searchNote)) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => $searchNote,
                'parse_mode' => 'HTML',
            ]);
        }

        // Send listings
        $scraper = new OlxScraperService();
        foreach (array_slice($listings, 0, 5) as $listing) { // Limit to 5
            $details = $scraper->getAdDetails($listing['url']);
            $photos = $details['photos'] ?? [];

            if (!empty($photos)) {
                // Send media group
                $media = [];
                foreach (array_slice($photos, 0, 10) as $photoUrl) { // Limit photos
                    $media[] = [
                        'type' => 'photo',
                        'media' => $photoUrl,
                    ];
                }
                $media[0]['caption'] = $this->formatListingText($listing, $details);
                $media[0]['parse_mode'] = 'HTML';

                Telegram::sendMediaGroup([
                    'chat_id' => $chatId,
                    'media' => json_encode($media),
                ]);
            } else {
                // Send text
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => $this->formatListingText($listing, $details),
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ]);
            }
        }

        // Send search URL
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => "🔍 <a href=\"{$searchUrl}\">To'liq natijalarni OLX da ko'rish</a>",
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);
    }

    private function formatListingText(array $listing, array $details): string
    {
        $text = "🏠 <b>{$listing['title']}</b>\n";
        if (!empty($listing['price']) && $listing['price'] !== '—') {
            $text .= "💰 {$listing['price']}\n";
        }
        if (!empty($listing['location']) && $listing['location'] !== '—') {
            $text .= "📍 {$listing['location']}\n";
        }
        if (!empty($details['m2']) && $details['m2'] !== '—') {
            $text .= "📐 {$details['m2']}\n";
        }
        if (!empty($details['phone']) && $details['phone'] !== '—') {
            $text .= "📞 {$details['phone']}\n";
        }
        $text .= "🔗 <a href=\"{$listing['url']}\">OLX da ko'rish</a>";
        return $text;
    }
}
