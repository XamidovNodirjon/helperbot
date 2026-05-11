<?php

namespace App\Jobs;

use App\Services\OlxListingPresenter;
use App\Services\OlxScraperService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;        // ← BU YO'Q EDI
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram;

class PerformOlxSearch implements ShouldQueue
{
    use Queueable;

    public $timeout = 120;
    public $tries   = 1;

    protected int   $chatId;
    protected array $filters;

    public function __construct(int $chatId, array $filters)
    {
        $this->chatId  = $chatId;
        $this->filters = $filters;
    }

    public function handle(): void
    {
        Log::info('OLX Job boshlandi', [
            'chatId'  => $this->chatId,
            'filters' => $this->filters,
        ]);

        try {
            $scraper = new OlxScraperService();
            $result  = $scraper->search($this->filters);

            Log::info('OLX Job natija', [
                'count'     => count($result['listings'] ?? []),
                'searchUrl' => $result['searchUrl'] ?? '',
            ]);

            if (empty($result['listings'])) {
                Telegram::sendMessage([
                    'chat_id' => $this->chatId,
                    'text'    => "❌ Hech qanday e'lon topilmadi.\n\nFiltrlarni o'zgartirib qaytadan urinib ko'ring.",
                ]);
                return;
            }

            // Cache ga saqlash (2 soat)
            $cacheKey = 'olx_results_' . $this->chatId;
            Cache::put($cacheKey, [
                'listings'   => $result['listings'],
                'searchUrl'  => $result['searchUrl'],
                'searchNote' => $result['searchNote'],
                'filters'    => $this->filters,
            ], now()->addHours(2));

            Log::info('OLX cache saqlandi', [
                'key'   => $cacheKey,
                'total' => count($result['listings']),
            ]);

            // Izoh xabar
            if (!empty($result['searchNote'])) {
                Telegram::sendMessage([
                    'chat_id'    => $this->chatId,
                    'text'       => $result['searchNote'],
                    'parse_mode' => 'HTML',
                ]);
            }

            // Birinchi sahifani yuborish
            OlxListingPresenter::sendPage($this->chatId, $cacheKey, 0);

        } catch (\Throwable $e) {
            Log::error('OLX Job xatosi', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            try {
                Telegram::sendMessage([
                    'chat_id' => $this->chatId,
                    'text'    => "❌ Qidirishda xatolik yuz berdi. Iltimos /start bosib qaytadan urinib ko'ring.",
                ]);
            } catch (\Throwable $ignored) {}
        }
    }
}