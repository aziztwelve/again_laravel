<?php

namespace App\Support;

/**
 * Общие части всех писем: логотип в шапке, ссылки в подвале, служебные метки.
 * Картинки лежат в публичной папке витрины (again_front/public/img/email).
 */
class EmailBranding
{
    /** Подставляется в подвале; настоящую ссылку на отписку ставит PrepareOutgoingEmail при отправке. */
    public const UNSUBSCRIBE_PLACEHOLDER = '%%UNSUBSCRIBE_URL%%';

    /** Метка рекламного письма: адресам, отписавшимся от рекламы, такое письмо не отправляется. */
    public const MARKETING_MARKER = '<!--again:marketing-->';

    public const TELEGRAM_URL = 'https://t.me/again8help_bot';

    public const MAX_URL = 'https://max.ru/id4707052811_1_bot';

    public const WHATSAPP_URL = 'https://wa.me/79991178888';

    public static function asset(string $file): string
    {
        return PublicUrl::to('/img/email/'.$file);
    }

    /** @return array<int, array{label:string, url:string, icon:string}> */
    public static function socials(): array
    {
        return [
            ['label' => 'ВКонтакте', 'url' => 'https://vk.com/again8ru', 'icon' => 'vk.png'],
            ['label' => 'MAX', 'url' => self::MAX_URL, 'icon' => 'max.png'],
            ['label' => 'Telegram', 'url' => self::TELEGRAM_URL, 'icon' => 'telegram.png'],
            ['label' => 'Instagram', 'url' => 'https://www.instagram.com/again8.ru', 'icon' => 'instagram.png'],
        ];
    }
}
