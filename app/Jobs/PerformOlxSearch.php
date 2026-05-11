<?php

namespace App\Jobs;

use App\Services\OlxListingPresenter;
use App\Services\OlxScraperService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Telegram\Bot\Laravel\Facades\Telegram;

class PerformOlxSearch implements ShouldQueue
{
    use Queueable;

    protected $chatId;
    protected $filters;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct($chatId, $filters)
    {
        $this->chatId  = $chatId;
        $this->filters = $filters;
    }

    Cache::put($cacheKey, [
        'listings'   => $result['listings'],
        'searchUrl'  => $result['searchUrl'],
        'searchNote' => $result['searchNote'],
        'filters'    => $this->filters, // ← BU QATORNI QO'SHING
    ], now()->addHours(2));

    /**
     * Execute the job.
     */
    public function handle(): void
{
    \Log::info('Job OLX Search started', [
        'chatId'  => $this->chatId,
        'filters' => $this->filters,
    ]);

    try {
        $scraper = new OlxScraperService();
        $result  = $scraper->search($this->filters);

        if (empty($result['listings'])) {
            Telegram::sendMessage([
                'chat_id' => $this->chatId,
                'text'    => "❌ Hech qanday e'lon topilmadi.\n\nFiltrlaringizni tekshirib qaytadan urinib ko'ring.",
            ]);
            return;
        }

        $cacheKey = 'olx_results_' . $this->chatId;
        Cache::put($cacheKey, [
            'listings'   => $result['listings'],
            'searchUrl'  => $result['searchUrl'],
            'searchNote' => $result['searchNote'],
            'filters'    => $this->filters,
        ], now()->addHours(2));

        if (!empty($result['searchNote'])) {
            Telegram::sendMessage([
                'chat_id'    => $this->chatId,
                'text'       => $result['searchNote'],
                'parse_mode' => 'HTML',
            ]);
        }

        OlxListingPresenter::sendPage($this->chatId, $cacheKey, 0);

    } catch (\Throwable $e) {
        \Log::error('PerformOlxSearch job xatosi: ' . $e->getMessage());

        try {
            Telegram::sendMessage([
                'chat_id' => $this->chatId,
                'text'    => "❌ Qidirishda xatolik yuz berdi. Iltimos qaytadan urinib ko'ring.",
            ]);
        } catch (\Throwable $telegramError) {
            \Log::error('Telegram xabar yuborishda xatolik: ' . $telegramError->getMessage());
        }
    }
}
}
