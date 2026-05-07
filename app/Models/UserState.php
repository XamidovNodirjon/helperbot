<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserState extends Model
{
    protected $fillable = ['chat_id', 'step', 'data'];

    protected $casts = [
        'chat_id' => 'integer',
        'data'    => 'array',
    ];

    // ─── Konstantalar: qadam nomlari ───────────────────────────────────────────

    const STEP_IDLE                = 'idle';
    const STEP_ASK_PROPERTY_TYPE   = 'ask_property_type';   // uy / dokon / ofis
    const STEP_ASK_REGION          = 'ask_region';           // viloyat tanlash
    const STEP_ASK_SQM_MIN         = 'ask_sqm_min';
    const STEP_ASK_SQM_MAX         = 'ask_sqm_max';
    const STEP_ASK_CURRENCY        = 'ask_currency';     // valyuta: UZS | USD
    const STEP_ASK_PRICE_MIN       = 'ask_price_min';
    const STEP_ASK_PRICE_MAX       = 'ask_price_max';
    const STEP_DONE                = 'done';

    // ─── Helper metodlar ───────────────────────────────────────────────────────

    /**
     * Foydalanuvchi holatini olish yoki yangi yaratish.
     */
    public static function forUser(int $chatId): self
    {
        return self::firstOrCreate(
            ['chat_id' => $chatId],
            ['step' => self::STEP_IDLE, 'data' => []]
        );
    }

    /**
     * Keyingi qadamga o'tish va ma'lumot qo'shish.
     */
    public function nextStep(string $step, array $newData = []): void
    {
        $this->step = $step;
        $this->data = array_merge($this->data ?? [], $newData);
        $this->save();
    }

    /**
     * Holatni boshlang'ich holatga qaytarish.
     */
    public function reset(): void
    {
        $this->step = self::STEP_IDLE;
        $this->data = [];
        $this->save();
    }
}
