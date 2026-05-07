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

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        \Log::info('Job OLX Search started', ['chatId' => $this->chatId, 'filters' => $this->filters]);

        $scraper = new OlxScraperService();
        $result  = $scraper->search($this->filters);

        \Log::info('Job OLX Search result', [
            'count'     => count($result['listings'] ?? []),
            'searchUrl' => $result['searchUrl'] ?? '',
        ]);

        if (empty($result['listings'])) {
            Telegram::sendMessage([
                'chat_id' => $this->chatId,
                'text'    => "❌ Hech qanday e'lon topilmadi.\n\nFiltrlaringizni tekshirib qaytadan urinib ko'ring.",
            ]);
            return;
        }

        // ─── Barcha e'lonlarni Cache ga saqlash (2 soat) ──────────────────────
        $cacheKey = 'olx_results_' . $this->chatId;
        Cache::put($cacheKey, [
            'listings'   => $result['listings'],
            'searchUrl'  => $result['searchUrl'],
            'searchNote' => $result['searchNote'],
        ], now()->addHours(2));

        \Log::info('OLX results cached', ['key' => $cacheKey, 'total' => count($result['listings'])]);

        // ─── Izoh xabar (agar mavjud bo'lsa) ─────────────────────────────────
        if (!empty($result['searchNote'])) {
            Telegram::sendMessage([
                'chat_id'    => $this->chatId,
                'text'       => $result['searchNote'],
                'parse_mode' => 'HTML',
            ]);
        }

        // ─── Birinchi 2 ta e'lonni yuborish ───────────────────────────────────
        OlxListingPresenter::sendPage($this->chatId, $cacheKey, 0);
    }
}
