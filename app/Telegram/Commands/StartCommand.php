<?php

namespace App\Telegram\Commands;

use Telegram\Bot\Commands\Command;

class StartCommand extends Command
{
    /**
     * @var string Command Name
     */
    protected string $name = 'start';

    /**
     * @var string Command Description
     */
    protected string $description = 'Botni ishga tushirish';

    /**
     * {@inheritdoc}
     */
    public function handle()
    {
        $update = $this->getUpdate();
        $chat = $update->getMessage()->getChat();
        $firstName = $chat->getFirstName();

        $text = "Assalomu alaykum <b>$firstName</b>!\n\nBotga xush kelibsiz!\n\nKerakli bo'limni tanlang:";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🏷️ Sotuvlar', 'callback_data' => 'sotuvlar'],
                    ['text' => '🔑 Ijara',    'callback_data' => 'ijara']
                ]
            ]
        ];

        $this->replyWithMessage([
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
        ]);
    }
}
