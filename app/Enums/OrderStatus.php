<?php

namespace App\Enums;

enum OrderStatus: string
{
    case NEW = 'new';
    case APPROVED = 'approved';
    case PROCESSING = 'processing';
    case SHIPPED = 'shipped';
    case DELIVERED = 'delivered';
    case CANCELLED = 'cancelled';
    case PRODUCT_RETURN = 'product_return';
    case RETURN_PAYMENT = 'return_payment';

//    case ASSEMBLED = 'assembled';
//    case TSD_CONTROL = 'tsd_control';
//    case CHECK_ISSUED = 'check_issued';
//    case CLAIM = 'claim';

    public function label(): string
    {
        return match ($this) {
            self::NEW => 'Новый',
            self::APPROVED => 'Согласован',
            self::PROCESSING => 'В работе',
            self::SHIPPED => 'Отгружен',
            self::DELIVERED => 'Доставлен',
            self::CANCELLED => 'Отменен',
            self::PRODUCT_RETURN => 'Возврат товара',
            self::RETURN_PAYMENT => 'Возврат оплаты',


//            self::ASSEMBLED => 'Собран',
//            self::TSD_CONTROL => 'На контроле в ТСД',
//            self::CHECK_ISSUED => 'Выдан чек (ферма)',
//            self::CLAIM => 'Рекламация',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NEW => '#fb7878',
            self::APPROVED => '#60a5fa',
            self::PROCESSING => '#efd49c',
            self::SHIPPED => '#7391ec',
            self::DELIVERED => '#6fbaba',
            self::CANCELLED => '#f88686',
            self::PRODUCT_RETURN => '#f59e0b', // оранжевый для возврата
            self::RETURN_PAYMENT => '#9333ea', // фиолетовый для возврата оплаты


//            self::ASSEMBLED => '#558f5a',
//            self::TSD_CONTROL => '#7391ec',
//            self::CHECK_ISSUED => '#10B981',
//            self::CLAIM => '#ff7d54',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    // Для отдачи фронту
    public static function toArray(): array
    {
        $result = [];
        foreach (self::cases() as $status) {
            $result[$status->value] = [
                'value' => $status->value,
                'label' => $status->label(),
                'color' => $status->color(),
            ];
        }
        return $result;
    }
}
