<?php

namespace App\Http\Controllers;

use App\Jobs\PerformOlxSearch;
use App\Services\OlxListingPresenter;
use App\Telegram\Handlers\IjaraFlowHandler;
use App\Telegram\Handlers\IjaraHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram;

class TelegramBotController extends Controller
{
    public function handle(Request $request)
    {
        $update = Telegram::getWebhookUpdate();

        if ($update->has('callback_query')) {
            $this->handleCallback($update->getCallbackQuery());
            return response('OK');
        }

        if ($update->has('message') && $update->getMessage()->has('text')) {
            $message = $update->getMessage();
            $chatId  = $message->getChat()->getId();
            $text    = $message->getText();

            if (IjaraFlowHandler::handleTextInput($chatId, $text)) {
                return response('OK');
            }
        }

        Telegram::commandsHandler(true);
        return response('OK');
    }

    private function handleCallback($callbackQuery): void
    {
        $chatId    = $callbackQuery->getMessage()->getChat()->getId();
        $messageId = $callbackQuery->getMessage()->getMessageId();
        $data      = $callbackQuery->getData();

        Log::info('Callback received', [
            'chatId'    => $chatId,
            'messageId' => $messageId,
            'data'      => $data,
        ]);

        // Darhol javob — vaqt tugab qolmasin
        try {
            Telegram::answerCallbackQuery([
                'callback_query_id' => $callbackQuery->getId(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('answerCallbackQuery failed: ' . $e->getMessage());
        }

        // Callback logikasi
        try {
            $this->dispatchCallback($chatId, $messageId, $data);
        } catch (\Throwable $e) {
            Log::error('handleCallback xatosi', [
                'data'  => $data,
                'error' => $e->getMessage(),
                'line'  => $e->getLine(),
                'file'  => $e->getFile(),
            ]);

            try {
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text'    => '❌ Xatolik yuz berdi. Iltimos /start bosing.',
                ]);
            } catch (\Throwable $ignored) {}
        }
    }

    private function dispatchCallback(int $chatId, int $messageId, string $data): void
    {
        // ─── Aniq mosliklar ───────────────────────────────────────────────
        if ($data === 'ijara_confirm') {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text'    => '🔍 Qidiruv boshlandi... Iltimos, kuting.',
            ]);
            $state   = \App\Models\UserState::forUser($chatId);
            $filters = $state->data;
            PerformOlxSearch::dispatch($chatId, $filters);
            return;
        }

        if ($data === 'ijara_restart') {
            $this->sendStartMessage($chatId);
            return;
        }

        if ($data === 'ijara_cancel') {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text'    => '❌ Qidiruv bekor qilindi.',
            ]);
            return;
        }

        if ($data === 'sotuvlar') {
            IjaraHandler::showPropertyTypes($chatId, $messageId, 'sotuvlar');
            return;
        }

        if ($data === 'ijara') {
            IjaraHandler::showPropertyTypes($chatId, $messageId, 'ijara');
            return;
        }

        if ($data === 'olx_openurl') {
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
            return;
        }

        // ─── Prefiks bo'yicha ─────────────────────────────────────────────
        if (str_starts_with($data, 'property_')) {
            // property_{mode}_{type}
            $parts = explode('_', $data, 3); // max 3 qism
            $mode  = $parts[1] ?? 'ijara';
            $type  = $parts[2] ?? 'uy';
            $this->handlePropertySelected($chatId, $messageId, $mode, $type);
            return;
        }

        if (str_starts_with($data, 'region_')) {
            $regionId = (int) str_replace('region_', '', $data);
            IjaraHandler::showDistricts($chatId, $messageId, $regionId);
            return;
        }

        if (str_starts_with($data, 'district_')) {
            $districtId = (int) str_replace('district_', '', $data);
            IjaraFlowHandler::startFlow($chatId, $districtId);
            return;
        }

        if (str_starts_with($data, 'currency_')) {
            $currency = str_replace('currency_', '', $data);
            IjaraFlowHandler::handleCurrencySelected($chatId, $currency);
            return;
        }

        if (str_starts_with($data, 'back_')) {
            $this->handleBack($chatId, $messageId, $data);
            return;
        }

        if (str_starts_with($data, 'olx_next_')) {
            // olx_next_olx_results_{chatId}_{page}
            $withoutPrefix  = substr($data, strlen('olx_next_'));
            $lastUnderscore = strrpos($withoutPrefix, '_');
            $cacheKey       = substr($withoutPrefix, 0, $lastUnderscore);
            $page           = (int) substr($withoutPrefix, $lastUnderscore + 1);

            Log::info('OLX next page', [
                'chatId'   => $chatId,
                'cacheKey' => $cacheKey,
                'page'     => $page,
            ]);

            OlxListingPresenter::sendPage($chatId, $cacheKey, $page);
            return;
        }

        Log::warning("Noma'lum callback data", ['data' => $data]);
    }

    private function handlePropertySelected(int $chatId, int $messageId, string $mode, string $type): void
    {
        $state = \App\Models\UserState::forUser($chatId);
        $state->nextStep(\App\Models\UserState::STEP_ASK_REGION, [
            'mode'          => $mode,
            'property_type' => $type,
        ]);

        IjaraHandler::showRegions($chatId, $messageId);
    }

    private function handleBack(int $chatId, int $messageId, string $data): void
    {
        if ($data === 'back_main') {
            $this->sendStartMessage($chatId, $messageId);
        } elseif ($data === 'back_property') {
            $state = \App\Models\UserState::forUser($chatId);
            $mode  = $state->data['mode'] ?? 'ijara';
            IjaraHandler::showPropertyTypes($chatId, $messageId, $mode);
        } elseif ($data === 'back_region') {
            IjaraHandler::showRegions($chatId, $messageId);
        }
    }

    private function sendStartMessage(int $chatId, int $messageId = null): void
    {
        $text = "Assalomu alaykum!\n\nBotga xush kelibsiz!\n\nKerakli bo'limni tanlang:";

        $keyboard = [
            'inline_keyboard' => [[
                ['text' => '🏷️ Sotuvlar', 'callback_data' => 'sotuvlar'],
                ['text' => '🔑 Ijara',    'callback_data' => 'ijara'],
            ]],
        ];

        $params = [
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'HTML',
            'reply_markup' => json_encode($keyboard),
        ];

        if ($messageId) {
            $params['message_id'] = $messageId;
            Telegram::editMessageText($params);
        } else {
            Telegram::sendMessage($params);
        }
    }
}