<?php

namespace App\Enums;

enum CommunicationChannel: string
{
    case WEB_CHAT = 'web_chat';
    case TELEGRAM = 'telegram';
    case MAX = 'max';
    case VK = 'vk';
    case EMAIL = 'email';
    case WHATSAPP = 'whatsapp';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
